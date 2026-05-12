<?php

namespace App\Support;

use InvalidArgumentException;

final class Crc16Ccitt
{
    private const POLYNOMIAL = 0x1021;

    private const INITIAL = 0xFFFF;

    public static function compute(string $bytes): int
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('CRC input cannot be empty.');
        }

        $crc = self::INITIAL;
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($bytes[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x8000) !== 0)
                    ? (($crc << 1) ^ self::POLYNOMIAL) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }
}
