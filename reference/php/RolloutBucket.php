<?php

namespace App\Ota;

/** Port of `soar_ota::rollout_bucket`: the same device always lands in the same 0..99 bucket. */
final class RolloutBucket
{
    public static function of(string $deviceId, string $appId, int $version): int
    {
        $str = fn (string $s): string => pack('N', strlen($s)).$s;
        $digest = hash('sha256', "soar-ota-rollout\0".$str($deviceId).$str($appId).pack('J', $version), true);
        // First 8 bytes as a big-endian u64, modulo 100, without leaving PHP's signed int range:
        // n = hi * 2^32 + lo and 2^32 = 96 (mod 100).
        ['hi' => $hi, 'lo' => $lo] = unpack('Nhi/Nlo', $digest);

        return (($hi % 100) * 96 + ($lo % 100)) % 100;
    }

    /** A device receives a release when its bucket is below the percentage. */
    public static function includes(string $deviceId, string $appId, int $version, int $percent): bool
    {
        return self::of($deviceId, $appId, $version) < $percent;
    }
}
