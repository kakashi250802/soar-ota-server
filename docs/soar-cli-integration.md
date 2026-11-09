# Proposal: Soar CLI commands that talk to this server

Not implemented in the Soar repo yet. Today `soar publish`, `soar rollout` and `soar rollback`
write a static directory. These additions call the admin API instead. Common flags:

| Flag | Env var | Meaning |
|---|---|---|
| `--server URL` | `SOAR_OTA_SERVER` | Server base URL, e.g. `https://ota.example.com` |
| `--token T` | `SOAR_OTA_TOKEN` | Admin/CI token (issued by the backend, limited to the app); never on the command line in CI logs, prefer the env var |
| `--app ID` | from `soar.json` | App id |

## `soar update` (like `eas update`)

```
soar update [FILE.soar|FILE.soarb] --branch main --message "Fix login" \
    [--platform all|ios|android|ios,android] [--rollout 10] [--mode next|resume|now] \
    [--app-version 2.4.0] [--kernels a,b] [--server URL] [--token T]
```

1. Build the bundle when given a `.soar` (as `soar publish` does) and run the verifier.
2. Compute `min_host_ver = max(--min-host, required .soarb version)` exactly like `soar publish`.
3. `POST {server}/api/v1/admin/apps/{app}/updates`, multipart fields `bundle`, `branch`, `message`,
   `platforms`, `min_host_ver`, `app_version`, `kernels`, `apply_mode` (`next_launch`, `on_resume`,
   `immediate`), `rollout_percent`.
4. Print the group id and versions from the 201 answer. Exit non-zero on 4xx/5xx, printing `errors`.

## Other commands

| Command | HTTP call |
|---|---|
| `soar update:list [--branch B \| --channel C]` | `GET …/updates?branch=B` |
| `soar update:edit GROUP --rollout P` | `PATCH …/updates/GROUP` `{"rollout_percent":P}` |
| `soar update:republish GROUP [--branch B \| --channel C]` | `POST …/updates/GROUP/republish` |
| `soar update:rollback --branch B [--to GROUP \| --previous \| --embedded]` | `POST …/branches/B/rollback` |
| `soar branch:list` / `soar branch:create NAME` | `GET` / `POST …/branches` |
| `soar channel:create NAME --branch B` | `POST …/channels` |
| `soar channel:edit NAME --branch B` | `PATCH …/channels/NAME` |
| `soar channel:rollout NAME --branch B --percent P` / `--end [--promote]` | `POST …/channels/NAME/rollout` / `…/rollout/end` |

## Keep `soar publish` working: `--server` on the existing commands

```
soar publish app.soarb --key soar-signing.key --app ID --version N --channel production \
    --server https://ota.example.com --token $SOAR_OTA_TOKEN [--branch main]
```

With `--server` instead of `--out`, the CLI signs locally as today, then uploads `manifest.json` and
the bundle to `POST …/imports` (multipart `manifest`, `bundle`, `branch`). The server checks the
signature against the app's registered public key before it accepts the release. This is the path
for teams that keep the signing key in CI only.
