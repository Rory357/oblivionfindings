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
#   5. php artisan optimize:clear
#   6. optional Nominatim install or geocoder health check
#   7. php artisan queclink:install   (refreshes + restarts listener)
#   8. php artisan queue:restart
#
# Requires: bash, php, composer, node + npm, MySQL credentials in .env.
# If running on a server with sudo available the queclink:install step
# will write to /etc/systemd/system — invoke this script with sudo or
# pass --skip-queclink to defer.
# ──────────────────────────────────────────────────────────────────────
set -euo pipefail

SKIP_QUECLINK=0
INSTALL_NOMINATIM=0
SKIP_NOMINATIM=0
for arg in "$@"; do
    case "$arg" in
        --skip-queclink) SKIP_QUECLINK=1 ;;
        --install-nominatim) INSTALL_NOMINATIM=1 ;;
        --skip-nominatim) SKIP_NOMINATIM=1 ;;
        --help|-h) echo "Usage: $0 [--skip-queclink] [--install-nominatim] [--skip-nominatim]"; exit 0 ;;
        *) echo "Unknown option: $arg"; exit 1 ;;
    esac
done

cd "$(dirname "$0")/.."

APP_RUN_USER="${APP_RUN_USER:-${SUDO_USER:-}}"
if [ -z "$APP_RUN_USER" ] || [ "$APP_RUN_USER" = "root" ]; then
    APP_RUN_USER="$(stat -c '%U' .)"
fi

run_app() {
    if [ "$(id -u)" -eq 0 ] && [ -n "$APP_RUN_USER" ] && [ "$APP_RUN_USER" != "root" ]; then
        runuser -u "$APP_RUN_USER" -- "$@"
    else
        "$@"
    fi
}

echo "▶ composer install"
run_app composer install --no-dev --optimize-autoloader --no-interaction

echo "▶ npm ci && npm run build"
run_app npm ci
export NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=8192}"
run_app env NODE_OPTIONS="$NODE_OPTIONS" npm run build

echo "▶ php artisan migrate --force"
run_app php artisan migrate --force

echo "▶ php artisan storage:link"
run_app php artisan storage:link 2>/dev/null || true

echo "▶ php artisan optimize:clear"
run_app php artisan optimize:clear

# Demo data for the redesigned /emar/rounds page. Opt-in (set SEED_DEMO_DATA=true
# in .env on demo/test servers) so we never inject fake residents into a real
# deployment. Idempotent + date-relative, so every deploy refreshes TODAY's rounds.
# Non-fatal: a seeding hiccup must never fail the deploy.
if [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
    echo "▶ php artisan db:seed MedicationRoundsDemoSeeder (SEED_DEMO_DATA=true)"
    run_app php artisan db:seed --class='Database\Seeders\MedicationRoundsDemoSeeder' --force \
        || echo "  ⚠ demo round seed failed (non-fatal) — run manually if needed."
fi

if [ "$SKIP_NOMINATIM" -eq 1 ]; then
    echo "▶ skipping geocoder health check (--skip-nominatim)"
elif [ "$INSTALL_NOMINATIM" -eq 1 ]; then
    echo "▶ scripts/nominatim/install-nominatim.sh"
    bash scripts/nominatim/install-nominatim.sh

    echo "▶ php artisan fleet:geocoder:status --fail-if-enabled"
    run_app php artisan fleet:geocoder:status --fail-if-enabled
else
    echo "▶ php artisan fleet:geocoder:status --fail-if-enabled"
    run_app php artisan fleet:geocoder:status --fail-if-enabled
fi

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

echo "▶ php artisan queue:restart"
run_app php artisan queue:restart

echo
echo "✓ Server provisioning complete."
echo
echo "Queclink listener:"
echo "  Status:   sudo systemctl status oblivion-queclink"
echo "  Logs:     sudo journalctl -u oblivion-queclink -f"
echo "  Settings: visit /security-devices/integrations/queclink in the app"
echo
echo "Fleet geocoder:"
echo "  Status:   php artisan fleet:geocoder:status"
echo "  Backfill: php artisan fleet:reverse-geocode:backfill --client=9012 --limit=500 --dry-run"
