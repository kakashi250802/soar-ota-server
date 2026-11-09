# Soar OTA server: specification and PHP starter

Over-the-air updates for apps built with Soar, modelled on Expo Updates / EAS Update. This repository is the
hand-off to the backend team: the protocol the apps rely on, a minimal PHP server that works with today's apps, test
fixtures made by the real Soar CLI, and the design of the full service.

Start here: [docs/for-backend-team.md](docs/for-backend-team.md), then [docs/php-guide.md](docs/php-guide.md).

## Contents

| Path | What it is |
|---|---|
| [docs/](docs) | The protocol, the admin API design, the architecture, security and the release runbook |
| [examples/minimal-php](examples/minimal-php) | The smallest working server in plain PHP: sign, publish, serve, verify |
| [fixtures/](fixtures) | Bundles, a throwaway signing key, signed manifests and the static layout, made by the real Soar CLI; `rust-vectors.json` holds test vectors from the engine's own code |
| [reference/php](reference/php) | Reference PHP for the tricky parts (manifest bytes, signing, rollout bucket, update selection) and a suggested schema; not a runnable server |
| [tools/rust-check](tools/rust-check) | Rust programs that run the engine's real OTA code: `vectors` prints test vectors, `client` plays a device against a server |
| [scripts/generate-fixtures.sh](scripts/generate-fixtures.sh) | Rebuilds `fixtures/` with the Soar CLI |

## Try the minimal server

With PHP 8.1+ (or Docker: `docker run --rm -it -p 8080:8080 -v "$PWD":/w -w /w/examples/minimal-php php:8.3-cli sh`):

```bash
cd examples/minimal-php
php verify.php ../../fixtures/soar-signing.pub ../../fixtures/manifests/*.json
php publish.php ../../fixtures/demo-v2.soarb --key ../../fixtures/soar-signing.key \
  --app dev.soar.demo --channel production --version 2 --min-host 29 --rollout 100 --mode immediate
php -S 0.0.0.0:8080 server.php
```

Then play a device against it with the engine's own client (needs Rust and a checkout of the Soar engine next to this
repository):

```bash
cd tools/rust-check
cargo run --release --bin client -- http://127.0.0.1:8080 dev.soar.demo production ../../fixtures/soar-signing.pub device-1
```

```text
manifest v2 rollout 100% mode Immediate
STAGED and swap now: 682 bytes
```

## What was checked

- PHP accepts every manifest the Soar CLI signed in `fixtures/manifests` (`verify.php`).
- A manifest PHP signs for the same bundle and fields carries the same signature as the CLI's.
- The minimal server answers `200` with an ETag, `304` for a matching `If-None-Match`, `404` when nothing is
  published, and serves the bundle with a matching hash.
- The engine's real OTA client downloads, verifies and stages the bundle from the PHP server.

These ran in `php:8.3-cli` (Docker) against fixtures from Soar 0.1.2.
