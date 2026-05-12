<?php

namespace App\Services;

use App\Exceptions\UpstreamUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class FlightDirectoryService
{
    public function __construct(
        private string $scheme,
        private string $host,
        private int $port,
    ) {}

    public function all(): array
    {
        $url = sprintf('%s://%s:%d/flights', $this->scheme, $this->host, $this->port);

        try {
            $response = Http::timeout(10)->get($url);
        } catch (ConnectionException $e) {
            throw new UpstreamUnavailableException('Flight list connection failed', previous: $e);
        }

        if (! $response->successful()) {
            throw new UpstreamUnavailableException('Flight list failed with '.$response->status());
        }

        $flights = $response->json();
        if (! is_array($flights)) {
            throw new UpstreamUnavailableException('Flight list returned invalid data');
        }

        return $flights;
    }
}
