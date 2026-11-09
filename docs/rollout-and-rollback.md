# Rollout, rollback, channels and host versions

Sources: `eligibility.rs`, `launch.rs` and `publish.rs` in `crates/soar-ota`.

## What a device checks, in order (`check_eligibility`)

1. Signature valid for the public key shipped in the app, `rollout_percent <= 100`, `bundle_sha256` is 32 bytes of hex.
2. `app_id` equals the device's app.
3. `channel` equals the channel the install follows.
4. `min_host_ver <= host version`; otherwise `HostTooOld`.
5. Every entry of `kernels` is compiled into the host; otherwise `MissingKernels`.
6. `version` is not in the device's blacklist (versions that failed their trial).
7. `version` is **above** the newest version the device has in any form (embedded, good, trial, pending).
8. The rollout bucket is below `rollout_percent`.

After a manifest passes, the bundle is downloaded and its length and SHA-256 must match the manifest. Then it is written
to the inactive A/B slot and becomes *pending*. A new bundle is not staged while a trial is in progress.

## Rollout bucket

`rollout_bucket(device_id, app_id, version)` returns 0..99. A device takes the release when
`bucket < rollout_percent`: 0 means nobody, 100 means everybody, 25 means the devices whose bucket is 0..24.

Algorithm:

1. Hash with SHA-256 the concatenation of
   - the ASCII string `soar-ota-rollout` plus a NUL byte,
   - `device_id`: u32 big-endian byte length, then UTF-8 bytes,
   - `app_id`: same,
   - `version`: u64 big-endian.
2. Read the first 8 bytes of the digest as a big-endian u64 `n`.
3. `bucket = n mod 100`.

The version is part of the hash, so each release reshuffles which devices are in the first 5%; the same devices are not
always the canaries. `device_id` is a stable per-install identifier that never changes between launches.

PHP (`reference/php/RolloutBucket.php`); `n` can exceed PHP's signed int range, so reduce it from two 32-bit halves
(2^32 mod 100 = 96):

```php
$digest = hash('sha256', "soar-ota-rollout\0" . $str($deviceId) . $str($appId) . pack('J', $version), true);
['hi' => $hi, 'lo' => $lo] = unpack('Nhi/Nlo', $digest);
$bucket = (($hi % 100) * 96 + ($lo % 100)) % 100;
```

Test vectors, computed by `soar_ota::rollout_bucket` (`fixtures/rust-vectors.json`):

| device_id | app_id | version | bucket |
|---|---|---|---|
| `device-1` | `dev.soar.demo` | 1 | 6 |
| `device-1` | `dev.soar.demo` | 2 | 85 |
| `device-2` | `dev.soar.demo` | 1 | 18 |
| `00000000-0000-4000-8000-000000000000` | `dev.soar.demo` | 3 | 95 |
| `` (empty) | `a` | 0 | 34 |
| `thiết-bị-ốc` | `ứng.dụng` | 18446744073709551615 | 94 |
| `abc` | `dev.soar.demo` | 4294967296 | 64 |
| `device-1` | `other.app` | 1 | 34 |
| `d` | `a` | 9223372036854775808 | 63 |

The last two u64 rows are above `PHP_INT_MAX`; the PHP tests check the others.

The server uses the same function in two places: to answer 204 to a device outside a rollout on the Expo-style endpoint,
and for `GET /api/v1/admin/apps/{app}/channels/{channel}/bucket?device_id=...&version=...`, a support helper that tells
you why a device did or did not get an update.

## Rollback: a new, higher version

Devices only move forward (check 7). So "rolling back" never lowers a version:

- **Republish the old bundle under a new, higher version.** `soar rollback --to 1 --version 3` writes manifest version 3
  that points at the bundle of version 1 (a different file name, `3-<sha16>.soarb`, same bytes and hash). A device on
  version 2 sees 3 > 2 and installs it. This server does the same: `update:rollback --to previous` / `--to <group>`
  creates new updates (new versions) that carry the old bundle's hash and size; the blob is stored once.
- **Halting a rollout:** set `rollout_percent` to 0 (`soar rollout --percent 0`; here `PATCH .../updates/{group}`). Devices
  that already installed it keep it; new devices do not take it.
- **Device-side automatic rollback:** a freshly applied bundle is a *trial*. If it starts twice (`MAX_TRIAL_STARTS = 2`)
  without calling `mark_healthy`, or the host fails to load it, the device returns to the last good bundle (or the embedded
  one) and **blacklists that version forever**. The server cannot see this unless the device reports it (see
  [device-reports.md](device-reports.md)).
- **Roll back to embedded:** the static layout cannot do it (there is no version below the embedded one to ship).
  The Expo-style protocol adds a signed directive; see [device-protocol.md](device-protocol.md). Until the client supports
  it, publish the embedded bundle's source as a new update instead.

Ordering rule: the server assigns versions from one counter per app, shared by all branches, so versions are comparable
across branches. When a channel is repointed to a branch whose newest version is lower than the one it was serving, the
server republishes that branch's newest group so it is higher again (otherwise devices would ignore it).

## Channels, branches, apps

- **App** (`app_id`): one Soar app with its own key pair and version counter. One server hosts many apps; URLs and
  tables are keyed by `app_id`. Pattern `[A-Za-z0-9][A-Za-z0-9._-]{0,127}`.
- **Channel**: what an installed build listens to (`production`, `staging`, `dev`). The build bakes its channel in; it
  is the `channel` in the manifest and in the static URL.
- **Branch**: a stream of updates, like a git branch. A channel points at one branch and can send a percentage of devices
  to a second branch (`channel:rollout`). Names: `[A-Za-z0-9][A-Za-z0-9._-]{0,63}`.
- **Update group**: one publish. It holds one update per platform (`ios`, `android`) or one update for `all`.
- A manifest is signed **per channel** at request time, so one branch can feed several channels.

In the static layout of `soar publish`, a channel and a branch are the same thing (the directory `<app>/<channel>/`).
That is why `POST .../imports` creates a branch named after the manifest's channel by default.

## `min_host_ver` and Expo's runtime version

Expo's runtime version is a string that must match **exactly**: an update only goes to builds with the same runtime
version. Soar's `min_host_ver` is a **floor**: the update runs on any host at or above it (plus having the kernels). The
server therefore maps `soar-runtime-version` (the host version) to "newest update with `min_host_ver <= runtime`".
Consequences:

- Old hosts keep receiving the newest update they can run, instead of nothing.
- To cut off old hosts from a breaking bundle, raise `min_host_ver` on the new update.
- Rolling back across a `min_host_ver` boundary works naturally: a host below the floor is offered an older update.
- The CLI raises `min_host_ver` to the `.soarb` format version of the bundle automatically.
