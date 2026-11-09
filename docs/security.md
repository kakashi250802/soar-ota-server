# Security and signing keys

Devices trust one thing: the ed25519 public key compiled into the app. Whatever can produce a signature that verifies
under it can ship code to every device. Protect the signing key accordingly.

## Who holds the signing key

**Option A: publisher-signed (recommended for production).** The key lives with CI or the release engineer. `soar
publish` signs the manifest and writes it; the server only *stores* an already-signed manifest and bundle and verifies
the signature against the app's registered public key before accepting it (`POST /api/v1/admin/apps/{app}/imports`,
sodium `sodium_crypto_sign_verify_detached`). A completely compromised server (database, storage, API tokens) can then
withhold or replay updates but cannot ship new code.

Its cost: the signature covers `channel`, `rollout_percent` and `apply_mode`, so every change to one of them (a rollout
step, a rollback, serving the same bundle on another channel, a branch rollout) needs a new signature from the key
holder. That is fine for the static layout (`soar rollout` re-signs) but does not fit server-driven features such as
channel-to-branch pointers and per-device rollouts, where the server must produce a signed manifest per request.

**Option B: server-signed (what this reference server does by default).** The server holds the private seed per app
(`OTA_KEYS_DIR/<app_id>.key`) and signs each manifest when it serves it (Expo's code signing works this way too). Admin
calls upload bundles only. This is what makes branches, channel rollouts, republish and rollback to embedded possible
with one API call each. The cost: whoever can read the key or call the signing code can ship code to devices.

**Recommendation.**

1. Production apps: keep the signing key out of the web tier. Put it in a KMS/HSM that signs but never exports
   (check that yours supports ed25519 signing; Vault Transit and Google Cloud KMS do, and the cloud list keeps growing), behind the `KeyStore` class (`sign(bytes)` and `public key`
   are all the server needs). Server-signing with a KMS is the practical way to get the Expo-like features.
2. If the team cannot use a KMS, use option A for production and keep option B for dev and staging channels.
3. Never put a production key in Docker images, git or `.env`. The committed demo key (`fixtures/`) is a throwaway that
   only signs demo data.
4. Rotating a key means shipping a new app build with the new public key; devices verify against exactly one key today.
   A client change for a key set (`keyid`) is tracked in [client-changes.md](client-changes.md).

## Other points

- **Admin API tokens.** Bearer tokens, stored as SHA-256 only, shown once when issued.
  A token can be limited to one app. Use HTTPS, one token per CI system, rotate by creating a new token and deleting the
  old row. Tokens are not user accounts; add real auth (SSO, roles, an audit table) before human admins use this.
- **Device endpoints are public.** They are rate limited per IP (`OTA_DEVICE_RATE_LIMIT`). `POST /v1/reports` accepts
  anonymous data from anyone who knows an app id, so treat reports as advisory, never as an input to decisions.
- **Replay and downgrade.** Versions only move forward on devices, and manifests bind `app_id`, `channel` and the bundle
  hash, so an old manifest cannot be moved to another channel or app. A manifest of an old version is ignored by devices
  already past it. A freeze attack (a mirror that serves an old manifest forever) is not covered: no expiry field exists.
- **Transport.** Serve over HTTPS. Signatures protect the bundle even on plain HTTP, but TLS keeps metadata private
  and avoids downgrade by on-path attackers.
- **Uploads.** Size capped, content addressed (stored under its SHA-256, never overwritten); the server never executes or
  parses a bundle.
- **Privacy.** `device_id` is a per-install random id. Store as received or hash it with a server secret; do not link it
  to accounts. Reports hold no other personal data.
- **Key files.** `KeyStore` writes seeds with mode 0600. Back them up separately from the database dumps.
