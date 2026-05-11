#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────
# Oblivion Findings — fresh-server provisioning script.
#
# Run this on a freshly-cloned VPS to bring the app up. Idempotent —
# safe to re-run on every deploy.
#
# Steps:
#   1. composer install --no-dev
#   2. npm ci && npm run build
#   3. php artisan migrate --force
#   4. php artisan storage:link
#   5. php artisan queclink:install   (writes systemd unit + opens UFW port)
#   6. php artisan optimize:clear
#
# Requires: bash, php, composer, node + npm, MySQL credentials in .env.
# If running on a server with sudo available the queclink:install step
# will write to /etc/systemd/system — invoke this script with sudo or
# pass --skip-queclink to defer.
# ──────────────────────────────────────────────────────────────────────
set -euo pipefail

SKIP_QUECLINK=0
for arg in "$@"; do
    case "$arg" in
        --skip-queclink) SKIP_QUECLINK=1 ;;
        --help|-h) echo "Usage: $0 [--skip-queclink]"; exit 0 ;;
    esac
done

cd "$(dirname "$0")/.."

echo "▶ composer install"
composer install --no-dev --optimize-autoloader --no-interaction

echo "▶ npm ci && npm run build"
npm ci
npm run build

echo "▶ php artisan migrate --force"
php artisan migrate --force

echo "▶ php artisan storage:link"
php artisan storage:link 2>/dev/null || true

if [ "$SKIP_QUECLINK" -eq 0 ]; then
    echo "▶ php artisan queclink:install"
    if [ "$(id -u)" -ne 0 ] && ! command -v sudo >/dev/null; then
        echo "  ⚠ queclink:install needs root to write systemd units."
        echo "  Skipping — re-run with sudo or pass --skip-queclink to silence this."
    elif [ "$(id -u)" -ne 0 ]; then
        sudo -E php artisan queclink:install
    else
        php artisan queclink:install
    fi
else
    echo "▶ skipping queclink:install (--skip-queclink)"
fi

echo "▶ php artisan optimize:clear"
php artisan optimize:clear

echo
echo "✓ Server provisioning complete."
echo
echo "Queclink listener:"
echo "  Status:   sudo systemctl status oblivion-queclink"
echo "  Logs:     sudo journalctl -u oblivion-queclink -f"
echo "  Settings: visit /security-devices/integrations/queclink in the app"
