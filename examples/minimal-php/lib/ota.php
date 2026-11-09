<?php
// The smallest complete Soar OTA toolkit in plain PHP 8.1+ with the sodium extension (bundled with PHP).
// Byte-compatible with crates/soar-ota/src/manifest.rs in the Soar engine; see docs/manifest-and-signing.md.

declare(strict_types=1);

const APPLY_MODES = ['next_launch' => 0, 'on_resume' => 1, 'immediate' => 2];

/** The exact bytes a manifest signature covers (never the JSON text). */
function ota_canonical_bytes(array $m): string
{
    $str = fn (string $s): string => pack('N', strlen($s)) . $s; // u32 big-endian byte length + bytes
    $kernels = array_values(array_unique($m['kernels']));
    sort($kernels, SORT_STRING); // bytewise order, so the publisher's order does not matter
    $out = "soar-ota-manifest-v1\0" . $str($m['app_id']) . $str($m['channel'])
        . pack('J', $m['version']) . pack('N', $m['min_host_ver']) . pack('N', count($kernels));
    foreach ($kernels as $k) {
        $out .= $str($k);
    }
    return $out . $str($m['bundle_sha256']) . pack('J', $m['bundle_size'])
        . chr($m['rollout_percent']) . chr(APPLY_MODES[$m['apply_mode']]);
}

/** Signs `$m` with the 32-byte seed in `soar-signing.key` (64 hex characters) and returns it with `signature`. */
function ota_sign(array $m, string $seedHex): array
{
    $pair = sodium_crypto_sign_seed_keypair(hex2bin(trim($seedHex)));
    $m['signature'] = bin2hex(sodium_crypto_sign_detached(ota_canonical_bytes($m), sodium_crypto_sign_secretkey($pair)));
    return $m;
}

/** Whether `$m` carries a valid signature for the public key in `soar-signing.pub` (64 hex characters). */
function ota_verify(array $m, string $publicHex): bool
{
    $sig = hex2bin($m['signature'] ?? '');
    return $sig !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES
        && sodium_crypto_sign_verify_detached($sig, ota_canonical_bytes($m), hex2bin(trim($publicHex)));
}

/** The public key (64 hex characters) of a seed, to print or to ship in the app. */
function ota_public_key(string $seedHex): string
{
    return bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(hex2bin(trim($seedHex)))));
}

/** The file name a bundle has in the static layout: `<version>-<first 16 hex of sha256>.soarb`. */
function ota_bundle_file(array $m): string
{
    return $m['version'] . '-' . substr($m['bundle_sha256'], 0, 16) . '.soarb';
}
