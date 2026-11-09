<?php

namespace App\Ota;

use InvalidArgumentException;

/** Lowercase hex only, like the Rust code: uppercase digits are rejected. */
final class Hex
{
    public static function decode(string $hex, ?int $bytes = null): string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/\A[0-9a-f]+\z/', $hex) !== 1) {
            throw new InvalidArgumentException('not lowercase hex');
        }
        if ($bytes !== null && strlen($hex) !== $bytes * 2) {
            throw new InvalidArgumentException("expected $bytes bytes of hex");
        }

        return hex2bin($hex);
    }
}
