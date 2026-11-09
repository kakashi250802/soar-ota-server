<?php

namespace App\Ota;

use App\Models\SoarApp;
use RuntimeException;

/**
 * Per-app signing keys on the local file system. In production, replace this with a KMS or HSM
 * backed implementation: the only operations the server needs are "sign these bytes" and
 * "what is the public key".
 */
class KeyStore
{
    public function path(string $appId): string
    {
        return rtrim(config('ota.keys_dir'), '/')."/$appId.key";
    }

    public function has(string $appId): bool
    {
        return is_file($this->path($appId));
    }

    /**
     * Signing key for an app, or for one tier (channel) of it: `<app_id>.<channel>.key` wins over
     * `<app_id>.key`, so dev, staging and production can each have their own key.
     */
    public function forApp(SoarApp|string $app, ?string $channel = null): KeyPair
    {
        $id = $app instanceof SoarApp ? $app->app_id : $app;
        if ($channel !== null && preg_match(SoarApp::ID_PATTERN, "$id.$channel") && $this->has("$id.$channel")) {
            return KeyPair::fromSeedHex((string) file_get_contents($this->path("$id.$channel")));
        }
        if (! preg_match(SoarApp::ID_PATTERN, $id) || ! $this->has($id)) {
            throw new RuntimeException("No signing key configured for app $id");
        }

        return KeyPair::fromSeedHex((string) file_get_contents($this->path($id)));
    }

    /** Writes the seed (64 hex chars, `soar keygen` format) with owner-only permissions. */
    public function store(string $appId, string $seedHex): KeyPair
    {
        $pair = KeyPair::fromSeedHex($seedHex); // validates the format
        $dir = rtrim(config('ota.keys_dir'), '/');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $this->path($appId);
        if (is_file($path)) {
            throw new RuntimeException("A signing key for $appId already exists; refusing to overwrite it");
        }
        file_put_contents($path, trim($seedHex)."\n");
        chmod($path, 0600);

        return $pair;
    }
}
