# OTA release runbook

For the backend team operating the server. Commands use `curl`; set these first:

```sh
export OTA=https://ota.example.com            # or http://localhost:8080 for the demo
export APP=dev.soar.demo
export T="Authorization: Bearer $SOAR_OTA_TOKEN"
export API=$OTA/api/v1/admin/apps/$APP
```

## One-time setup per app

1. Create one signing key per tier with the Soar CLI (on a trusted machine):
   `soar keygen --out keys/dev`, `soar keygen --out keys/uat`, `soar keygen --out keys/prod`.
2. Register the app with the production key (it becomes the app's default):
   `curl -H "$T" -H 'Content-Type: application/json' -d "{\"app_id\":\"$APP\",\"name\":\"My app\",\"signing_key\":\"$(cat keys/prod/soar-signing.key)\"}" $OTA/api/v1/admin/apps`
3. Install the per-tier keys on the server as `$OTA_KEYS_DIR/$APP.development.key` and
   `$APP.staging.key` (mode 600, owner = PHP user). A tier file wins over `$APP.key`.
4. Give the mobile team each tier's `soar-signing.pub`; each build flavour ships its own.
5. Create branches and channels:
   ```sh
   for b in main staging development; do curl -H "$T" -H 'Content-Type: application/json' -d "{\"name\":\"$b\"}" $API/branches; done
   curl -H "$T" -H 'Content-Type: application/json' -d '{"name":"production","branch":"main","client_key":"<prod client key>"}' $API/channels
   curl -H "$T" -H 'Content-Type: application/json' -d '{"name":"staging","branch":"staging","client_key":"<uat client key>"}' $API/channels
   ```
6. Create a CI token limited to the app (issued by the backend's admin tooling).
   Store it as the CI secret `SOAR_OTA_TOKEN`.

## Publish

1. Build: `soar build -o app.soarb`.
2. Upload to the branch, start small:
   ```sh
   curl -H "$T" -F bundle=@app.soarb -F branch=main -F message="2.4.1: fix login" \
        -F app_version=2.4.0 -F min_host_ver=29 -F rollout_percent=10 $API/updates
   ```
   Keep `apply_mode` at the default `next_launch` unless the fix is urgent.
3. Check: `curl -H "$T" "$API/updates?channel=production&limit=3"`.

## Roll out

1. Watch `curl -H "$T" $API/channels/production/stats` (needs client reports) and crash tools.
2. Raise: `curl -X PATCH -H "$T" -H 'Content-Type: application/json' -d '{"rollout_percent":50}' $API/updates/<group_id>`, then 100.
3. Halt at any time with `{"rollout_percent":0}`: devices that already installed keep it; others get the previous update.
4. Promote staging to production without rebuilding: `curl -X PATCH … -d '{"branch":"staging"}' $API/channels/production`,
   or gradually with `POST $API/channels/production/rollout {"branch":"staging","percent":10}` and
   `POST …/rollout/end {"promote":true}`.

## Roll back

- To the previous update: `curl -H "$T" -H 'Content-Type: application/json' -d '{"to":"previous","message":"bad 2.4.1"}' $API/branches/main/rollback`.
  This creates a new, higher version with the old bundle; devices take it on their next check.
- To a specific group: `{"to":"<group_id>"}`.
- To the embedded bundle: `{"to":"embedded"}` (needs the planned client; the current client ignores it).
- Devices also roll back by themselves when a new bundle fails two launches.

## Rotate keys

The app trusts only the public key built into it, so rotation needs a store release.

1. `soar keygen --out keys/prod-2026`.
2. Ship an app release whose build trusts the **new** public key.
3. Until most users have that release, keep signing with the old key. Updates for the new build
   must be signed with the new key, so pin them: publish with `app_version` of the new build and
   put the new key on a separate app id or tier, or wait until the old build is retired.
4. Replace `$OTA_KEYS_DIR/$APP.key` (or the tier file) with the new seed and update `apps.public_key`.
5. Destroy the old seed.

If a key leaks: halt rollouts (`rollout_percent` 0), replace the key file at once (old builds then
get nothing they can verify, which is safe), ship a store release with a new public key.

Rotate client keys (`soar-ota-key`) with `PUT $API/channels/{channel}/client-key {"client_key":"…"}`
only after builds with the new key are out; `{"client_key":null}` removes the check.
