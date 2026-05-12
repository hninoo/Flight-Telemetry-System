<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

final class Monitor
{
    public function __construct(private int $limitMb)
    {
        if ($limitMb <= 0) {
            throw new InvalidArgumentException('Memory limit must be greater than 0 MB.');
        }
    }

    public function exceeded(): bool
    {
        return $this->memoryUsageBytes() > $this->limitBytes();
    }

    public function check(): void
    {
        $usedBytes = $this->memoryUsageBytes();

        if ($usedBytes <= $this->limitBytes()) {
            return;
        }

        Log::warning('memory limit exceeded', [
            'used_mb' => $this->bytesToMb($usedBytes),
            'limit_mb' => $this->limitMb,
        ]);

        throw new RuntimeException('Telemetry memory limit exceeded');
    }

    public function currentMb(): float
    {
        return $this->bytesToMb($this->memoryUsageBytes());
    }

    private function memoryUsageBytes(): int
    {
        return memory_get_usage(true);
    }

    private function limitBytes(): int
    {
        return $this->limitMb * 1024 * 1024;
    }

    private function bytesToMb(int $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }
}
