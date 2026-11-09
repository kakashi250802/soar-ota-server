<?php

namespace App\Ota;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/** Content-addressed bundle blobs: `<app_id>/blobs/<sha256>.soarb`. Never overwritten. */
class BundleStore
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('ota.disk'));
    }

    public function has(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    public function put(string $key, string $bytes): void
    {
        if (! $this->has($key)) {
            $this->disk()->put($key, $bytes);
        }
    }

    /** @return resource|null */
    public function stream(string $key)
    {
        return $this->disk()->readStream($key);
    }

}
