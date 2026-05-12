<?php

namespace Tests\Unit;

use App\Services\Client;
use App\Services\Monitor;
use App\Services\PacketParser;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class TelemetrySafetyTest extends TestCase
{
    public function test_client_rejects_subscription_interval_below_server_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(
            flightId: '1',
            flightNumber: 'ONX101',
            host: 'localhost',
            port: 4001,
            intervalMs: Client::MIN_INTERVAL_MS - 1,
        );
    }

    public function test_client_rejects_subscription_interval_above_server_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(
            flightId: '1',
            flightNumber: 'ONX101',
            host: 'localhost',
            port: 4001,
            intervalMs: Client::MAX_INTERVAL_MS + 1,
        );
    }

    public function test_broadcast_sanitizer_removes_binary_string_bytes(): void
    {
        $client = new Client(
            flightId: '1',
            flightNumber: "ONX101\x00\xff",
            host: 'localhost',
            port: 4001,
            intervalMs: Client::MIN_INTERVAL_MS,
        );

        $method = new ReflectionMethod(Client::class, 'sanitizeForBroadcast');
        $method->setAccessible(true);

        $payload = $method->invoke($client, [
            'flightNumber' => "ONX101\x00\xff",
            'data' => ['flightNumber' => "ONX101\x01"],
        ]);

        $this->assertSame('ONX101', $payload['flightNumber']);
        $this->assertSame('ONX101', $payload['data']['flightNumber']);
    }

    public function test_parser_compacts_oversized_noise_without_blind_tail_truncation(): void
    {
        $parser = new PacketParser;

        $parser->feed(str_repeat('x', 5000));

        $this->assertSame(0, $parser->bufferLength());
    }

    public function test_monitor_throws_runtime_exception_when_memory_limit_is_exceeded(): void
    {
        $this->expectException(RuntimeException::class);

        (new Monitor(1))->check();
    }

    public function test_monitor_rejects_non_positive_memory_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Monitor(0);
    }
}
