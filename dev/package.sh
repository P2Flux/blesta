#!/usr/bin/env bash
# Builds p2flux-blesta-<version>.zip from the committed tree (unpacks to p2flux/, ready for
# components/gateways/nonmerchant/). Usage: dev/package.sh 1.0.0
set -euo pipefail
cd "$(dirname "$0")/.."
version="${1:?version, e.g. 1.0.0}"
[ "$(php -r 'echo json_decode(file_get_contents("p2flux/config.json"))->version;')" = "$version" ] || { echo "config.json version is not $version" >&2; exit 1; }
git diff --quiet HEAD -- p2flux || { echo "uncommitted changes in p2flux/" >&2; exit 1; }
php tests/bridge.php >/dev/null
php tests/gateway.php >/dev/null
php tests/flows.php >/dev/null
for f in $(git ls-files 'p2flux/*.php' 'p2flux/**/*.php' 'p2flux/**/*.pdt'); do php -l "$f" >/dev/null; done
git archive --format=zip --output="p2flux-blesta-$version.zip" HEAD p2flux
sha256sum "p2flux-blesta-$version.zip"
