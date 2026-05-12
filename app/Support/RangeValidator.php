<?php

namespace App\Support;

final class RangeValidator
{
    public static function isValid(
        float $altitude,
        float $speed,
        float $acceleration,
        float $thrust,
        float $temperature,
    ): bool {
        foreach ([$altitude, $speed, $acceleration, $thrust, $temperature] as $value) {
            if (! is_finite($value)) {
                return false;
            }
        }

        return $altitude >= 9000 && $altitude <= 12000
            && $speed >= 220 && $speed <= 260
            && $acceleration >= -2 && $acceleration <= 2
            && $thrust >= 0 && $thrust <= 200000
            && $temperature >= -50 && $temperature <= 50;
    }
}
