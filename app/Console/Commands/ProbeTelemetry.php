<?php

namespace App\Console\Commands;

use App\Exceptions\UpstreamUnavailableException;
use App\Services\Client;
use App\Services\FlightDirectoryService;
use App\Services\PacketParser;
use App\Support\Enums\ConnectionStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telemetry:probe
    {flightId? : Flight ID from the flight list}
    {--all : Probe every flight from the flight list}
    {--interval= : Subscription interval in ms; defaults to telemetry.default_interval_ms}
    {--packets=3 : Number of parsed packets to print before exiting}
    {--timeout=20 : Max seconds to wait before exiting}')]
#[Description('Probe telemetry TCP streams and print parsed packet results.')]
class ProbeTelemetry extends Command
{
    public function handle(FlightDirectoryService $directory): int
    {
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

        $packetLimit = max(1, (int) $this->option('packets'));
        $timeout = max(1, (int) $this->option('timeout'));

        $flights = $this->resolveFlights($directory);
        if ($flights === []) {
            return self::FAILURE;
        }

        $hasFailure = false;
        foreach ($flights as $flight) {
            if (! $this->probeFlight($flight, $interval, $packetLimit, $timeout)) {
                $hasFailure = true;
            }
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    private function probeFlight(array $flight, int $interval, int $packetLimit, int $timeout): bool
    {
        $host = (string) config('telemetry.host');
        $port = (int) $flight['telemetryPort'];
        $flightId = (string) $flight['id'];

        $this->newLine();
        $this->info("Connecting to {$host}:{$port} for flight {$flightId}...");

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            $this->error("Connect failed: {$errstr} ({$errno})");

            return false;
        }

        stream_set_timeout($socket, 5);

        $subscription = json_encode([
            'type' => 'subscribe',
            'flightId' => $flightId,
            'intervalMs' => $interval,
        ], JSON_UNESCAPED_SLASHES);

        if ($subscription === false || fwrite($socket, $subscription) === false) {
            fclose($socket);
            $this->error('Subscription failed.');

            return false;
        }

        $this->info("Subscribed: {$subscription}");

        $parser = new PacketParser;
        $deadline = time() + $timeout;
        $printed = 0;

        while ($printed < $packetLimit && time() < $deadline) {
            $chunk = fread($socket, 8192);

            if ($chunk === false) {
                fclose($socket);
                $this->error('Read failed.');

                return false;
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);
                if (($meta['timed_out'] ?? false) === true) {
                    continue;
                }

                fclose($socket);
                $this->warn('Connection closed before enough packets were received.');

                return $printed > 0;
            }

            $parser->feed($chunk);

            foreach ($parser->drain() as $result) {
                $printed++;
                $status = $result['outcome'] === 'valid'
                    ? ConnectionStatus::Valid
                    : ConnectionStatus::Corrupted;

                $this->line(json_encode([
                    'flightId' => $flightId,
                    'packet' => $printed,
                    'status' => $status->value,
                    'data' => $result['data'],
                ], JSON_UNESCAPED_SLASHES));

                if ($printed >= $packetLimit) {
                    break;
                }
            }
        }

        fclose($socket);

        if ($printed === 0) {
            $this->warn("No complete packets received within {$timeout}s.");

            return false;
        }

        return true;
    }

    private function resolveFlights(FlightDirectoryService $directory): array
    {
        try {
            $flights = $directory->all();
        } catch (UpstreamUnavailableException $e) {
            $this->error('Flight list fetch failed: '.$e->getMessage());

            return [];
        }

        if ($flights === []) {
            $this->error('No flights found.');

            return [];
        }

        if ($this->option('all')) {
            return $flights;
        }

        $requestedId = $this->argument('flightId');
        if ($requestedId === null) {
            return [$flights[0]];
        }

        foreach ($flights as $flight) {
            if ((string) ($flight['id'] ?? '') === (string) $requestedId) {
                return [$flight];
            }
        }

        $this->error("Flight {$requestedId} was not found.");

        return [];
    }
}
