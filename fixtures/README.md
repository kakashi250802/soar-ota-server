# Fixtures

Made by the real Soar CLI with [scripts/generate-fixtures.sh](../scripts/generate-fixtures.sh); test vectors from the
engine's own code with `tools/rust-check` (`cargo run --release --bin vectors > ../../fixtures/rust-vectors.json`).

| File | What it is |
|---|---|
| `demo-v1.soarb`, `demo-v2.soarb` | Two bundles of a demo app (`soar build`) |
| `soar-signing.key`, `soar-signing.pub` | A throwaway signing key: 32-byte seed and public key as lowercase hex. Never use it for real apps. |
| `manifests/*.json` | Manifests `soar publish`, `soar rollout` and `soar rollback` signed with that key |
| `static-layout/` | The folder `soar publish` writes, exactly what devices read today |
| `rust-vectors.json` | Rollout buckets, canonical manifest bytes and signatures computed by `soar_ota` |

A server implementation must accept every manifest here with `soar-signing.pub`, and produce the same signature when
it signs the same fields with `soar-signing.key`.
