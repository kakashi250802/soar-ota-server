# Device reports (planned client support)

Today's Soar client does not report anything; it only downloads. This is the report the next client version will
send, so the server can count installs and rollbacks per version (the stats in [api.md](api.md#reports)).

## Request

```http
POST /reports/{app_id}
Content-Type: application/json
```

```json
{
  "device_id": "3f1c9a0e-7b2d-4c11-9f5e-0a8b6c4d2e19",
  "channel": "production",
  "platform": "android",
  "host_ver": 31,
  "app_version": "1.5.0",
  "event": "healthy",
  "version": 3,
  "previous_version": 2
}
```

| Field | Type | Meaning |
|---|---|---|
| `device_id` | string | The same id the rollout bucket uses. The server may store a hash of it instead. |
| `channel` | string | The channel the build follows. |
| `platform` | string | `ios` or `android`. |
| `host_ver` | integer | The engine (host) version of the installed app. |
| `app_version` | string | The store version of the app. |
| `event` | string | `installed`: the bundle was downloaded, verified and staged. `healthy`: it launched and passed its first run. `rolled_back`: it failed twice at launch and the device went back to the previous bundle. |
| `version` | integer | The OTA version the event is about. |
| `previous_version` | integer or null | What ran before (null for the embedded bundle). |

## Response and behaviour

- `204 No Content` on success, `400` on a malformed body, `404` for an unknown app.
- The client sends it from its background poller, once, and does not retry; reports are statistics, not guarantees.
- Rate-limit per device id. Never let reports affect what a device receives: the signed manifest decides that.

## Stats

Count distinct devices per `(app, channel, version, event)`. The rollback rate of a version is
`rolled_back / installed`; a rising rate is the signal to roll back ([rollout-and-rollback.md](rollout-and-rollback.md)).
