# Architecture

This is the design the demo was built on, kept as a suggestion: the team chooses its own stack. The paths below
describe a suggested layout; [reference/php](../reference/php) holds the protocol classes it names.

## Suggested stack: Laravel

Laravel 12 (PHP 8.2+). Reasons:

- The job is database-backed CRUD plus file storage plus tokens, tests and migrations. Laravel ships all of it: migrations,
  Eloquent, request validation, the filesystem abstraction (local disk, S3 via Flysystem), rate limiting, console commands,
  and a test harness with an in-memory SQLite. With Slim the team would assemble and maintain each piece.
- A PHP backend team is likely to know Laravel; onboarding cost is lowest.
- The protocol logic (manifest, signing, rollout bucket, update selection) is plain PHP under `app/Ota/`, with no framework
  types, so it ports to Slim or any other framework by moving the thin controllers.

## Layout

```
app/Ota/                  protocol logic, no HTTP
  Manifest.php            fields, canonical bytes, sign/verify  (byte-compatible with the Rust code)
  KeyPair.php, Hex.php    sodium ed25519 wrappers, lowercase hex
  RolloutBucket.php       port of soar_ota::rollout_bucket
  Directive.php           signed roll-back-to-embedded directive (planned client support)
  UpdateSelector.php      channel -> branch -> update, rollouts, platform and runtime filters
  ManifestFactory.php     builds + signs the manifest a device gets
  ReleaseService.php      publish, republish, rollback, channel edits (one method per EAS command)
  KeyStore.php            where signing seeds live (file per app; swap for a KMS)
  BundleStore.php         content-addressed blobs on a Laravel disk (local or S3)
app/Http/Controllers/
  Device/                 StaticLayoutController (legacy), ProtocolController (Expo-like), BundleController, ReportController
  Admin/                  Apps, Branches, Channels, Updates, Stats
app/Http/Middleware/AuthenticateAdminToken.php
app/Models/               SoarApp, Branch, Channel, UpdateGroup, Update, DeviceReport, ApiToken
app/Console/Commands/     ota:app:create, ota:token:create, ota:export-static
routes/device.php         public routes (no session), routes/api.php  admin routes
database/migrations/      one migration; MySQL, Postgres or SQLite
fixtures/                 demo bundles and signed manifests built with the real Soar CLI
tests/                    PHPUnit, checking against fixtures/
tools/rust-check/         Rust programs that run the real engine code against this server
```

## Data model

```
apps ──< branches ──< updates >── update_groups
  │          ▲
  └──< channels (branch_id, rollout_branch_id, rollout_percent)
device_reports (app_id, channel, device_id, event, version, ...)      api_tokens (hash, optional app)
```

- `apps.last_version`: the per-app counter. Every new update takes the next number, under a row lock.
- `updates`: `version` (unique per app), `platform` (`all|ios|android`), `kind` (`bundle|rollback_to_embedded`),
  `min_host_ver`, `kernels`, `apply_mode`, `rollout_percent`, `bundle_sha256`, `bundle_size`, `republished_from_id`.
- Bundles are stored once per hash at `<app_id>/blobs/<sha256>.soarb`. A republish or rollback is database rows only.
- Manifests are **not stored**: they are built and signed from `updates` when a device asks (deterministic signatures keep
  ETags stable). Imported signed manifests are verified and then treated as ordinary updates.

## Request paths

```
device (current client) --GET /{app}/{channel}/manifest.json--> StaticLayoutController --UpdateSelector--> ManifestFactory(sign)
device (planned)        --GET /updates/{app} + soar-* headers--> ProtocolController   --UpdateSelector--> ManifestFactory(sign) / 204
both                    --GET bundle--------------------------> BundleResponder (ETag, immutable, optional S3 redirect)
CI                      --POST /api/v1/admin/...--------------> Admin controllers --> ReleaseService
```

## Extension points for the team

- **KMS signing:** implement the two methods `KeyStore` offers (`forApp` returns a `KeyPair`) behind an interface that
  exposes `sign(bytes)` instead of the seed, and use it in `ManifestFactory`.
- **Auth:** replace `AuthenticateAdminToken` with SSO or Sanctum; add roles; add an audit table (`ReleaseService` is the
  single write path, so one hook there covers every change).
- **CDN:** put it in front. The static routes are `public` with ETags; the Expo-style route is `private`, only bundles cache.
- **Reports:** `device_reports` is append-only and indexed for the stats query; move it to a time-series store or add
  retention (`DELETE ... WHERE created_at < ...`) when volume grows.
- **Kernels/platform filters:** `UpdateSelector::head` is the only place that decides who gets what.
