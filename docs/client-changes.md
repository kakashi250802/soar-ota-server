# Planned client changes (Soar engine)

The server already speaks the protocol below. The Soar repo is not changed yet; this is the list
of what its device code (`crates/soar-ota/src/updater.rs` and the host) must do.

1. **New fetcher.** Add a `ProtocolFetch` next to `HttpFetch` that sends one
   `GET {base}/updates/{app_id}` with the `soar-*` headers in [protocol.md](protocol.md#42-soar-updates-protocol-v1-planned-client):
   `soar-protocol-version: 1`, `soar-platform`, `soar-runtime-version` (`HostInfo.host_ver`),
   `soar-channel-name`, `soar-client-id` (`HostInfo.device_id`), `soar-current-update-id`
   (`Updater::running_version()` when it is not the embedded one), `soar-embedded-update-id`,
   `soar-app-version` (the store app version) and `soar-ota-key` when the build has a tier key.
2. **Responses.** 204: nothing to do. 200 `type: update`: parse `manifest` with the existing
   `Manifest::from_json`, run the existing checks, download `bundle_url` (still at most 16 MiB) and
   call `Updater::offer`. 401/404/429/5xx: log and try again later. Keep the static layout as a
   fallback when the base URL is a directory or a plain file host.
3. **Roll back to embedded.** New directive: verify the signature over the directive canonical
   bytes (`soar-ota-directive-v1`), check `app_id` and `channel`, then clear `good`, `pending` and
   `trial` in the OTA state so the next launch runs the embedded bundle. Record the directive version
   so a later bundle with a higher version is still accepted.
4. **App version pin.** Send `soar-app-version`; the server filters. No signed field changes.
5. **Defaults like the reference app.** Check on every launch on the poller thread (never block
   the first frame), download in the background, apply on the next launch (`next_launch`); hot swap
   only when the manifest says `immediate`.
6. **Reports.** After `stage`, `mark_healthy` and a rollback, POST the event described in
   [device-reports.md](device-reports.md). Fire and forget, on the poller thread.
7. **Tier keys.** Ship the public key of the build's tier (dev, uat/staging or prod). A build with
   another tier's key, or none, accepts no update.
