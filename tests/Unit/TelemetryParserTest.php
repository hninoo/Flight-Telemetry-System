<?php

namespace Tests\Unit;

use App\Services\PacketParser;
use App\Support\Crc16Ccitt;
use App\Support\RangeValidator;
use InvalidArgumentException;
use Tests\TestCase;

class TelemetryParserTest extends TestCase
{
    public function test_crc16_ccitt_matches_standard_check_vector(): void
    {
        $this->assertSame(0x29B1, Crc16Ccitt::compute('123456789'));
    }

    public function test_crc16_ccitt_rejects_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Crc16Ccitt::compute('');
    }

    public function test_range_validator_accepts_boundary_values(): void
    {
        $this->assertTrue(RangeValidator::isValid(
            altitude: 9000.0,
            speed: 220.0,
            acceleration: -2.0,
            thrust: 0.0,
            temperature: -50.0,
        ));

        $this->assertTrue(RangeValidator::isValid(
            altitude: 12000.0,
            speed: 260.0,
            acceleration: 2.0,
            thrust: 200000.0,
            temperature: 50.0,
        ));
    }

    public function test_range_validator_rejects_out_of_range_and_non_finite_values(): void
    {
        $this->assertFalse(RangeValidator::isValid(8999.99, 240.0, 0.0, 100000.0, 20.0));
        $this->assertFalse(RangeValidator::isValid(10000.0, 240.0, NAN, 100000.0, 20.0));
    }

    public function test_parser_drains_valid_packet(): void
    {
        $parser = new PacketParser;

        $parser->feed($this->makePacket(
            flightNumber: 'ONX101',
            packetNumber: 7,
            altitude: 10001.234,
            speed: 245.678,
            acceleration: 0.456,
            thrust: 123456.789,
            temperature: 24.321,
        ));

        $results = $parser->drain();

        $this->assertCount(1, $results);
        $this->assertSame('valid', $results[0]['outcome']);
        $this->assertSame('ONX101', $results[0]['data']['flightNumber']);
        $this->assertSame(7, $results[0]['data']['packetNumber']);
        $this->assertSame(10001.23, $results[0]['data']['altitude']);
        $this->assertSame(245.68, $results[0]['data']['speed']);
        $this->assertSame(0.46, $results[0]['data']['acceleration']);
        $this->assertSame(123456.79, $results[0]['data']['thrust']);
        $this->assertSame(24.32, $results[0]['data']['temperature']);
        $this->assertSame(0, $parser->bufferLength());
    }

    public function test_parser_marks_bad_crc_packet_as_corrupted(): void
    {
        $parser = new PacketParser;
        $packet = $this->makePacket();
        $packet[0x21] = chr(ord($packet[0x21]) ^ 0xFF);

        $parser->feed($packet);
        $results = $parser->drain();

        $this->assertCount(1, $results);
        $this->assertSame('corrupted', $results[0]['outcome']);
        $this->assertNull($results[0]['data']);
    }

    public function test_parser_marks_malformed_size_packet_as_corrupted(): void
    {
        $parser = new PacketParser;
        $packet = $this->makePacket();
        $packet[0x0C] = chr(0x23);

        $parser->feed($packet);
        $results = $parser->drain();

        $this->assertCount(1, $results);
        $this->assertSame('corrupted', $results[0]['outcome']);
        $this->assertNull($results[0]['data']);
        $this->assertSame(0, $parser->bufferLength());
    }

    public function test_parser_marks_bad_end_marker_packet_as_corrupted_and_resynchronizes(): void
    {
        $parser = new PacketParser;
        $packet = $this->makePacket();
        $packet[0x23] = chr(0x00);

        $parser->feed($packet.$this->makePacket(flightNumber: 'ONX303'));
        $results = $parser->drain();

        $this->assertCount(2, $results);
        $this->assertSame('corrupted', $results[0]['outcome']);
        $this->assertNull($results[0]['data']);
        $this->assertSame('valid', $results[1]['outcome']);
        $this->assertSame('ONX303', $results[1]['data']['flightNumber']);
        $this->assertSame(0, $parser->bufferLength());
    }

    public function test_parser_buffers_partial_packet_until_rest_arrives(): void
    {
        $parser = new PacketParser;
        $packet = $this->makePacket(packetNumber: 42);

        $parser->feed(substr($packet, 0, 12));

        $this->assertSame([], $parser->drain());
        $this->assertSame(12, $parser->bufferLength());

        $parser->feed(substr($packet, 12));
        $results = $parser->drain();

        $this->assertCount(1, $results);
        $this->assertSame('valid', $results[0]['outcome']);
        $this->assertSame(42, $results[0]['data']['packetNumber']);
        $this->assertSame(0, $parser->bufferLength());
    }

    public function test_parser_resynchronizes_after_noise_before_packet(): void
    {
        $parser = new PacketParser;

        $parser->feed("noise\x00\x01".$this->makePacket(flightNumber: 'ONX202'));
        $results = $parser->drain();

        $this->assertCount(1, $results);
        $this->assertSame('valid', $results[0]['outcome']);
        $this->assertSame('ONX202', $results[0]['data']['flightNumber']);
    }

    private function makePacket(
        string $flightNumber = 'ONX101',
        int $packetNumber = 1,
        float $altitude = 10000.0,
        float $speed = 240.0,
        float $acceleration = 0.5,
        float $thrust = 100000.0,
        float $temperature = 20.0,
    ): string {
        $packet = str_repeat("\0", PacketParser::PACKET_SIZE);
        $packet[0x00] = chr(PacketParser::START_MARKER);

        $flightBytes = str_pad(substr($flightNumber, 0, 10), 10, "\0");
        for ($i = 0; $i < 10; $i++) {
            $packet[0x01 + $i] = $flightBytes[$i];
        }

        $packet[0x0B] = chr($packetNumber);
        $packet[0x0C] = chr(PacketParser::SIZE_FIELD);

        $this->writeFloat($packet, 0x0D, $altitude);
        $this->writeFloat($packet, 0x11, $speed);
        $this->writeFloat($packet, 0x15, $acceleration);
        $this->writeFloat($packet, 0x19, $thrust);
        $this->writeFloat($packet, 0x1D, $temperature);

        $crc = Crc16Ccitt::compute(substr($packet, 0, PacketParser::CRC_RANGE_LENGTH));
        $packet[0x21] = chr(($crc >> 8) & 0xFF);
        $packet[0x22] = chr($crc & 0xFF);
        $packet[0x23] = chr(PacketParser::END_MARKER);

        return $packet;
    }

    private function writeFloat(string &$packet, int $offset, float $value): void
    {
        $bytes = pack('G', $value);
        for ($i = 0; $i < 4; $i++) {
            $packet[$offset + $i] = $bytes[$i];
        }
    }
}
