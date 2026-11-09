# Soar OTA protocol

This is the reference for anyone who implements or extends the server without reading Rust. Every
format here is checked against the Soar engine's device code (`crates/soar-ota` in the Soar repo):
the test suite verifies manifests written by the real `soar` CLI and compares PHP output with
vectors computed by the Rust code (see [fixtures/README.md](../fixtures/README.md)).

There are two device protocols:

| | Static layout (today's client) | Soar Updates protocol v1 (planned client) |
|---|---|---|
| Who uses it | The current Rust updater (`updater.rs`) | The client after the changes in [client-changes.md](client-changes.md) |
| URLs | `GET /{app}/{channel}/manifest.json`, `GET /{app}/{channel}/bundles/{version}-{sha16}.soarb` | `GET /updates/{app}`, then `bundle_url` |
| Device info sent | none (plain GET) | `soar-*` headers |
| Who decides rollout | the device (bucket check) | the server, and the device checks again |
| Cacheable by a CDN | yes | the manifest no (per device), bundles yes |

## 1. Manifest

The manifest is a JSON object. Field order does not matter to devices; the server writes the
order below.

| Field | JSON type | Range / format | Meaning |
|---|---|---|---|
| `app_id` | string | `[A-Za-z0-9][A-Za-z0-9._-]{0,127}` on this server | App identifier, e.g. `dev.soar.demo`. Must equal the device's app id. |
| `channel` | string | `[A-Za-z0-9][A-Za-z0-9._-]{0,63}` on this server | The channel the manifest is served on. Must equal the device's channel. |
| `version` | integer | u64 in Rust; this server stores up to 2^63-1 | Monotonic per app. A device installs only a version above every version it has. |
| `min_host_ver` | integer | u32 | Oldest host binary that can run the bundle. `soar publish` raises it to the `.soarb` format version the bundle needs (29 for the fixtures). |
| `kernels` | array of strings | | Native kernels the bundle calls; the host must have all of them. |
| `bundle_sha256` | string | 64 lowercase hex | SHA-256 of the bundle bytes. |
| `bundle_size` | integer | u64 | Bundle length in bytes. |
| `rollout_percent` | integer | 0..100 | Share of devices that take this version (section 5). 0 halts it. |
| `apply_mode` | string | `next_launch`, `on_resume`, `immediate` | When a downloaded bundle is used. |
| `signature` | string | 128 lowercase hex | ed25519 signature over the canonical bytes (section 2). |

Parsing is strict, like Rust's serde: every field must be present with the right JSON type
(`2` is valid, `"2"` and `2.0` are not). Unknown fields are ignored by the device.

Example (written by `soar publish`, `fixtures/manifests/production-v1-r25.json`):

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

## 2. Signature

The JSON text is never signed (whitespace and key order differ between tools). The signature
covers a fixed binary string, built in this order:

| Part | Encoding |
|---|---|
| magic | the 21 bytes `soar-ota-manifest-v1` followed by one zero byte |
| `app_id` | str |
| `channel` | str |
| `version` | u64 big-endian (8 bytes) |
| `min_host_ver` | u32 big-endian (4 bytes) |
| kernel count | u32 big-endian: the number of kernels after sorting and de-duplicating |
| each kernel | str, in bytewise ascending order (`strcmp`), duplicates removed |
| `bundle_sha256` | str (the 64 hex characters as ASCII, not the raw 32 bytes) |
| `bundle_size` | u64 big-endian |
| `rollout_percent` | 1 byte |
| `apply_mode` | 1 byte: `next_launch` 0, `on_resume` 1, `immediate` 2 |

`str` = the UTF-8 byte length as u32 big-endian, then the UTF-8 bytes. PHP: `pack('N', strlen($s)) . $s`;
u64: `pack('J', $n)`; u32: `pack('N', $n)`.

Keys and signatures:

- Algorithm: Ed25519 (RFC 8032). Ed25519 is deterministic: the same key and bytes always give
  the same signature, so the server can re-sign on every request and the ETag stays stable.
- `soar keygen` writes `soar-signing.key`: the 32-byte **seed** as 64 lowercase hex characters and a
  newline. `soar-signing.pub`: the 32-byte public key as 64 lowercase hex. PHP:
  `sodium_crypto_sign_seed_keypair(hex2bin($seed))`.
- `signature` is the 64-byte detached signature as 128 **lowercase** hex. Uppercase hex is rejected
  by the device. The response header `soar-signature` carries the same bytes in base64.
- The device uses `verify_strict` (rejects non-canonical signatures and small-order keys).
  libsodium's `sodium_crypto_sign_verify_detached` applies the same checks in practice.
- After the signature, the device also requires `rollout_percent <= 100` and `bundle_sha256` to be
  64 hex characters.

Test vector (seed `07` repeated 32 times, public key
`ea4a6c63e29c520abef5507b132ec5f9954776aebebe7b92421eea691446d22c`): the manifest
`{app_id: "dev.soar.demo", channel: "production", version: 5, min_host_ver: 29, kernels: ["storage","network","camera","network"], bundle_sha256: "00"×32, bundle_size: 123, rollout_percent: 25, apply_mode: "on_resume"}`
has the canonical bytes

```
736f61722d6f74612d6d616e69666573742d763100 0000000d 6465762e736f61722e64656d6f
0000000a 70726f64756374696f6e 0000000000000005 0000001d 00000003
00000006 63616d657261 00000007 6e6574776f726b 00000007 73746f72616765
00000040 3030…30 (64 × "0") 000000000000007b 19 01
```

and the signature
`275b64ed57d6166ee7e23d466add0604bbd9cc0a25f19fc60624d548d32e4fd66cc69f4c4f00bff9f9fd1dbf622bedd653f0ee67b8d10c8dff7315abf250ba04`.
More vectors: `fixtures/rust-vectors.json`.

### Planned: the roll-back-to-embedded directive

Not in the Rust client yet (see [client-changes.md](client-changes.md)). Signed with the same key over
`"soar-ota-directive-v1\0"`, str(`app_id`), str(`channel`), str(`"rollBackToEmbedded"`),
u64(`version`). The different magic means a directive can never pass as a manifest.

## 3. Bundle and its hash

A bundle is the `.soarb` file `soar build` writes. The server treats it as opaque bytes:
`bundle_sha256 = hash('sha256', $bytes)` (lowercase hex), `bundle_size = strlen($bytes)`. The
device refuses anything over 16 MiB (`MAX_DOWNLOAD`), so the server refuses larger uploads.
Bundles are stored once per hash (`<app_id>/blobs/<sha256>.soarb`) and never change.

## 4. Device requests

### 4.1 Static layout (current client)

The current updater polls in a background thread:

1. `GET {base}/{app_id}/{channel}/manifest.json`. No query string, no custom headers (only the HTTP
   library's defaults), 15 s timeout, redirects followed.
   - **404**: "nothing published yet", not an error. The device tries again later.
   - Any other error status: the device logs a fetch error and tries again later.
2. If `version` is above everything the device has: `GET {base}/{app_id}/{channel}/bundles/{version}-{first 16 hex of bundle_sha256}.soarb`.
3. The device verifies signature, app, channel, `min_host_ver`, kernels, version, rollout bucket,
   size and hash, stages the bundle in its inactive A/B slot and applies it per `apply_mode`.

The current client sends no `If-None-Match`; the server still sends `ETag` and answers `304` so a
CDN can revalidate cheaply. Headers:

| Response | Cache-Control | ETag |
|---|---|---|
| manifest 200/304 | `public, max-age=60, must-revalidate` (`OTA_MANIFEST_MAX_AGE`) | sha256 of the body |
| bundle 200/304 | `public, max-age=31536000, immutable` | `"<bundle_sha256>"` |
| 404 | `public, max-age=30` | |

On this server the static manifest is built per request: the newest platform-`all` update of the
channel's branch, signed for that channel. No platform or runtime filter is possible (the client
sends nothing) and a roll-back-to-embedded directive shows as 404. An export job
writes the same files to a directory for S3 or a CDN without PHP.

### 4.2 Soar Updates protocol v1 (planned client)

`GET /updates/{app_id}` with these request headers:

| Header | Required | Value |
|---|---|---|
| `soar-protocol-version` | yes | `1` |
| `soar-platform` | yes | `ios` or `android` |
| `soar-runtime-version` | yes | host version, integer (compared with `min_host_ver`) |
| `soar-channel-name` | yes | `development`, `staging` or `production` (or any channel) |
| `soar-client-id` | yes | the stable per-install device id the rollout bucket uses, at most 128 chars |
| `soar-current-update-id` | no | `version` the device runs now; omit when it runs the embedded bundle |
| `soar-embedded-update-id` | no | version of the embedded bundle, default 0 |
| `soar-app-version` | no | app version (Expo's runtime version); updates published with `app_version` need an exact match |
| `soar-ota-key` (or `x-ota-key`) | when the channel has a client key | per-tier client key |

Responses:

| Status | When | Body |
|---|---|---|
| 200 | an update | `{"type":"update","update_id":"3","manifest":{…signed manifest…},"bundle_url":"https://…/bundles/{app}/{sha256}.soarb"}` |
| 200 | roll back to embedded | `{"type":"rollBackToEmbedded","app_id":…,"channel":…,"version":5,"signature":"…"}` |
| 204 | nothing to do | empty |
| 400 | bad or missing headers | `{"error":…,"details":[…]}` |
| 401 | channel has a client key and it is missing or wrong | `{"error":…}` |
| 404 | unknown app or channel | `{"error":…}` |
| 429 | rate limit (`OTA_DEVICE_RATE_LIMIT` per IP per minute) | |

Every response has `soar-protocol-version: 1` and `Cache-Control: private, max-age=0`. 200 responses
also have `soar-signature: sig=:<base64 signature>:, keyid="main"` (Expo's `expo-signature` shape).
`update_id` is the decimal `version`: the signed manifest has no separate id.

How the server chooses (`reference/php/UpdateSelector.php`):

1. Find the channel; it points at a branch, and optionally at a second branch for a split rollout.
2. Candidates: updates on that branch for the platform or `all`, with `min_host_ver <= runtime`, and
   `app_version` null or equal to `soar-app-version`. Newest first.
3. With a split: the device takes the rollout branch when `bucket(client_id, app_id, rollout head version) < channel percent`.
4. The device gets the newest candidate whose own `rollout_percent` includes it, so in a 25%
   rollout the other 75% get the previous update instead of nothing.
5. 204 if that version is not above `soar-current-update-id`. A directive is sent only to a device
   that runs an OTA bundle (current differs from embedded) older than the directive.

The device must still verify the manifest exactly as in 4.1 (the server is not trusted), then
download `bundle_url` (immutable, CDN-cacheable, `ETag`, may redirect to S3).

## 5. Rollout bucket

```
bucket = u64_be(sha256("soar-ota-rollout\0" || str(device_id) || str(app_id) || u64_be(version))[0..8]) mod 100
```

`str` is the same length-prefixed encoding as in section 2. A device takes the release when
`bucket < rollout_percent`: 0 reaches nobody, 100 everybody. Including the version reshuffles the
canary devices on every release. PHP (`reference/php/RolloutBucket.php`) avoids unsigned 64-bit math with
`(hi mod 100) * 96 + lo mod 100`, because 2^32 mod 100 = 96.

Vectors computed by `soar_ota::rollout_bucket`:

| device_id | app_id | version | bucket |
|---|---|---|---|
| `device-1` | `dev.soar.demo` | 1 | 6 |
| `device-1` | `dev.soar.demo` | 2 | 85 |
| `device-2` | `dev.soar.demo` | 1 | 18 |
| `00000000-0000-4000-8000-000000000000` | `dev.soar.demo` | 3 | 95 |
| (empty) | `a` | 0 | 34 |
| `thiết-bị-ốc` | `ứng.dụng` | 18446744073709551615 | 94 |
| `abc` | `dev.soar.demo` | 4294967296 | 64 |
| `device-1` | `other.app` | 1 | 34 |
| `d` | `a` | 9223372036854775808 | 63 |

## 6. Rollback

Devices only move forward: a version at or below what they have is ignored. So a rollback is a
**new, higher version that carries an older bundle** (`soar rollback --to 1 --version 3` does the
same). `POST …/branches/{branch}/rollback {"to":"previous"}` or `{"to":"<group id>"}` republishes.

On the device, a new bundle runs on trial: if it does not report healthy within two launches it is
rolled back to the last good bundle and its version is blacklisted for good on that device.

Roll back to the embedded bundle needs the planned directive (section 2); the current client cannot
do it. With the current client, republish the bundle you want instead.

## 7. Channels, branches, apps, versions

- **App**: one `app_id`, one per-app `version` counter shared by all branches, one signing key
  (or one per tier, section 8).
- **Branch**: a stream of updates (`main`, `staging`, `release-2.4`, …).
- **Channel**: what a build listens to (`development`, `staging`, `production`). It points at one
  branch, optionally with a percentage split to a second branch. Pointing a channel at a branch
  whose head is older than the old branch's head republishes that head, so devices still see a
  newer version.
- **Update group**: one publish; one update per platform (`all`, or `ios` and `android`).
- **Runtime**: `min_host_ver` is a floor ("this host or newer"); `app_version` is an exact pin.

## 8. Security

Two options for who holds the signing key:

1. **Publisher signs (offline key).** CI runs `soar publish`, the key never leaves CI, the server
   only stores signed manifests. A stolen server cannot ship code. But the signed bytes include
   `channel` and `rollout_percent`, so every rollout change, channel switch or rollback needs CI to
   sign again, and a channel split cannot be decided per device.
2. **Server signs (online key, this implementation).** The server keeps the key and signs each
   response. All EAS-style operations are API calls. A compromise of the server or its key lets
   an attacker ship bundles to devices.

**Recommendation: option 2 with hardening**, because the EAS-style workflow needs it: one key per
tier (`<app_id>.<channel>.key` wins over `<app_id>.key`) so a leaked dev key cannot reach
production, key files readable only by the PHP user (or move `KeyStore` to a KMS or HSM), admin API
behind a VPN or IP allow list, CI tokens scoped to one app. Option 1 stays available: CI can sign
with `soar publish` and upload with `POST …/imports`; the server checks the signature against the
registered public key before it accepts the bundle.

Other notes: the device trusts only the public key compiled into the app, never the server; a
build without the right tier's key rejects every manifest. Device endpoints are public and rate
limited; tier client keys (`soar-ota-key`) only keep casual clients out, as they ship inside the app.
