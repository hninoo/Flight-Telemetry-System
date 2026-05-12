<?php

namespace App\Services;

use App\Events\TelemetryUpdated;
use App\Support\Enums\ConnectionStatus;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client as SwooleClient;

class Client
{
    public const MIN_INTERVAL_MS = 100;

    public const MAX_INTERVAL_MS = 10000;

    private const BACKOFF_SECONDS = [1, 2, 4, 8, 16, 30];

    private const CONNECT_TIMEOUT = 5.0;

    private const BROADCAST_QUEUE_SIZE = 64;

    private const MAX_PACKET_LENGTH = 8 * 1024;

    private const SOCKET_BUFFER_SIZE = 64 * 1024;

    private PacketParser $parser;

    private ConnectionStatus $status = ConnectionStatus::Closed;

    private bool $shouldRun = true;

    private int $reconnectAttempt = 0;

    private ?Channel $broadcastQueue = null;

    private ?SwooleClient $socket = null;

    public function __construct(
        public readonly string $flightId,
        public readonly string $flightNumber,
        public readonly string $host,
        public readonly int $port,
        public readonly int $intervalMs,
    ) {
        if ($intervalMs < self::MIN_INTERVAL_MS || $intervalMs > self::MAX_INTERVAL_MS) {
            throw new InvalidArgumentException(
                'intervalMs must be between '.self::MIN_INTERVAL_MS.' and '.self::MAX_INTERVAL_MS.' ms'
            );
        }

        $this->parser = new PacketParser;
    }

    public function run(): void
    {
        $this->broadcastQueue = new Channel(self::BROADCAST_QUEUE_SIZE);
        $this->spawnBroadcaster();

        while ($this->shouldRun) {
            $socket = new SwooleClient(SWOOLE_SOCK_TCP);
            $this->socket = $socket;

            $socket->set(
                [
                    'open_eof_check' => false,
                    'package_max_length' => self::MAX_PACKET_LENGTH,
                    'socket_buffer_size' => self::SOCKET_BUFFER_SIZE,
                ]
            );

            if (! $socket->connect($this->host, $this->port, self::CONNECT_TIMEOUT)) {
                $this->onError("connect failed: {$socket->errCode}");
                $socket->close();
                $this->socket = null;
                $this->backoffSleep();

                continue;
            }

            $subscription = json_encode([
                'type' => 'subscribe',
                'flightId' => $this->flightId,
                'intervalMs' => $this->intervalMs,
            ], JSON_UNESCAPED_SLASHES);

            if ($subscription === false || ! $socket->send($subscription)) {
                $this->onError('subscription send failed');
                $socket->close();
                $this->socket = null;
                $this->backoffSleep();

                continue;
            }

            $this->reconnectAttempt = 0;
            $this->recvLoop($socket);
            $socket->close();
            $this->socket = null;

            if ($this->shouldRun) {
                $this->backoffSleep();
            }
        }

        $this->status = ConnectionStatus::Closed;
        $this->broadcastQueue->close();
        $this->broadcastQueue = null;
    }

    private function recvLoop(SwooleClient $socket): void
    {
        $timeout = max(15.0, ($this->intervalMs / 1000) * 3);

        while ($this->shouldRun) {
            $chunk = $socket->recv($timeout);

            if ($chunk === false) {
                if (! $this->shouldRun) {
                    return;
                }

                $reason = $socket->errCode === SOCKET_ETIMEDOUT
                    ? 'recv timeout'
                    : "recv error: {$socket->errCode}";
                $this->onError($reason);

                return;
            }

            if ($chunk === '') {
                $this->onClosed('peer closed');

                return;
            }

            $this->parser->feed($chunk);

            foreach ($this->parser->drain() as $result) {
                $this->handleResult($result);
            }
        }
    }

    private function handleResult(array $result): void
    {
        if ($result['outcome'] === 'valid') {
            $this->status = ConnectionStatus::Valid;
            $this->enqueueBroadcast($result['data']);

            return;
        }

        $this->status = ConnectionStatus::Corrupted;
        $this->enqueueBroadcast(null);
    }

    private function enqueueBroadcast(?array $data): void
    {
        $payload = $this->sanitizeForBroadcast([
            'flightId' => $this->flightId,
            'flightNumber' => $this->flightNumber,
            'status' => $this->status->value,
            'data' => $data,
            'timestamp' => (int) (microtime(true) * 1000),
        ]);

        if ($this->broadcastQueue === null) {
            $this->dispatch($payload);

            return;
        }

        if (! $this->broadcastQueue->push($payload, 0.001)) {
            Log::warning('broadcast dropped', [
                'flight' => $this->flightId,
                'status' => $this->status->value,
            ]);
        }
    }

    private function spawnBroadcaster(): void
    {
        $queue = $this->broadcastQueue;

        Coroutine::create(function () use ($queue) {
            while (true) {
                $payload = $queue->pop();
                if ($payload === false) {
                    return;
                }
                $this->dispatch($payload);
            }
        });
    }

    private function dispatch(array $payload): void
    {
        try {
            event(new TelemetryUpdated($this->flightId, $payload));
        } catch (\Throwable $e) {
            Log::error('broadcast failed', [
                'flight' => $this->flightId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sanitizeForBroadcast(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->sanitizeForBroadcast($v);
            }

            return $out;
        }

        if (is_string($value)) {
            return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        }

        if (is_float($value) && ! is_finite($value)) {
            return null;
        }

        return $value;
    }

    private function onError(string $reason): void
    {
        Log::warning('error', ['flight' => $this->flightId, 'reason' => $reason]);
        $this->status = ConnectionStatus::Error;
        $this->enqueueBroadcast(null);
    }

    private function onClosed(string $reason): void
    {
        $this->status = ConnectionStatus::Closed;
        $this->enqueueBroadcast(null);
    }

    private function backoffSleep(): void
    {
        if (! $this->shouldRun) {
            return;
        }

        $idx = min($this->reconnectAttempt, count(self::BACKOFF_SECONDS) - 1);
        $delay = self::BACKOFF_SECONDS[$idx];
        $this->reconnectAttempt++;

        Coroutine::sleep((float) $delay);
    }

    public function stop(): void
    {
        $this->shouldRun = false;
        $this->socket?->close();
    }

    public function status(): ConnectionStatus
    {
        return $this->status;
    }
}
