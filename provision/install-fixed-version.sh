#!/usr/bin/env sh
set -eu
umask 077

VERSION="${RONDO_MODULE_VERSION:-v1.0.3}"
SHA256="${RONDO_MODULE_SHA256:?Set RONDO_MODULE_SHA256 to the approved 64-character checksum}"
FREESCOUT_ROOT="${FREESCOUT_ROOT:-/var/www/html}"
BASE="https://github.com/RondoHQ/freescout-rondo-integration/releases/download/${VERSION}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

printf '%s' "$VERSION" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$'
printf '%s' "$SHA256" | grep -Eq '^[a-fA-F0-9]{64}$'
test ! -e "$FREESCOUT_ROOT/Modules/RondoIntegration"

curl --fail --silent --show-error --location --proto '=https' "$BASE/rondo-integration.zip" -o "$WORK/rondo-integration.zip"
printf '%s  %s\n' "$SHA256" "$WORK/rondo-integration.zip" | sha256sum --check --status
unzip -Z1 "$WORK/rondo-integration.zip" | while IFS= read -r entry; do
    case "$entry" in
        RondoIntegration/*) ;;
        *) exit 1 ;;
    esac
    case "$entry" in
        *../*|../*|/*) exit 1 ;;
    esac
done
unzip -q "$WORK/rondo-integration.zip" -d "$WORK/extracted"
test -f "$WORK/extracted/RondoIntegration/module.json"
cp -R "$WORK/extracted/RondoIntegration" "$FREESCOUT_ROOT/Modules/RondoIntegration"
cd "$FREESCOUT_ROOT"
php artisan freescout:module-install rondointegration
