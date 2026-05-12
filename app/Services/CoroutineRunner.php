<?php

namespace App\Services;

use App\Support\Enums\ConnectionStatus;
use Illuminate\Support\Facades\Log;
use LogicException;
use Swoole\Coroutine;

final class CoroutineRunner
{
    private const CLIENT_RESTART_DELAY_SECONDS = 5.0;

    private const SHUTDOWN_TIMEOUT_SECONDS = 3.0;

    private const SHUTDOWN_POLL_SECONDS = 0.05;

    /** @var Client[] */
    private array $clients = [];

    private bool $stopped = false;

    public function add(Client $client): void
    {
        if ($this->stopped) {
            throw new LogicException('Cannot add a client after the runner has stopped.');
        }

        $this->clients[$client->flightId] = $client;
    }

    /** @return Client[] */
    public function clients(): array
    {
        return array_values($this->clients);
    }

    public function run(callable $tick): void
    {
        if ($this->clients === []) {
            Log::warning('runner started without clients');

            return;
        }

        Coroutine\run(function () use ($tick) {
            foreach ($this->clients as $client) {
                Coroutine::create(function () use ($client) {
                    while (! $this->stopped) {
                        try {
                            $client->run();

                            return;
                        } catch (\Throwable $e) {
                            Log::error('client coroutine crashed; restarting', [
                                'flight' => $client->flightId,
                                'error' => $e->getMessage(),
                            ]);

                            Coroutine::sleep(self::CLIENT_RESTART_DELAY_SECONDS);
                        }
                    }
                });
            }

            while (! $this->stopped) {
                Coroutine::sleep(1.0);
                try {
                    if ($tick() === false) {
                        $this->stopped = true;

                        break;
                    }
                } catch (\Throwable $e) {
                    Log::error('tick threw exception, shutting down', [
                        'error' => $e->getMessage(),
                    ]);
                    $this->stopped = true;

                    break;
                }
            }

            foreach ($this->clients as $client) {
                $client->stop();
            }

            $this->waitForClients();
        });
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    private function waitForClients(): void
    {
        $deadline = microtime(true) + self::SHUTDOWN_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if ($this->allClientsClosed()) {
                return;
            }

            Coroutine::sleep(self::SHUTDOWN_POLL_SECONDS);
        }

        Log::warning('runner shutdown timed out before all clients closed', [
            'open_clients' => $this->openClientIds(),
        ]);
    }

    private function allClientsClosed(): bool
    {
        foreach ($this->clients as $client) {
            if ($client->status() !== ConnectionStatus::Closed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function openClientIds(): array
    {
        $open = [];

        foreach ($this->clients as $client) {
            if ($client->status() !== ConnectionStatus::Closed) {
                $open[] = $client->flightId;
            }
        }

        return $open;
    }
}
