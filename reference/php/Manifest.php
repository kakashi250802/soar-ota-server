<?php

namespace App\Ota;

/**
 * The signed description of one bundle, byte-compatible with `soar_ota::Manifest` in the Soar
 * engine (crates/soar-ota/src/manifest.rs). The signature covers the fixed binary layout built
 * by {@see canonicalBytes()}, never the JSON text.
 *
 * Integers are PHP ints (signed 64-bit), so `version` and `bundle_size` are limited to
 * PHP_INT_MAX; the Rust side allows the full u64 range.
 */
final class Manifest
{
    public const MAGIC = "soar-ota-manifest-v1\0";

    /** @param list<string> $kernels */
    public function __construct(
        public readonly string $appId,
        public readonly string $channel,
        public readonly int $version,
        public readonly int $minHostVer,
        public readonly array $kernels,
        public readonly string $bundleSha256,
        public readonly int $bundleSize,
        public readonly int $rolloutPercent,
        public readonly ApplyMode $applyMode,
        public readonly string $signature = '',
    ) {
    }

    /**
     * Parses the JSON shape `soar publish` writes. Strict about types, as serde is: every
     * field must be present, integers must be integers. Unknown fields are ignored.
     */
    public static function fromArray(array $d): self
    {
        $str = function (string $k) use ($d): string {
            if (! isset($d[$k]) || ! is_string($d[$k])) {
                throw new InvalidManifest("`$k` must be a string");
            }

            return $d[$k];
        };
        $int = function (string $k, int $min, int $max) use ($d): int {
            if (! isset($d[$k]) || ! is_int($d[$k]) || $d[$k] < $min || $d[$k] > $max) {
                throw new InvalidManifest("`$k` must be an integer in $min..$max");
            }

            return $d[$k];
        };
        $kernels = $d['kernels'] ?? null;
        if (! is_array($kernels) || ! array_is_list($kernels) || array_filter($kernels, fn ($k) => ! is_string($k))) {
            throw new InvalidManifest('`kernels` must be a list of strings');
        }
        $mode = ApplyMode::tryFrom($str('apply_mode')) ?? throw new InvalidManifest('`apply_mode` is not known');

        return new self(
            $str('app_id'), $str('channel'), $int('version', 0, PHP_INT_MAX), $int('min_host_ver', 0, 4294967295),
            $kernels, $str('bundle_sha256'), $int('bundle_size', 0, PHP_INT_MAX), $int('rollout_percent', 0, 100),
            $mode, $d['signature'] ?? '',
        );
    }

    /** @return array<string, mixed> in the field order of the Rust struct */
    public function toArray(): array
    {
        return [
            'app_id' => $this->appId,
            'channel' => $this->channel,
            'version' => $this->version,
            'min_host_ver' => $this->minHostVer,
            'kernels' => $this->kernels,
            'bundle_sha256' => $this->bundleSha256,
            'bundle_size' => $this->bundleSize,
            'rollout_percent' => $this->rolloutPercent,
            'apply_mode' => $this->applyMode->value,
            'signature' => $this->signature,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Bytes that get signed: every field except the signature. Strings are a u32 big-endian byte
     * length followed by UTF-8 bytes; integers are big-endian; kernels are sorted bytewise and
     * de-duplicated so the publisher's order cannot matter.
     */
    public function canonicalBytes(): string
    {
        $str = fn (string $s): string => pack('N', strlen($s)).$s;
        $kernels = array_values(array_unique($this->kernels));
        sort($kernels, SORT_STRING); // strcmp order, independent of locale
        $out = self::MAGIC.$str($this->appId).$str($this->channel).pack('J', $this->version).pack('N', $this->minHostVer)
            .pack('N', count($kernels));
        foreach ($kernels as $k) {
            $out .= $str($k);
        }

        return $out.$str($this->bundleSha256).pack('J', $this->bundleSize).chr($this->rolloutPercent).chr($this->applyMode->byte());
    }

    public function signedWith(KeyPair $key): self
    {
        return $this->withSignature(bin2hex($key->sign($this->canonicalBytes())));
    }

    public function withSignature(string $hex): self
    {
        return new self($this->appId, $this->channel, $this->version, $this->minHostVer, $this->kernels, $this->bundleSha256,
            $this->bundleSize, $this->rolloutPercent, $this->applyMode, $hex);
    }

    public function withChannel(string $channel): self
    {
        return new self($this->appId, $channel, $this->version, $this->minHostVer, $this->kernels, $this->bundleSha256,
            $this->bundleSize, $this->rolloutPercent, $this->applyMode, '');
    }

    /** True when the signature is well formed and valid for this manifest under the public key (hex). */
    public function verify(string $publicKeyHex): bool
    {
        try {
            $sig = Hex::decode($this->signature, 64);
            $sha = Hex::decode($this->bundleSha256, 32);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $sha !== '' && KeyPair::verify($this->canonicalBytes(), $sig, $publicKeyHex);
    }
}
