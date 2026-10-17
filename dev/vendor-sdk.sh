#!/usr/bin/env bash
# Vendor the P2Flux PHP SDK into the gateway (Blesta extensions ship their libraries).
#
# The namespace is rewritten to P2FluxBlesta\Vendor\P2Flux, so another extension bundling its own copy
# of the SDK can never win silently. Files are read from the release TAG, never from a working tree.
#
# Usage: dev/vendor-sdk.sh ../p2flux_sdk_php v0.12.0
set -euo pipefail

src="${1:-../p2flux_sdk_php}"
tag="${2:-}"
dest="$(cd "$(dirname "$0")/.." && pwd)/p2flux/vendor/p2flux"

[ -d "$src/src" ] || { echo "not an SDK checkout: $src" >&2; exit 1; }
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "give an exact release tag, e.g. v0.12.0 (got '$tag')" >&2; exit 1; }
commit="$(git -C "$src" rev-parse --verify -q "refs/tags/$tag^{commit}")" || { echo "no tag $tag in $src" >&2; exit 1; }

mkdir -p "$dest"
for file in P2FluxException.php ChargeResult.php CurlTransport.php P2FluxClient.php; do
  git -C "$src" show "$tag:src/$file" | sed 's/^namespace P2Flux;$/namespace P2FluxBlesta\\Vendor\\P2Flux;/' > "$dest/$file"
done
git -C "$src" show "$tag:LICENSE" > "$dest/LICENSE"
grep -q '^namespace P2FluxBlesta\\Vendor\\P2Flux;$' "$dest/P2FluxClient.php" || { echo "namespace rewrite failed" >&2; exit 1; }

cat > "$dest/VENDORED.md" <<NOTE
# Vendored: p2flux/sdk-php

Source: https://github.com/P2Flux/sdk-php at \`$tag\` (commit \`$commit\`), copied by \`dev/vendor-sdk.sh\`
from the tag itself. One mechanical edit: \`namespace P2Flux\` becomes \`namespace P2FluxBlesta\Vendor\P2Flux\`.
Do not edit these files. Fix the SDK upstream, tag it, and re-run the script.
NOTE
echo "vendored $tag ($commit) -> $dest"
