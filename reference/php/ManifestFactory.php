<?php

namespace App\Ota;

use App\Models\SoarApp;
use App\Models\Update;

/** Builds and signs the manifest a device receives for an update, as served on `$channel`. */
class ManifestFactory
{
    public function __construct(private readonly KeyStore $keys)
    {
    }

    public function signed(SoarApp $app, Update $update, string $channel, int $rolloutPercent): Manifest
    {
        $manifest = new Manifest(
            $app->app_id, $channel, (int) $update->version, (int) $update->min_host_ver, array_values($update->kernels ?? []),
            $update->bundle_sha256, (int) $update->bundle_size, $rolloutPercent, ApplyMode::from($update->apply_mode),
        );

        return $manifest->signedWith($this->keys->forApp($app, $channel));
    }

    public function directive(SoarApp $app, Update $update, string $channel): array
    {
        return Directive::rollbackToEmbedded($this->keys->forApp($app, $channel), $app->app_id, $channel, (int) $update->version);
    }
}
