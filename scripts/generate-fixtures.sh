#!/usr/bin/env bash
# Regenerates fixtures/ with the real Soar CLI: demo bundles, a throwaway signing key, signed manifests and
# the static layout a device reads. Needs a built `soar` binary:
#   cd ../soar && cargo build --release -p soar-cli
# Usage: scripts/generate-fixtures.sh [path/to/soar]
set -euo pipefail

SOAR="${1:-../soar/target/release/soar}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
APP=dev.soar.demo
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cd "$WORK"
"$SOAR" new demo --name Demo >/dev/null
(cd demo && "$SOAR" build -o ../demo.soarb >/dev/null)
sed -i.bak 's/Welcome to Soar/Welcome back/' demo/src/App.soar
(cd demo && "$SOAR" build -o ../demo-v2.soarb >/dev/null)
"$SOAR" keygen --out keys >/dev/null

K=(--key keys/soar-signing.key --out static)
snap() { mkdir -p manifests; cp "static/$APP/$1/manifest.json" "manifests/$2.json"; }
"$SOAR" publish demo.soarb "${K[@]}" --app $APP --version 1 --channel production --rollout 25 --kernels storage,network,camera --mode resume --min-host 1 >/dev/null && snap production production-v1-r25
"$SOAR" rollout --percent 100 "${K[@]}" --app $APP --channel production >/dev/null && snap production production-v1-r100
"$SOAR" publish demo-v2.soarb "${K[@]}" --app $APP --version 2 --channel production --rollout 100 --mode now >/dev/null && snap production production-v2
"$SOAR" rollback --to 1 --version 3 "${K[@]}" --app $APP --channel production --rollout 50 >/dev/null && snap production production-v3-rollback
"$SOAR" publish demo.soarb "${K[@]}" --app $APP --version 1 --channel staging --rollout 100 >/dev/null && snap staging staging-v1

F="$HERE/fixtures"
rm -rf "$F/static-layout" "$F/manifests"
mkdir -p "$F"
cp -R static "$F/static-layout"
cp -R manifests "$F/manifests"
cp keys/soar-signing.key keys/soar-signing.pub "$F/"
cp demo.soarb "$F/demo-v1.soarb"
cp demo-v2.soarb "$F/demo-v2.soarb"
echo "Fixtures written. Also regenerate vectors: (cd tools/rust-check && cargo run --release --bin vectors > ../../fixtures/rust-vectors.json)"
