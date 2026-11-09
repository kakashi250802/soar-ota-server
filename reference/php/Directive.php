<?php

namespace App\Ota;

/**
 * PLANNED, not in the Rust client yet: tells a device to drop its OTA bundle and run the bundle
 * embedded in the app binary (Expo's "roll back to embedded" directive). Signed with the same
 * ed25519 key over its own canonical bytes, with a different magic so a directive can never be
 * replayed as a manifest.
 */
final class Directive
{
    public const MAGIC = "soar-ota-directive-v1\0";

    public const ROLLBACK_TO_EMBEDDED = 'rollBackToEmbedded';

    public static function canonicalBytes(string $appId, string $channel, string $type, int $version): string
    {
        $str = fn (string $s): string => pack('N', strlen($s)).$s;

        return self::MAGIC.$str($appId).$str($channel).$str($type).pack('J', $version);
    }

    /** @return array<string, mixed> */
    public static function rollbackToEmbedded(KeyPair $key, string $appId, string $channel, int $version): array
    {
        $sig = bin2hex($key->sign(self::canonicalBytes($appId, $channel, self::ROLLBACK_TO_EMBEDDED, $version)));

        return ['type' => self::ROLLBACK_TO_EMBEDDED, 'app_id' => $appId, 'channel' => $channel, 'version' => $version, 'signature' => $sig];
    }
}
