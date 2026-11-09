# Manifest, signing and bundle hash

This is the part of the protocol that must stay **byte-compatible** with the Soar engine. Source of truth:
`crates/soar-ota/src/manifest.rs` in the Soar repository. Everything below was checked against that code, and
`examples/minimal-php/verify.php` checks it against manifests the real CLI signed (`fixtures/manifests`).

## Manifest JSON

A manifest describes one bundle for one channel. Devices get it as JSON (`Content-Type: application/json`). Parsing is
done with serde: every field below must be present, with exactly these JSON types; unknown extra fields are ignored.

| Field | JSON type | Meaning |
|---|---|---|
| `app_id` | string | The app. The device refuses a manifest whose `app_id` differs from its own. |
| `channel` | string | The channel the device follows (`production`, `staging`, ...). The device refuses a different one. |
| `version` | integer (u64) | Monotonic per app. A device only installs a version **above** the newest it already has. |
| `min_host_ver` | integer (u32) | Oldest host binary that can run this bundle. The device refuses the manifest if its own host version is lower. Soar's counterpart of Expo's runtime version. |
| `kernels` | array of strings | Native kernels the bundle calls. The device needs all of them compiled in. Order does not matter. |
| `bundle_sha256` | string | Lowercase hex SHA-256 of the bundle bytes (64 characters). |
| `bundle_size` | integer (u64) | Bundle length in bytes. |
| `rollout_percent` | integer 0..100 | Share of devices that take this version (see [rollout-and-rollback.md](rollout-and-rollback.md)). |
| `apply_mode` | string | `next_launch`, `on_resume` or `immediate`: when the staged bundle replaces the running one. |
| `signature` | string | Lowercase hex ed25519 signature (128 characters) over the canonical bytes below. |

Example, written by `soar publish` (kernels in the order the publisher listed them):

```json
{
  "app_id": "dev.soar.demo",
  "channel": "production",
  "version": 1,
  "min_host_ver": 29,
  "kernels": ["storage", "network", "camera"],
  "bundle_sha256": "3a5255f4dd6f7377ed6b981b99a04c042765117f2f414b4e885c1c9009818a64",
  "bundle_size": 685,
  "rollout_percent": 25,
  "apply_mode": "on_resume",
  "signature": "89e302f80d5d5ee868dd086e93d6d0ea36f70f7904a41541fc767447b3742ec2823c1cdc06f6849979f382cf94755d925201d52898a2f183410857492896ad0f"
}
```

Notes:

- `min_host_ver` is `max(--min-host, bundle format version)`: the CLI raises it to the `.soarb` format version the
  bundle needs (29 for the demo bundle), so a bundle never reaches an engine that cannot load it. The server stores
  what the publisher sends; see the open questions in the README about parsing the `.soarb` header server side.
- The JSON text is **not** what is signed. Whitespace, key order and `kernels` order can vary; the signature covers the
  binary string below. The Rust CLI writes pretty JSON with two spaces; PHP's `JSON_PRETTY_PRINT` writes four. Both are valid.
- Hex is **lowercase only**. The Rust decoder rejects uppercase digits.

## What is signed: the canonical byte string

`Manifest::canonical_bytes`. Concatenate, in this order:

| # | Bytes | Encoding |
|---|---|---|
| 1 | `soar-ota-manifest-v1` followed by a NUL byte | 21 bytes, ASCII (domain separator) |
| 2 | `app_id` | u32 big-endian **byte** length, then the UTF-8 bytes |
| 3 | `channel` | same |
| 4 | `version` | u64 big-endian |
| 5 | `min_host_ver` | u32 big-endian |
| 6 | kernels count | u32 big-endian, after sorting bytewise and removing duplicates |
| 7 | each kernel, in that sorted order | u32 length + UTF-8 bytes |
| 8 | `bundle_sha256` | u32 length (always 64) + the 64 hex characters as ASCII (**not** the 32 raw bytes) |
| 9 | `bundle_size` | u64 big-endian |
| 10 | `rollout_percent` | 1 byte |
| 11 | `apply_mode` | 1 byte: `next_launch` = 0, `on_resume` = 1, `immediate` = 2 |

PHP, as in `examples/minimal-php/lib/ota.php` and `reference/php/Manifest.php`:

```php
$str = fn (string $s): string => pack('N', strlen($s)) . $s;     // strlen counts bytes
$kernels = array_values(array_unique($kernels));
sort($kernels, SORT_STRING);                                      // bytewise, locale independent
$bytes = "soar-ota-manifest-v1\0" . $str($appId) . $str($channel)
       . pack('J', $version) . pack('N', $minHostVer) . pack('N', count($kernels))
       . implode('', array_map($str, $kernels))
       . $str($bundleSha256) . pack('J', $bundleSize)
       . chr($rolloutPercent) . chr($applyModeByte);
```

Test vector (computed by the Rust code; `fixtures/rust-vectors.json`, first manifest): `app_id` `dev.soar.demo`,
`channel` `production`, `version` 5, `min_host_ver` 29, `kernels` `["storage","network","camera","network"]`,
`bundle_sha256` 64 zeros, `bundle_size` 123, `rollout_percent` 25, `apply_mode` `on_resume`:

```
736f61722d6f74612d6d616e69666573742d7631 00      magic + NUL
0000000d 6465762e736f61722e64656d6f               app_id
0000000a 70726f64756374696f6e                     channel
0000000000000005                                  version
0000001d                                          min_host_ver (29)
00000003                                          3 kernels after dedup
00000006 63616d657261  00000007 6e6574776f726b  00000007 73746f72616765   camera, network, storage
00000040 30303030...30 (64 x "0")                 bundle_sha256 as text
000000000000007b                                  bundle_size (123)
19                                                rollout_percent (25)
01                                                on_resume
```

## Signature and key encoding

- Algorithm: **ed25519** (RFC 8032), pure Ed25519, no prehash, no context.
- Secret key file written by `soar keygen` (`soar-signing.key`): the 32-byte **seed** as 64 lowercase hex characters
  plus a newline. It is the seed, not the 64-byte libsodium secret key.
- Public key file (`soar-signing.pub`): the 32-byte public key as 64 lowercase hex characters plus a newline. The app
  ships it; devices verify with it.
- `signature` in the manifest: the 64-byte signature as 128 lowercase hex characters.
- Ed25519 is deterministic, so signing the same manifest with the same key always gives the same signature. The PHP
  tests rely on this: PHP re-signs each CLI manifest and gets the identical signature.

PHP with sodium (`reference/php/KeyPair.php`):

```php
$pair   = sodium_crypto_sign_seed_keypair(hex2bin($seedHex));          // seed -> keypair
$secret = sodium_crypto_sign_secretkey($pair);
$sig    = sodium_crypto_sign_detached($canonicalBytes, $secret);       // 64 bytes
$hex    = bin2hex($sig);

$ok = sodium_crypto_sign_verify_detached(hex2bin($hex), $canonicalBytes, hex2bin($publicKeyHex));
```

The device uses `verify_strict` (ed25519-dalek), which rejects malleable signatures and small-order keys. libsodium's
verify rejects non-canonical `S` and small-order points too, so the two agree on every signature a legitimate signer
makes. The server is **not** the trust root (devices verify again), so a tiny difference on adversarial inputs does not
open a hole; it would only let the server store a manifest that devices then reject.

After the signature, a device also checks `rollout_percent <= 100` and that `bundle_sha256` is 32 bytes of hex. The
server's `Manifest::fromArray` and `verify` check the same.

## Bundle hash

`bundle_sha256` is the lowercase hex SHA-256 of the exact `.soarb` bytes, `bundle_size` their length. After download
the device compares the length first, then the hash, and refuses the bundle on any mismatch. In PHP:
`hash('sha256', $bytes)`. The static layout names bundle files `<version>-<first 16 hex of sha256>.soarb`.

## Limits

- The device accepts at most **16 MiB** (`updater::MAX_DOWNLOAD`) for a manifest or a bundle; a larger response is an
  error. The server enforces `OTA_MAX_BUNDLE_BYTES` (default 16 MiB) on upload.
- PHP integers are signed 64-bit, so the server supports `version` and `bundle_size` up to 2^63 - 1 where Rust allows
  2^64 - 1. Versions are a small counter in practice.
