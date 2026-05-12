<?php

namespace App\Console\Commands;

use App\Exceptions\UpstreamUnavailableException;
use App\Services\Client;
use App\Services\CoroutineRunner;
use App\Services\FlightDirectoryService;
use App\Services\Monitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Swoole\Runtime;

#[Signature('telemetry:start
    {--interval= : Subscription interval in ms; defaults to telemetry.default_interval_ms}
    {--memory= : Memory limit MB; defaults to telemetry.memory_limit_mb}')]
#[Description('Start the flight telemetry processing daemon (Swoole coroutines).')]
class StartTelemetry extends Command
{
    private bool $shouldStop = false;

    public function handle(FlightDirectoryService $directory): int
    {
        if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
            $this->error('Swoole extension is not loaded. Install ext-swoole.');

            return self::FAILURE;
        }

        $this->installSignalHandlers();

        $interval = $this->option('interval') !== null
            ? (int) $this->option('interval')
            : (int) config('telemetry.default_interval_ms');

        if ($interval < Client::MIN_INTERVAL_MS || $interval > Client::MAX_INTERVAL_MS) {
            $this->error(sprintf(
                '--interval must be between %d and %d ms',
                Client::MIN_INTERVAL_MS,
                Client::MAX_INTERVAL_MS,
            ));

            return self::INVALID;
        }

        $memoryMb = $this->option('memory') !== null
            ? (int) $this->option('memory')
            : (int) config('telemetry.memory_limit_mb');


        try {
            $flights = $directory->all();
        } catch (UpstreamUnavailableException $e) {
            return self::FAILURE;
        }

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $runner = new CoroutineRunner;
        foreach ($flights as $flight) {
            $runner->add(new Client(
                flightId: (string) $flight['id'],
                flightNumber: (string) $flight['flightNumber'],
                host: (string) config('telemetry.host'),
                port: (int) $flight['telemetryPort'],
                intervalMs: $interval,
            ));
        }

        $monitor = new Monitor($memoryMb);
        $memoryExceeded = false;

        $runner->run(function () use ($monitor, &$memoryExceeded) {
            try {
                $monitor->check();
            } catch (RuntimeException) {
                $memoryExceeded = true;

                return false;
            }

            return ! $this->shouldStop;
        });

        return $memoryExceeded ? self::FAILURE : self::SUCCESS;
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $handler = function (int $signo) {
            $this->shouldStop = true;
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }
}
