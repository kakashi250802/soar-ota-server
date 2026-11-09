# Admin / CI API

Suggested design for the admin and CI API. Base path `/api/v1/admin`. Every request needs
`Authorization: Bearer <token>` (tokens are issued by the backend, optionally limited to one app). Responses are JSON;
validation errors are `422` with `{"message": ..., "errors": {...}}`; unknown things are `404`; a missing or bad token is `401` (a token for another app
is `403`). Each endpoint mirrors an `eas` command (see [expo-comparison.md](expo-comparison.md)).

The examples assume `API=https://ota.example.com/api/v1/admin/apps/dev.soar.demo` and
`TOKEN=<a CI token>`, `H="Authorization: Bearer $TOKEN"`.

| EAS command | Endpoint |
|---|---|
| (register an app) | `POST /apps` |
| `branch:create` | `POST /apps/{app}/branches` |
| `branch:list` | `GET /apps/{app}/branches` |
| `channel:create` | `POST /apps/{app}/channels` |
| `channel:list` / `channel:view` | `GET /apps/{app}/channels` |
| `channel:edit --branch` | `PATCH /apps/{app}/channels/{channel}` |
| `channel:rollout` | `POST /apps/{app}/channels/{channel}/rollout` |
| `channel:rollout --end` | `POST /apps/{app}/channels/{channel}/rollout/end` |
| `update` | `POST /apps/{app}/updates` (multipart) |
| (upload a release signed by `soar publish`) | `POST /apps/{app}/imports` (multipart) |
| `update:list` | `GET /apps/{app}/updates` (query `branch`, `channel`, `limit`) |
| `update:edit --rollout-percentage` | `PATCH /apps/{app}/updates/{group}` |
| `update:republish` | `POST /apps/{app}/updates/{group}/republish` |
| `update:rollback` | `POST /apps/{app}/branches/{branch}/rollback` |
| (device reports) | `GET /apps/{app}/channels/{channel}/stats` |
| (support) | `GET /apps/{app}/channels/{channel}/bucket?device_id=&version=` |

## Apps

```bash
# Let the server generate the signing key (returns the public key to ship in the app)...
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"app_id":"com.acme.app","name":"Acme","generate":true}' \
  http://localhost:8080/api/v1/admin/apps
# ...or import the key `soar keygen` wrote (the 64 hex seed). Never send a production key over plain HTTP.
curl -s -H "$H" -H 'Content-Type: application/json' \
  -d "{\"app_id\":\"com.acme.app\",\"name\":\"Acme\",\"signing_key\":\"$(cat soar-signing.key)\"}" \
  http://localhost:8080/api/v1/admin/apps
curl -s -H "$H" http://localhost:8080/api/v1/admin/apps
```

## Branches and channels

```bash
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"name":"main"}'  $API/branches            # branch:create
curl -s -H "$H" $API/branches                                                                      # branch:list
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"name":"production","branch":"main"}' $API/channels   # channel:create
curl -s -X PATCH -H "$H" -H 'Content-Type: application/json' -d '{"branch":"next"}' $API/channels/production   # channel:edit
```

`channel:edit` points the channel at another branch and ends any rollout. If the new branch's newest version is lower
than what the channel served, its newest group is republished so devices still move forward.

Channel rollout (`eas channel:rollout`): send a percentage of devices to another branch.

```bash
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"branch":"next","percent":10}' $API/channels/production/rollout
# {"name":"production","branch":"main","rollout":{"branch":"next","percent":10}}
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"branch":"next","percent":50}' $API/channels/production/rollout   # raise it
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"promote":true}' $API/channels/production/rollout/end           # finish: production now points at next
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"promote":false}' $API/channels/production/rollout/end          # abort
```

`percent` 0..100. Percent 0 keeps the rollout but sends nobody to it.

## Publish an update (`eas update`)

Multipart form. The server stores the bundle (content addressed) and signs manifests when devices ask.

| Field | Required | Meaning |
|---|---|---|
| `bundle` | yes | The `.soarb` file (at most `OTA_MAX_BUNDLE_BYTES`, 16 MiB by default). |
| `branch` | yes | Target branch. |
| `message` | no | Free text shown by `update:list`. |
| `platforms` | no | Comma list of `all` (default), `ios`, `android`. One update per platform in the group. |
| `min_host_ver` | no | Default 1. The CLI should send the value it computed from the bundle format. |
| `kernels` | no | Comma list or repeated field. |
| `apply_mode` | no | `next_launch` (default), `on_resume`, `immediate`. |
| `rollout_percent` | no | 0..100, default 100. |

```bash
curl -s -H "$H" \
  -F bundle=@app.soarb -F branch=main -F message="Fix login" -F min_host_ver=29 \
  -F kernels=storage,network -F apply_mode=on_resume -F rollout_percent=25 \
  $API/updates
```

```json
{
  "group_id": "ed040ce2-f5be-413a-ab26-f7f66bcf3698",
  "branch": "main", "message": "Fix login", "created_by": "demo", "created_at": "2026-11-09T07:28:40+00:00",
  "updates": [{
    "id": "3e3a9761-...", "version": 3, "platform": "all", "kind": "bundle", "min_host_ver": 29,
    "apply_mode": "on_resume", "rollout_percent": 25, "kernels": ["storage", "network"],
    "bundle_sha256": "0cab2845...75b7", "bundle_size": 690, "republished_from": null
  }]
}
```

Versions come from one counter per app; every update in a group gets the next number.

### Upload a release that was already signed (`soar publish` output)

For teams that keep the key in CI (see [security.md](security.md)): upload the `manifest.json` and bundle that
`soar publish` wrote. The server rejects the upload (`422`) unless the manifest parses, is for this app, its signature
verifies with the app's **registered public key**, and the bundle matches `bundle_size` and `bundle_sha256`.

```bash
soar publish app.soar --key soar-signing.key --out dist --app com.acme.app --version 7 --channel production
curl -s -H "$H" -F manifest=@dist/com.acme.app/production/manifest.json \
  -F bundle=@dist/com.acme.app/production/bundles/7-<sha16>.soarb -F branch=main \
  http://localhost:8080/api/v1/admin/apps/com.acme.app/imports
```

The release is then served like any other update (the manifest is signed for each channel at request time). The version
in the uploaded manifest is only provenance; the server assigns its own.

## List, edit, republish, roll back

```bash
curl -s -H "$H" "$API/updates?channel=production&limit=10"          # update:list (newest first); or ?branch=main
curl -s -X PATCH -H "$H" -H 'Content-Type: application/json' -d '{"rollout_percent":100}' $API/updates/$GROUP   # update:edit
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"branch":"staging","message":"Promote"}' $API/updates/$GROUP/republish
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"channel":"production"}' $API/updates/$GROUP/republish   # onto the channel's branch
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"to":"previous","message":"bad release"}' $API/branches/main/rollback
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"to":"<group id>"}' $API/branches/main/rollback
curl -s -H "$H" -H 'Content-Type: application/json' -d '{"to":"embedded"}'  $API/branches/main/rollback
```

- `republish` copies the group's updates onto a branch as **new** updates with new versions (the blob is shared).
- `rollback` `to: previous | <group id>` is a republish onto the same branch: devices see a higher version that carries
  the older bundle. `to: embedded` publishes a `rollback_to_embedded` directive (Expo-style protocol only).
- `PATCH` on a group changes the rollout percentage of all its updates; `0` halts it. Manifests are re-signed on the next
  request, so no other step is needed.

## Reports

```bash
curl -s -H "$H" $API/channels/production/stats
# {"app_id":"dev.soar.demo","channel":"production","versions":[{"version":3,"installed":120,"healthy":112,"rolled_back":4,"rollback_rate":0.0333}]}
```

Counts are distinct devices per version. See [device-reports.md](device-reports.md) for what devices send.

## Exporting a static copy

An export job (suggested) writes `<app>/<channel>/manifest.json` and `bundles/` for every channel, signed,
in the exact layout of `soar publish`; sync it to S3 or a CDN origin to serve the current client with no PHP at all.
Rerun it after each change.
