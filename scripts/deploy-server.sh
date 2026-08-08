#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────
# Oblivion Findings — fresh-server provisioning script.
#
# Run this on a freshly-cloned VPS to bring the app up. Idempotent —
# safe to re-run on every deploy.
#
# Steps:
#   1. git fetch + fast-forward pull from origin/main
#   2. composer install --no-dev
#   3. npm ci && npm run build:ssr
#   4. php artisan migrate --force
#   5. php artisan storage:link
#   6. php artisan optimize:clear
#   7. optional Nominatim install or geocoder health check
#   8. install and health-check the Inertia SSR runtime in Supervisor
#   9. install the eight monitoring workers and three UDP listeners in Supervisor
#  10. php artisan queclink:install   (refreshes + restarts listener)
#  11. php artisan queue:restart
#
# Requires: bash, php, composer, node + npm, MySQL credentials in .env,
# and Supervisor for the Inertia SSR and native monitoring runtimes unless
# explicitly skipped.
# If running on a server with sudo available the queclink:install step
# will write to /etc/systemd/system — invoke this script with sudo or
# pass --skip-queclink to defer.
# ──────────────────────────────────────────────────────────────────────
set -euo pipefail

# Login shells may use umask 0077. Deployment artifacts must remain readable by
# PHP-FPM and nginx after Composer and Vite recreate their cache/build files.
umask 002

SKIP_QUECLINK=0
INSTALL_NOMINATIM=0
SKIP_NOMINATIM=0
SKIP_GIT_UPDATE=0
SKIP_INERTIA_SSR=0
SKIP_MONITORING_SUPERVISOR=0
for arg in "$@"; do
    case "$arg" in
        --skip-git-update) SKIP_GIT_UPDATE=1 ;;
        --skip-inertia-ssr) SKIP_INERTIA_SSR=1 ;;
        --skip-queclink) SKIP_QUECLINK=1 ;;
        --skip-monitoring-supervisor) SKIP_MONITORING_SUPERVISOR=1 ;;
        --install-nominatim) INSTALL_NOMINATIM=1 ;;
        --skip-nominatim) SKIP_NOMINATIM=1 ;;
        --help|-h) echo "Usage: $0 [--skip-git-update] [--skip-inertia-ssr] [--skip-monitoring-supervisor] [--skip-queclink] [--install-nominatim] [--skip-nominatim]"; exit 0 ;;
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

assert_clean_release_checkout() {
    local checkout_status
    checkout_status="$(run_app git status --porcelain=v1 --untracked-files=all)"
    if [ -n "$checkout_status" ]; then
        echo "✗ deployment refused: the release checkout contains tracked or untracked changes."
        echo "  Deploy a clean release; do not mix source with runtime or browser evidence artifacts."
        exit 1
    fi
}

assert_origin_main_release() {
    local checkout_head
    local origin_main_head
    checkout_head="$(run_app git rev-parse --verify HEAD)"
    origin_main_head="$(run_app git rev-parse --verify refs/remotes/origin/main)"
    if [ "$checkout_head" != "$origin_main_head" ]; then
        echo "✗ deployment refused: the checked-out release does not exactly match origin/main."
        exit 1
    fi
}

if [ "$SKIP_GIT_UPDATE" -eq 1 ]; then
    echo "▶ skipping git update (--skip-git-update)"
    if [ -e .git ]; then
        assert_clean_release_checkout
    fi
elif [ ! -e .git ]; then
    echo "✗ git update requested, but $(pwd) is not a Git checkout."
    echo "  Re-run from the app root or pass --skip-git-update for artifact-only deployments."
    exit 1
else
    assert_clean_release_checkout

    echo "▶ git fetch --prune origin"
    run_app git fetch --prune origin

    echo "▶ git pull --ff-only origin main"
    run_app git pull --ff-only origin main

    assert_origin_main_release
    assert_clean_release_checkout
fi

echo "▶ composer install"
run_app composer install --no-dev --optimize-autoloader --no-interaction

echo "▶ npm ci && npm run build:ssr"
run_app npm ci
export NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=8192}"
run_app env NODE_OPTIONS="$NODE_OPTIONS" npm run build:ssr

echo "▶ php artisan migrate --force"
run_app php artisan migrate --force

echo "▶ php artisan storage:link"
run_app php artisan storage:link 2>/dev/null || true

echo "▶ php artisan optimize:clear"
run_app php artisan optimize:clear

# Demo data for the redesigned /emar/rounds page and the Finance hubs. Opt-in (set
# SEED_DEMO_DATA=true in .env on demo/test servers) so we never inject fake residents
# or finance rows into a real deployment. Both seeders are idempotent + date-relative,
# so every deploy refreshes TODAY's rounds and repairs the org-1 chart of accounts.
# Non-fatal: a seeding hiccup must never fail the deploy.
if [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
    echo "▶ php artisan db:seed MedicationRoundsDemoSeeder (SEED_DEMO_DATA=true)"
    run_app php artisan db:seed --class='Database\Seeders\MedicationRoundsDemoSeeder' --force \
        || echo "  ⚠ demo round seed failed (non-fatal) — run manually if needed."

    # FinanceDemoSeeder re-seeds the org-1 chart of accounts + an open fiscal period
    # (idempotent; FinanceSeeder::run(1) runs before its demo-data guard, so redeploys
    # repair an emptied/partial chart). Without a chart, every observer-dispatched GL
    # post (fuel, asset maintenance, house-ledger groceries) fails to resolve its
    # accounts and silently writes no journal.
    echo "▶ php artisan db:seed FinanceDemoSeeder (SEED_DEMO_DATA=true)"
    run_app php artisan db:seed --class='Database\Seeders\FinanceDemoSeeder' --force \
        || echo "  ⚠ finance demo seed failed (non-fatal) — run manually if needed."
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

if [ "$SKIP_INERTIA_SSR" -eq 1 ]; then
    echo "▶ skipping Inertia SSR Supervisor install (--skip-inertia-ssr)"
else
    echo "▶ scripts/inertia/install-supervisor.sh"
    inertia_ssr_supervisor_args=(
        "--application-path=$(pwd -P)"
        "--run-user=${INERTIA_SSR_RUNTIME_USER:-www-data}"
        "--log-directory=${INERTIA_SSR_LOG_DIRECTORY:-/var/log/oblivion}"
    )
    if [ -n "${INERTIA_SSR_SUPERVISOR_INCLUDE_DIR:-}" ]; then
        inertia_ssr_supervisor_args+=("--include-directory=$INERTIA_SSR_SUPERVISOR_INCLUDE_DIR")
    fi
    if [ -n "${INERTIA_SSR_SUPERVISORD_CONFIG:-}" ]; then
        inertia_ssr_supervisor_args+=("--supervisord-config=$INERTIA_SSR_SUPERVISORD_CONFIG")
    fi

    if [ "$(id -u)" -eq 0 ]; then
        bash scripts/inertia/install-supervisor.sh "${inertia_ssr_supervisor_args[@]}"
    elif command -v sudo >/dev/null; then
        sudo bash scripts/inertia/install-supervisor.sh "${inertia_ssr_supervisor_args[@]}"
    else
        echo "✗ Inertia SSR Supervisor install requires root or sudo."
        echo "  Re-run with privilege or explicitly pass --skip-inertia-ssr."
        exit 1
    fi
fi

if [ "$SKIP_MONITORING_SUPERVISOR" -eq 1 ]; then
    echo "▶ skipping monitoring Supervisor install (--skip-monitoring-supervisor)"
else
    echo "▶ scripts/monitoring/install-supervisor.sh"
    monitoring_supervisor_args=(
        "--application-path=$(pwd -P)"
        "--run-user=${MONITORING_RUNTIME_USER:-www-data}"
        "--log-directory=${MONITORING_LOG_DIRECTORY:-/var/log/oblivion}"
    )
    if [ -n "${MONITORING_SUPERVISOR_INCLUDE_DIR:-}" ]; then
        monitoring_supervisor_args+=("--include-directory=$MONITORING_SUPERVISOR_INCLUDE_DIR")
    fi
    if [ -n "${MONITORING_SUPERVISORD_CONFIG:-}" ]; then
        monitoring_supervisor_args+=("--supervisord-config=$MONITORING_SUPERVISORD_CONFIG")
    fi

    if [ "$(id -u)" -eq 0 ]; then
        bash scripts/monitoring/install-supervisor.sh "${monitoring_supervisor_args[@]}"
    elif command -v sudo >/dev/null; then
        sudo bash scripts/monitoring/install-supervisor.sh "${monitoring_supervisor_args[@]}"
    else
        echo "✗ monitoring Supervisor install requires root or sudo."
        echo "  Re-run with privilege or explicitly pass --skip-monitoring-supervisor."
        exit 1
    fi
fi

if [ "$SKIP_QUECLINK" -eq 0 ]; then
    echo "▶ php artisan queclink:install"
    if [ "$(id -u)" -ne 0 ] && ! command -v sudo >/dev/null; then
        echo "✗ queclink:install requires root or sudo."
        echo "  Re-run with privilege or explicitly pass --skip-queclink."
        exit 1
    elif [ "$(id -u)" -ne 0 ]; then
        sudo -E php artisan queclink:install
    else
        php artisan queclink:install
    fi

    queclink_running=false
    for attempt in {1..10}; do
        if systemctl is-active --quiet oblivion-queclink.service; then
            queclink_running=true
            break
        fi
        sleep 1
    done
    if [ "$queclink_running" != true ]; then
        echo "✗ Queclink listener did not reach active systemd state."
        exit 1
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
