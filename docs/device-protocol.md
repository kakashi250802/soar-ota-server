# Device protocol

Two ways for a device to ask for updates. The server speaks both from the same data.

| | Static layout ("legacy", what the Soar client does today) | Expo-style protocol (planned client change) |
|---|---|---|
| URL | `GET /{app}/{channel}/manifest.json`, then `GET /{app}/{channel}/bundles/{version}-{sha16}.soarb` | `GET /updates/{app}`, then `GET {bundle_url}` |
| Device identity | none | `soar-client-id` header |
| Selection | one manifest per channel; the **device** decides eligibility | the **server** decides and answers 204 when there is nothing new |
| Caching | CDN-friendly (ETag, `public`) | per device (`private`), only the bundle is cached |
| Client status | implemented (`crates/soar-ota/src/updater.rs`) | not implemented; see [client-changes.md](client-changes.md) |

## 1. Static layout (current client)

What `updater.rs` does, exactly (`Poller::spawn` with an `HttpFetch`):

1. Every poll interval (chosen by the host app), `GET {base}/{app_id}/{channel}/manifest.json`.
   - No query parameters. No custom headers: only the defaults of the `ureq` HTTP client. It sends **no**
     `If-None-Match`, so it never receives a 304 itself; a CDN in front may use ETags toward the origin.
   - 15 second timeout, redirects followed (ureq default), response capped at 16 MiB.
2. If the manifest `version` is not above the newest version already known, stop (no bundle request).
3. Otherwise `GET {base}/{app_id}/{channel}/bundles/{version}-{first 16 hex of bundle_sha256}.soarb`
   (`publish::bundle_path`).
4. Hand manifest and bundle to the main thread, which verifies the signature, checks eligibility, size and hash, and
   stages the bundle (see [rollout-and-rollback.md](rollout-and-rollback.md)).

Status codes the client understands:

| Response | Client behavior |
|---|---|
| 200 | Use the body. |
| 404 on the manifest or bundle | "Nothing published yet": not an error, silently tries again next poll. Any other file-not-found is treated the same. |
| 3xx | Followed (so a bundle URL may redirect to S3 or a CDN). |
| anything else, network error, timeout, over 16 MiB | Reported to the app as a fetch error; next poll retries. |

Server side, the suggested design serves both URLs from the database (a static-layout controller); the minimal
example serves them from files:

- `GET /{app}/{channel}/manifest.json`
  - `200`, `Content-Type: application/json`, body = the signed manifest for that channel.
  - `ETag: "<sha256 of the body>"`, `Cache-Control: public, max-age=60, must-revalidate` (`OTA_MANIFEST_MAX_AGE`).
  - `If-None-Match` matching the ETag (weak or strong, lists, `*`) gives `304` with the same headers and no body.
  - `404` JSON when the app or channel is unknown or nothing is published for it.
- `GET /{app}/{channel}/bundles/{version}-{sha16}.soarb`
  - `200`, `application/octet-stream`, `ETag: "<full sha256>"`, `Cache-Control: public, max-age=31536000, immutable`.
  - `304` on a matching `If-None-Match`; `404` when unknown. With `OTA_BUNDLE_REDIRECT=true` and an S3 disk the answer is
    a `302` to a presigned URL.

What the static layout cannot express, and how the server maps it:

- **Channels and branches.** The URL carries the channel. The server resolves the channel to its branch's newest
  update and signs the manifest for exactly that channel name (the manifest `channel` must equal the device's channel).
- **Platform.** The client sends none, so only updates published for `all` platforms are offered.
- **Runtime/host version.** The client does not send it; the device checks `min_host_ver` itself. No filtering.
- **Channel rollout between two branches.** The client cannot be split per device by the server. The server offers the
  rollout branch's newest update with `rollout_percent = min(update percent, channel percent)` and lets the device's
  own bucket check apply it. Devices outside the percentage stay where they are.
- **Roll back to embedded.** No legacy form; the old client sees nothing new (`404`).
- The manifest is **signed at request time** with the app's key (deterministic, so the ETag is stable). Alternatively
  an export job can write the same files to disk and host them on S3 or any CDN with no PHP
  in the request path.

## 2. Expo-style protocol (planned client change)

Modelled on the Expo Updates protocol (<https://docs.expo.dev/technical-specs/expo-updates-1/>): one URL per app, the
device describes itself in request headers, the server answers with the update to take or `204 No Content`.

```
GET /updates/{app_id}
soar-protocol-version: 1
soar-platform: ios                    ios | android
soar-runtime-version: 29              host version (integer), compared with the update's min_host_ver
soar-channel-name: production
soar-client-id: 9f1c...               stable per-install id; the same device_id the rollout bucket uses
soar-current-update-id: 3             optional: version of the OTA bundle running now (omit for the embedded bundle)
soar-embedded-update-id: 0            optional: version of the bundle embedded in the binary (default 0)
```

| Expo | Soar | Note |
|---|---|---|
| `expo-protocol-version` | `soar-protocol-version` | `1` |
| `expo-platform` | `soar-platform` | |
| `expo-runtime-version` | `soar-runtime-version` | Soar compares as a floor (`min_host_ver <= runtime`), Expo needs equality. |
| `expo-channel-name` | `soar-channel-name` | |
| `expo-current-update-id` | `soar-current-update-id` | A version number, not a UUID: the signed manifest has no separate id. |
| `expo-embedded-update-id` | `soar-embedded-update-id` | |
| `EAS-Client-ID` | `soar-client-id` | Required: the server needs it for rollouts. |
| `expo-expect-signature` | (always signed) | |

Responses (all carry `soar-protocol-version: 1` and `Cache-Control: private, max-age=0`):

**204 No Content**, empty body: nothing new for this device. Reasons: no update on the channel; the device is outside
every rollout; the newest eligible update is not above `soar-current-update-id`; or a roll-back directive does not
apply (the device already runs the embedded bundle).

**200 update**:

```
HTTP/1.1 200 OK
Content-Type: application/json
soar-protocol-version: 1
soar-signature: sig=:MOR3QPXk0H5O35KmfMMDU2zkxWFp+KMb4bXw7+suM6UuHT1as1VhMpDS2qT3m65Md22GtRcPnQGQYBwWh7pbAw==:, keyid="main"

{
  "type": "update",
  "update_id": "1",
  "manifest": { ...the signed manifest, exactly as in manifest-and-signing.md, channel = soar-channel-name... },
  "bundle_url": "https://updates.example.com/bundles/dev.soar.demo/3a5255f4...8a64.soarb"
}
```

- `manifest` is byte-for-byte the existing signed manifest, so the client keeps its verification code: it parses it,
  runs `verify` with the shipped public key, and checks eligibility, then size and SHA-256 of the downloaded bundle.
- `soar-signature` repeats the manifest signature in Expo's structured-field form (`sig=:<base64>:, keyid="main"`),
  where the base64 is the 64 raw signature bytes (the manifest carries the same bytes as hex). A client may ignore the
  header and rely on the in-body signature.
- `bundle_url` is absolute and may point at a CDN (`OTA_PUBLIC_URL`). Bundles are content addressed
  (`/bundles/{app}/{sha256}.soarb`), immutable, `public, max-age=31536000, immutable`, with a strong ETag.
- `update_id` is the decimal `version`.

**200 roll-back-to-embedded directive** (Expo's `rollBackToEmbedded`):

```json
{
  "type": "rollBackToEmbedded",
  "app_id": "dev.soar.demo",
  "channel": "production",
  "version": 5,
  "signature": "9326...7100"
}
```

Sent only to a device that runs an OTA bundle (`soar-current-update-id` set and different from the embedded id) older
than the directive's `version`. The signature is ed25519 over a **new** canonical string, with its own domain
separator so a directive can never be replayed as a manifest: `"soar-ota-directive-v1\0"`, then `app_id`, `channel`
and the type string `rollBackToEmbedded` as u32-length-prefixed UTF-8, then `version` as u64 big-endian. After
applying it the device runs the embedded bundle and sends `soar-current-update-id` equal to the embedded id, which
gets `204`. Defined here, not yet in the Rust client.

**400** JSON error when a required header is missing or malformed. **404** JSON for an unknown app or channel.

Selection, in order (`UpdateSelector`, `ProtocolController`):

1. Channel -> branch. If the channel has a rollout, the device is in the split when
   `rollout_bucket(client_id, app, newest rollout-branch version) < channel percentage`, and then reads the rollout
   branch, otherwise the channel's branch.
2. On that branch take the newest update for this platform (`ios`/`android` or `all`) whose `min_host_ver <=
   runtime version`, **skipping updates whose own rollout excludes this device** (same bucket function as the device).
   A 25% rollout therefore leaves the other 75% on the previous update, not on nothing.
3. If that update is not above `soar-current-update-id`: 204. Otherwise 200 with the manifest signed for the requested
   channel with the update's `rollout_percent`.

Kernels are not sent, so the server cannot filter on them; the device still checks them and refuses the update when a
kernel is missing. See open questions in the README.
