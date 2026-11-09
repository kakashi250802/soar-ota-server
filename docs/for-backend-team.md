# Overview for the backend team

This repository is the hand-off for the Soar OTA server: what it must do, the exact rules the apps rely on, a minimal
working PHP example, and the design of the full service, modelled on Expo Updates / EAS Update. The team builds the
production server; nothing here needs to run as it is.

Start with [php-guide.md](php-guide.md).

## Concepts (as in Expo)

| Concept | Meaning |
|---|---|
| Channel | What a build listens to: `development`, `staging`, `production`. |
| Branch | A stream of updates. A channel points at a branch and can split a share of devices onto a second branch. |
| Update group | One publish: one update per platform (`ios`, `android`), or one for both. |
| Runtime version | `min_host_ver`: the oldest engine (host) that can run the bundle. Optionally pinned to an app version. |
| Rollout percentage | The share of devices that take an update; the others keep the previous one. |
| Republish / rollback | Publish an earlier bundle again under a new, higher version; or send devices back to the bundle built into the app. |

Full comparison: [expo-comparison.md](expo-comparison.md).

## Rules that must not change

1. **ed25519 signatures.** Devices trust only the public key built into the app. The signed bytes are a fixed
   binary string, not the JSON ([manifest-and-signing.md](manifest-and-signing.md)). Do not change their order or
   encoding.
2. **Versions only grow.** A device ignores any version that is not above the one it runs. A rollback is a new,
   higher version carrying the old bundle.
3. **The static paths.** `/{app}/{channel}/manifest.json` and `/{app}/{channel}/bundles/{version}-{sha16}.soarb` are
   what today's client reads.

## Two device protocols

- **Static layout** (today's client): `GET manifest.json`; `404` means nothing is published. ETag and `304`; a CDN can
  cache it. The minimal example implements exactly this.
- **Soar Updates protocol v1** (next client): `GET /updates/{app}` with `soar-platform`, `soar-runtime-version`,
  `soar-channel-name`, `soar-current-update-id` and related headers; `200` with an update, `204` with none
  ([device-protocol.md](device-protocol.md)).

## Documents

| Document | Contents |
|---|---|
| [php-guide.md](php-guide.md) | Step by step in PHP, from the minimal server to the full service |
| [protocol.md](protocol.md) | The whole protocol in one place |
| [manifest-and-signing.md](manifest-and-signing.md) | Manifest fields, canonical bytes, keys, bundle hash |
| [rollout-and-rollback.md](rollout-and-rollback.md) | Rollout bucket, republish, rollback |
| [device-protocol.md](device-protocol.md) | Device requests: static layout and the Expo-like protocol |
| [api.md](api.md) | Admin and CI API, one endpoint per `eas` command |
| [architecture.md](architecture.md) | Suggested modules, data model and request paths |
| [security.md](security.md) | Key custody, tokens, what the server must never do |
| [device-reports.md](device-reports.md) | Install, health and rollback reports from devices |
| [client-changes.md](client-changes.md) | What the Soar client will change to use the Expo-like protocol |
| [soar-cli-integration.md](soar-cli-integration.md) | How `soar publish` / `soar update` will upload to the server |
| [ota-release-runbook.md](ota-release-runbook.md) | Releasing, rolling out and rolling back, step by step |
| [expo-comparison.md](expo-comparison.md) | Expo concepts and endpoints against their Soar counterparts |

## Decisions for the team

1. Key custody: a key file on the server, a KMS/HSM, or signing only in CI (the server accepts signed manifests)?
2. Should production channels require a client key (`soar-ota-key`)? Today's client cannot send one.
3. Reports: store the raw device id or a hash, and for how long?
4. Admin access: per-user tokens and an audit log of rollout and rollback changes?
5. Cleanup of old bundles (they are never deleted in the design).
6. One server for every deployment (dev, UAT, production), or one per deployment?
7. `version` up to 2^63 - 1 in PHP integers, where Rust allows 2^64 - 1. A counter never gets there.
