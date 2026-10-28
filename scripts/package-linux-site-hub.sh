#!/usr/bin/env bash
set -euo pipefail

# Packages a reproducible Linux Site hub tarball (linux/amd64 or linux/arm64)
# for Intel NUC / Raspberry Pi hosts.
ARCH="${1:-}"
BINARY_DIR="${2:-}"
OUTPUT_TARBALL="${3:-}"

if [[ "$ARCH" != "amd64" && "$ARCH" != "arm64" ]] || [[ -z "$BINARY_DIR" ]] || [[ -z "$OUTPUT_TARBALL" ]]; then
    echo "Usage: package-linux-site-hub.sh <amd64|arm64> <binary-dir> <output-tar.gz>" >&2
    exit 2
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COLLECTOR_DIR="${REPO_ROOT}/collector"
LINUX_PKG_DIR="${REPO_ROOT}/endpoint-agent/packaging/linux"

if [[ ! -f "${COLLECTOR_DIR}/vendor/autoload.php" ]]; then
    echo "Run composer install --no-dev in collector/ before packaging." >&2
    exit 1
fi

STAGE_ROOT="$(mktemp -d)"
trap 'rm -rf "$STAGE_ROOT"' EXIT

PKG_NAME="oblivion-site-hub-linux-${ARCH}"
DEST="${STAGE_ROOT}/${PKG_NAME}"
mkdir -p "${DEST}/systemd"

cp -R "${COLLECTOR_DIR}/bin" "${COLLECTOR_DIR}/src" "${COLLECTOR_DIR}/vendor" "${DEST}/"
cp "${COLLECTOR_DIR}/composer.json" "${DEST}/composer.json"
cp "${BINARY_DIR}/oblivion-site-collector" "${DEST}/oblivion-site-collector"
if [[ -f "${BINARY_DIR}/oblivion-media" ]]; then
    cp "${BINARY_DIR}/oblivion-media" "${DEST}/oblivion-media"
fi
if [[ -f "/etc/ssl/certs/ca-certificates.crt" ]]; then
    cp "/etc/ssl/certs/ca-certificates.crt" "${DEST}/cacert.pem"
fi
cp "${LINUX_PKG_DIR}/oblivion-site-collector.service" "${DEST}/systemd/oblivion-site-collector.service"
cp "${LINUX_PKG_DIR}/install.sh" "${DEST}/install.sh"

find "$DEST" -type d -exec chmod 0755 {} +
find "$DEST" -type f -exec chmod 0644 {} +
chmod 0755 "${DEST}/install.sh" "${DEST}/oblivion-site-collector" "${DEST}/bin/oblivion-collector"
if [[ -f "${DEST}/oblivion-media" ]]; then
    chmod 0755 "${DEST}/oblivion-media"
fi

mkdir -p "$(dirname "$OUTPUT_TARBALL")"
tar -C "$STAGE_ROOT" --owner=0 --group=0 --numeric-owner -czf "$OUTPUT_TARBALL" "$PKG_NAME"
echo "Packaged ${OUTPUT_TARBALL}"
