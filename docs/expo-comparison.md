# Expo Updates / EAS Update compared with Soar OTA

The server keeps Soar's own formats (ed25519 manifests, `soar-*` headers) but copies Expo's
concepts and EAS's commands, and the behaviour of the reference app, which today runs
`expo-updates` against a PHP endpoint on each deployment.

## Concepts

| Expo | Soar OTA | Same / different |
|---|---|---|
| Runtime version | `min_host_ver` (host binary floor) and optional `app_version` (exact pin) | Expo needs an exact match. Soar's `min_host_ver` is "this host or newer", and also checks native kernels. Publish with `app_version` to get Expo's exact behaviour. |
| Channel | Channel (`development`, `staging`, `production`) | Same. The build sends it (`soar-channel-name`); the signed manifest names it too. |
| Branch | Branch | Same. |
| Update group | Update group | Same: one publish, one update per platform. Soar adds platform `all` because a `.soarb` runs on both. |
| Update id (UUID) | `version` (per-app integer) | Different: Soar devices compare versions and only move forward, so ids are ordered. Groups and updates also have UUIDs in the admin API. |
| Channel rollout between branches | `POST …/channels/{c}/rollout` | Same idea; bucket = Soar's `rollout_bucket` on the device id. |
| Rollout percentage per update | `rollout_percent` (signed) | Same; devices outside get the previous update. |
| Republish | Republish | Same, but always as a new higher version. |
| Roll back to embedded | `rollBackToEmbedded` directive | Same name and meaning; needs the planned client change. |
| Code signing (`expo-signature`, RSA, certificate) | ed25519 over fixed bytes, `signature` field + `soar-signature` header | Different algorithm and key format; same purpose. No certificate chain: the app ships the raw public key. |
| Multipart manifest response | Single JSON object | Different shape; the manifest is the same object the static layout serves. |
| `expo-platform`, `expo-runtime-version`, `expo-channel-name`, `expo-current-update-id`, `expo-embedded-update-id`, `expo-protocol-version` | `soar-platform`, `soar-runtime-version`, `soar-channel-name`, `soar-current-update-id`, `soar-embedded-update-id`, `soar-protocol-version` | Same roles. Soar adds `soar-client-id` (rollout bucket) and `soar-app-version`. |
| 204 "no update" (protocol v1) | 204 | Same. |
| Assets | One `.soarb` bundle | Soar ships one file; no asset list. |
| Static hosting | Static layout `<app>/<channel>/manifest.json` | Soar's current client reads only this; Expo has no equivalent. |

## EAS commands and endpoints

All under `/api/v1/admin/apps/{app}`, `Authorization: Bearer <token>`.

| EAS command | Endpoint |
|---|---|
| `eas update --branch B --message M` | `POST updates` (multipart: `bundle`, `branch`, `message`, `platforms`, `min_host_ver`, `app_version`, `kernels`, `apply_mode`, `rollout_percent`) |
| `eas update:list` | `GET updates?branch=B` or `?channel=C` |
| `eas update:edit --rollout-percentage` | `PATCH updates/{group}` `{"rollout_percent":50}` |
| `eas update:republish --group G` | `POST updates/{group}/republish` `{"branch":B}` or `{"channel":C}` |
| `eas update:rollback` | `POST branches/{branch}/rollback` `{"to":"previous" \| "<group>" \| "embedded"}` |
| `eas branch:list` / `branch:create` | `GET branches` / `POST branches` |
| `eas channel:create` | `POST channels` `{"name","branch","client_key"?}` |
| `eas channel:edit --branch` | `PATCH channels/{channel}` `{"branch":B}` |
| `eas channel:rollout` | `POST channels/{channel}/rollout` `{"branch":B,"percent":10}`; end with `POST channels/{channel}/rollout/end` `{"promote":true}` |
| (signed release from CI) | `POST imports` (multipart `manifest` + `bundle` from `soar publish`) |

## Behaviours mirrored from the reference app

| Reference app (expo-updates + PHP endpoint) | Soar OTA | Status |
|---|---|---|
| One channel per environment in a request header: development, staging (UAT builds use it too), production | Channels `development`, `staging`, `production` (seeded); header `soar-channel-name` | Server done; current client sends the channel in the URL path instead |
| Per-tier client key header (`x-ota-key`); 401 without it | Channel `client_key` (stored as sha256); header `soar-ota-key` (or `x-ota-key`); 401 when missing or wrong | Server done for `/updates`. The static layout cannot check it (the current client sends no headers) |
| Runtime version = app version; an update reaches only builds of the same version | `app_version` on the update + `soar-app-version` header, exact match | Server done; client must send the header |
| Check on every launch without blocking start-up; download in the background; run on next launch, no hot swap by default | Rust poller thread downloads; default `apply_mode` is `next_launch` | Client already works this way; keep `next_launch` as the publish default |
| Signed manifests per tier (dev, uat, prod keys); a build without the tier's public key never accepts updates | One key per tier: `<app_id>.<channel>.key` on the server; the build ships that tier's public key | Server done; release process in [ota-release-runbook.md](ota-release-runbook.md) |
| Rollback and republish | `update:rollback`, `update:republish` | Done (embedded rollback needs the client change) |

## What is different on purpose

- Soar devices never go back to a lower version. Rollback, republish and channel switches always
  create a new version. This also protects against a replayed old manifest.
- The device runs a new bundle on trial and rolls it back by itself after two failed launches.
  Expo has an "error recovery" feature, but Soar also blacklists the bad version.
- The server signs per channel, so the same bundle can move from staging to production by
  pointing a channel at a branch, with no rebuild or re-sign in CI.
