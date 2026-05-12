<?php

namespace App\Services;

use App\Support\Crc16Ccitt;
use App\Support\RangeValidator;

final class PacketParser
{
    public const PACKET_SIZE = 36;

    public const START_MARKER = 0x82;

    public const END_MARKER = 0x80;

    public const SIZE_FIELD = 0x24;

    public const CRC_RANGE_LENGTH = 0x1F;

    private const MAX_BUFFER_LENGTH = self::PACKET_SIZE * 64;

    private const OFFSET_FLIGHT_NUMBER = 0x01;

    private const OFFSET_PACKET_NUMBER = 0x0B;

    private const OFFSET_SIZE_FIELD = 0x0C;

    private const OFFSET_ALTITUDE = 0x0D;

    private const OFFSET_SPEED = 0x11;

    private const OFFSET_ACCELERATION = 0x15;

    private const OFFSET_THRUST = 0x19;

    private const OFFSET_TEMPERATURE = 0x1D;

    private const OFFSET_CRC_HIGH = 0x21;

    private const OFFSET_CRC_LOW = 0x22;

    private string $buffer = '';

    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
        $this->compactBuffer();
    }

    public function drain(): array
    {
        $results = [];

        while (true) {
            $result = $this->extractNextResult();
            if ($result === null) {
                break;
            }
            $results[] = $result;
        }

        return $results;
    }

    private function extractNextResult(): ?array
    {
        while (true) {
            $startPos = strpos($this->buffer, "\x82");

            if ($startPos === false) {
                $this->buffer = '';

                return null;
            }

            if ($startPos > 0) {
                $this->buffer = substr($this->buffer, $startPos);
            }

            if (strlen($this->buffer) < self::PACKET_SIZE) {
                return null;
            }

            if (ord($this->buffer[self::PACKET_SIZE - 1]) !== self::END_MARKER) {
                $this->buffer = substr($this->buffer, 1);

                return ['outcome' => 'corrupted', 'data' => null];
            }

            $frame = substr($this->buffer, 0, self::PACKET_SIZE);
            $this->buffer = substr($this->buffer, self::PACKET_SIZE);

            return $this->interpretFrame($frame);
        }
    }

    private function interpretFrame(string $frame): array
    {
        if (ord($frame[self::OFFSET_SIZE_FIELD]) !== self::SIZE_FIELD) {
            return ['outcome' => 'corrupted', 'data' => null];
        }

        $crcRange = substr($frame, 0, self::CRC_RANGE_LENGTH);
        $computedCrc = Crc16Ccitt::compute($crcRange);
        $receivedCrc = (ord($frame[self::OFFSET_CRC_HIGH]) << 8) | ord($frame[self::OFFSET_CRC_LOW]);

        if ($computedCrc !== $receivedCrc) {
            return ['outcome' => 'corrupted', 'data' => null];
        }

        $flightNumber = trim(substr($frame, self::OFFSET_FLIGHT_NUMBER, 10), "\0 ");
        $packetNumber = ord($frame[self::OFFSET_PACKET_NUMBER]);

        $altitude = $this->unpackFloat($frame, self::OFFSET_ALTITUDE);
        $speed = $this->unpackFloat($frame, self::OFFSET_SPEED);
        $acceleration = $this->unpackFloat($frame, self::OFFSET_ACCELERATION);
        $thrust = $this->unpackFloat($frame, self::OFFSET_THRUST);
        $temperature = $this->unpackFloat($frame, self::OFFSET_TEMPERATURE);

        if ($altitude === null || $speed === null || $acceleration === null || $thrust === null || $temperature === null) {
            return ['outcome' => 'corrupted', 'data' => null];
        }

        if (! RangeValidator::isValid($altitude, $speed, $acceleration, $thrust, $temperature)) {
            return ['outcome' => 'corrupted', 'data' => null];
        }

        return [
            'outcome' => 'valid',
            'data' => [
                'flightNumber' => $flightNumber,
                'packetNumber' => $packetNumber,
                'altitude' => round((float) $altitude, 2),
                'speed' => round((float) $speed, 2),
                'acceleration' => round((float) $acceleration, 2),
                'thrust' => round((float) $thrust, 2),
                'temperature' => round((float) $temperature, 2),
            ],
        ];
    }

    public function bufferLength(): int
    {
        return strlen($this->buffer);
    }

    private function unpackFloat(string $frame, int $offset): ?float
    {
        $value = unpack('G', substr($frame, $offset, 4));

        if ($value === false || ! isset($value[1])) {
            return null;
        }

        return (float) $value[1];
    }

    private function compactBuffer(): void
    {
        if (strlen($this->buffer) <= self::MAX_BUFFER_LENGTH) {
            return;
        }

        $startPos = strpos($this->buffer, "\x82");
        if ($startPos === false) {
            $this->buffer = '';

            return;
        }

        if ($startPos > 0) {
            $this->buffer = substr($this->buffer, $startPos);
        }

        while (strlen($this->buffer) > self::MAX_BUFFER_LENGTH) {
            $nextStart = strpos($this->buffer, "\x82", 1);
            if ($nextStart === false) {
                $this->buffer = '';

                return;
            }

            $this->buffer = substr($this->buffer, $nextStart);
        }
    }
}
