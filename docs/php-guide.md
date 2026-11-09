# Building the OTA server in PHP

A step-by-step guide for the backend team, from the smallest server that works with today's Soar apps to the full
Expo-like service. The code in [examples/minimal-php](../examples/minimal-php) is the first step, complete and tested;
the later steps are designs, with the exact rules in the other documents.

## What the server does

A Soar app ships with one bundle built in. Over the air, the server can give it a newer bundle:

1. The app asks for the manifest of its app id and channel (`production`, `staging`, ...).
2. The manifest names the newest bundle: version, SHA-256, size, rollout percentage, when to apply it, and an
   ed25519 signature.
3. The app checks the signature with the public key built into it, checks that it can run the bundle and that it is
   in the rollout, downloads the bundle, checks its size and hash, and runs it (next launch, on resume, or at once).

Everything the device relies on is signed, so the server never has to be trusted: it serves files and decides who gets
what. A wrong or tampered manifest is refused on the device.

## Step 1: the smallest server (works today)

Requirements: PHP 8.1 or newer with the `sodium` extension (built into PHP since 7.2). No framework, no database.

```text
examples/minimal-php/
  lib/ota.php     canonical bytes, sign, verify (about 50 lines)
  publish.php     signs a bundle and writes the folder devices read
  server.php      serves that folder: manifest.json and bundles
  verify.php      checks manifests against a public key
```

### 1.1 Check that PHP and the Soar engine agree

The `fixtures/` folder holds manifests signed by the real Soar CLI. PHP must accept every one:

```bash
cd examples/minimal-php
php verify.php ../../fixtures/soar-signing.pub ../../fixtures/manifests/*.json
```

```text
ok   ../../fixtures/manifests/production-v1-r100.json (v1, 100%)
ok   ../../fixtures/manifests/production-v1-r25.json (v1, 25%)
ok   ../../fixtures/manifests/production-v2.json (v2, 100%)
ok   ../../fixtures/manifests/production-v3-rollback.json (v3, 50%)
ok   ../../fixtures/manifests/staging-v1.json (v1, 100%)
```

No PHP on your machine? Run the same commands in Docker:
`docker run --rm -v "$PWD":/w -w /w/examples/minimal-php php:8.3-cli php verify.php ...`.

### 1.2 Publish a bundle

```bash
php publish.php ../../fixtures/demo-v2.soarb \
  --key ../../fixtures/soar-signing.key \
  --app dev.soar.demo --channel production \
  --version 2 --min-host 29 --rollout 100 --mode immediate
```

```text
published dev.soar.demo production v2 (100%)
```

It writes:

```text
data/dev.soar.demo/production/manifest.json
data/dev.soar.demo/production/bundles/2-24ea6843d85086db.soarb
```

The signature in that `manifest.json` is byte for byte the one `soar publish` wrote for the same bundle
(`fixtures/manifests/production-v2.json`): ed25519 is deterministic.

### 1.3 Serve it

```bash
php -S 0.0.0.0:8080 server.php
```

```text
GET /dev.soar.demo/production/manifest.json            200 + ETag (304 with If-None-Match), 404 if nothing published
GET /dev.soar.demo/production/bundles/2-24ea6843d85086db.soarb   the bundle, cached for a year
```

The Soar engine's own OTA client, run against this server (`tools/rust-check`, see the README), prints:

```text
manifest v2 rollout 100% mode Immediate
STAGED and swap now: 682 bytes
```

### 1.4 The core of the code

What gets signed is a fixed binary string, not the JSON text. In PHP:

```php
function ota_canonical_bytes(array $m): string
{
    $str = fn (string $s): string => pack('N', strlen($s)) . $s;   // u32 big-endian byte length + bytes
    $kernels = array_values(array_unique($m['kernels']));
    sort($kernels, SORT_STRING);                                     // bytewise
    $out = "soar-ota-manifest-v1\0" . $str($m['app_id']) . $str($m['channel'])
        . pack('J', $m['version']) . pack('N', $m['min_host_ver']) . pack('N', count($kernels));
    foreach ($kernels as $k) {
        $out .= $str($k);
    }
    return $out . $str($m['bundle_sha256']) . pack('J', $m['bundle_size'])
        . chr($m['rollout_percent']) . chr(['next_launch' => 0, 'on_resume' => 1, 'immediate' => 2][$m['apply_mode']]);
}

// Sign with the 32-byte seed from `soar keygen` (64 hex characters in soar-signing.key).
$pair = sodium_crypto_sign_seed_keypair(hex2bin(trim($seedHex)));
$manifest['signature'] = bin2hex(sodium_crypto_sign_detached(ota_canonical_bytes($manifest), sodium_crypto_sign_secretkey($pair)));

// Verify with the public key the app ships (64 hex characters in soar-signing.pub).
$ok = sodium_crypto_sign_verify_detached(hex2bin($manifest['signature']), ota_canonical_bytes($manifest), hex2bin($publicHex));
```

The rules behind each byte: [manifest-and-signing.md](manifest-and-signing.md).

### 1.5 Rules you must keep

- Hex is lowercase. Field names and JSON types are exactly those of the manifest table; extra fields are ignored.
- `version` only grows per app. A device ignores any version that is not above the one it runs, so a rollback is a
  new, higher version carrying the old bundle ([rollout-and-rollback.md](rollout-and-rollback.md)).
- A bundle file is never overwritten: its name carries its hash. Write the bundle first and replace `manifest.json`
  last, atomically (write a temporary file, then `rename`).
- `min_host_ver` must be at least the bundle format version the Soar CLI reports (29 for the demo bundles); an app
  with an older engine then refuses the update instead of failing to load it.
- Keep the signing key out of the web root and out of git. The demo key in `fixtures/` is a throwaway test key.

## Step 2: change the rollout without a new bundle

Changing `rollout_percent` changes the signed bytes, so the manifest is signed again with the same key: run
`publish.php` with the same bundle, the same `--version` and the new `--rollout`. The bundle file stays as it is.
The device decides by itself whether it is in the rollout, from its device id, the app id and the version
([rollout-and-rollback.md](rollout-and-rollback.md), with test vectors in `fixtures/rust-vectors.json`); the server
does not need to know which devices are in.

## Step 3: a real service

When the team builds the full server, keep the same device URLs and add, in this order:

1. **A database** for apps, channels, branches and updates; a suggested schema is in
   [architecture.md](architecture.md) and [reference/php/schema-migration.php](../reference/php/schema-migration.php).
   Build and sign each manifest when a device asks (signatures are deterministic, so ETags stay stable).
2. **An admin API for CI and the team**, one endpoint per `eas` command: publish, list, rollout, republish, rollback,
   channel edits ([api.md](api.md), [expo-comparison.md](expo-comparison.md)).
3. **Key custody**: keep the seed in a secret store or a KMS, or keep signing in CI and have the server only accept
   manifests that already verify ([security.md](security.md)).
4. **Device reports** (installed, healthy, rolled back) and per-version stats ([device-reports.md](device-reports.md)).
5. **The Expo-like request** `GET /updates/{app}` with `soar-*` headers, for the next client version
   ([device-protocol.md](device-protocol.md), [client-changes.md](client-changes.md)).

[reference/php](../reference/php) holds tested PHP for the tricky parts (manifest bytes, signing, the rollout
bucket, choosing the update for a device). It is reference code, not a runnable server: copy what helps.
