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
#   4. rootless lifecycle-trigger preflight, isolated migrate, and exact postflight
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
# will write to /etc/systemd/system. Pass --skip-queclink only when an
# approved external manager owns that unit; release-required deployments
# still prove the externally managed listener is active.
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

MAINTENANCE_ACTIVE=0
DEPLOY_WRITER_DRAIN_TIMEOUT_SECONDS="${DEPLOY_WRITER_DRAIN_TIMEOUT_SECONDS:-390}"
DEPLOY_WEB_DRAIN_SECONDS="${DEPLOY_WEB_DRAIN_SECONDS:-5}"

assert_bounded_seconds() {
    local name="$1"
    local value="$2"
    local maximum="$3"

    if [[ ! "$value" =~ ^[0-9]+$ ]] || [ "$value" -gt "$maximum" ]; then
        echo "✗ deployment refused: $name must be an integer from 0 to $maximum."
        exit 1
    fi
}

queue_writer_pids() {
    local release_artisan
    release_artisan="$(pwd -P)/artisan"

    ps -eo pid=,user=,args= | awk \
        -v artisan="$release_artisan" \
        '$3 ~ /(^|\/)php([0-9.]+)?$/ && index($0, artisan) > 0 && index($0, "queue:work") > 0 { print $1 }'
}

wait_for_queue_writer_exit() {
    local deadline=$((SECONDS + DEPLOY_WRITER_DRAIN_TIMEOUT_SECONDS))
    local pid
    local process_command
    local release_artisan
    local writer_alive
    release_artisan="$(pwd -P)/artisan"

    while true; do
        writer_alive=0
        for pid in "$@"; do
            process_command="$(ps -p "$pid" -o args= 2>/dev/null || true)"
            if [[ "$process_command" == *"$release_artisan"* && "$process_command" == *"queue:work"* ]]; then
                writer_alive=1
                break
            fi
        done

        if [ "$writer_alive" -eq 0 ]; then
            return 0
        fi
        if [ "$SECONDS" -ge "$deadline" ]; then
            echo "✗ deployment stopped: pre-migration queue writers did not drain before the bounded timeout."
            return 1
        fi

        sleep 1
    done
}

report_maintenance_on_failure() {
    local exit_code=$?

    if [ "$exit_code" -ne 0 ] && [ "$MAINTENANCE_ACTIVE" -eq 1 ]; then
        if run_app php artisan down --retry=60 >/dev/null 2>&1; then
            echo "✗ deployment failed after maintenance began; the application is in maintenance mode."
            echo "  Complete reviewed forward recovery and validation before running php artisan up."
        else
            echo "✗ deployment failed and could not reconfirm maintenance mode automatically."
            echo "  Restore maintenance mode immediately, then complete reviewed forward recovery."
        fi
    fi

    return "$exit_code"
}

trap report_maintenance_on_failure EXIT

assert_bounded_seconds "DEPLOY_WRITER_DRAIN_TIMEOUT_SECONDS" "$DEPLOY_WRITER_DRAIN_TIMEOUT_SECONDS" 900
assert_bounded_seconds "DEPLOY_WEB_DRAIN_SECONDS" "$DEPLOY_WEB_DRAIN_SECONDS" 60
command -v ps >/dev/null || { echo "✗ deployment refused: ps is required for writer drain verification."; exit 1; }
command -v awk >/dev/null || { echo "✗ deployment refused: awk is required for writer drain verification."; exit 1; }

MONITORING_PROGRAMS=(
    oblivion-monitoring-events
    oblivion-monitoring-checks
    oblivion-monitoring-discovery
    oblivion-monitoring-provider
    oblivion-monitoring-topology
    oblivion-monitoring-maintenance
    oblivion-monitoring-orchestration
    oblivion-monitoring-commands
    oblivion-monitoring-snmp-traps
    oblivion-monitoring-syslog
    oblivion-monitoring-flow
)
MONITORING_EXPECTED_PROCESSES=(4 8 2 3 2 1 2 2 1 1 1)
MONITORING_COMMAND_MARKERS=(
    'queue:work redis --queue=monitoring-events '
    'queue:work redis --queue=monitoring-checks '
    'queue:work redis --queue=monitoring-discovery '
    'queue:work redis --queue=monitoring-provider '
    'queue:work redis --queue=monitoring-topology '
    'queue:work redis --queue=monitoring-maintenance '
    'queue:work redis --queue=monitoring '
    'queue:work redis --queue=monitoring-commands '
    'monitoring:listen-snmp-traps'
    'monitoring:listen-syslog'
    'monitoring:listen-flow'
)

monitoring_supervisor_status() {
    local supervisord_config="$1"
    local program="$2"
    local output

    if output="$(supervisorctl -c "$supervisord_config" status "$program:*" 2>/dev/null)"; then
        printf '%s\n' "$output"
        return 0
    fi
    if [ "$(id -u)" -ne 0 ] \
        && command -v sudo >/dev/null \
        && output="$(sudo -n supervisorctl -c "$supervisord_config" status "$program:*" 2>/dev/null)"; then
        printf '%s\n' "$output"
        return 0
    fi

    return 1
}

monitoring_root_supervisor_pid() {
    local supervisord_config="$1"
    local -a supervisor_pids

    mapfile -t supervisor_pids < <(
        ps -eo pid=,user=,args= | awk -v config="$supervisord_config" \
            '$2 == "root" && index($0, "/usr/bin/supervisord") > 0 && index($0, " -c " config) > 0 { print $1 }'
    )

    [ "${#supervisor_pids[@]}" -eq 1 ] || return 1
    printf '%s\n' "${supervisor_pids[0]}"
}

monitoring_supervised_process_pids() {
    local supervisord_config="$1"
    local release_artisan="$2"
    local command_marker="$3"
    local supervisor_pid

    supervisor_pid="$(monitoring_root_supervisor_pid "$supervisord_config")" || return 1
    ps -eo pid=,ppid=,args= | awk \
        -v parent="$supervisor_pid" \
        -v artisan="$release_artisan" \
        -v marker="$command_marker" \
        '$2 == parent && index($0, artisan) > 0 && index($0, marker) > 0 { print $1 }'
}

monitoring_runtime_is_release_bound() {
    local supervisord_config="${MONITORING_SUPERVISORD_CONFIG:-}"
    local release_artisan
    local index
    local program
    local expected_processes
    local command_marker
    local status_output
    local process_count
    local pid
    local process_command
    local -a process_pids

    command -v supervisorctl >/dev/null 2>&1 || return 1
    if [ -z "$supervisord_config" ]; then
        for candidate in /etc/supervisor/supervisord.conf /etc/supervisord.conf; do
            if [ -f "$candidate" ]; then
                supervisord_config="$candidate"
                break
            fi
        done
    fi
    [ -n "$supervisord_config" ] && [ -f "$supervisord_config" ] || return 1

    release_artisan="$(pwd -P)/artisan"
    for index in "${!MONITORING_PROGRAMS[@]}"; do
        program="${MONITORING_PROGRAMS[$index]}"
        expected_processes="${MONITORING_EXPECTED_PROCESSES[$index]}"
        command_marker="${MONITORING_COMMAND_MARKERS[$index]}"
        if status_output="$(monitoring_supervisor_status "$supervisord_config" "$program")"; then
            process_count="$(awk 'NF { count++ } END { print count + 0 }' <<< "$status_output")"
            [ "$process_count" -eq "$expected_processes" ] || return 1
            awk 'NF < 4 || $2 != "RUNNING" || $3 != "pid" || $4 !~ /^[0-9]+,$/ { exit 1 }' <<< "$status_output" \
                || return 1
            mapfile -t process_pids < <(awk '{ gsub(/,/, "", $4); print $4 }' <<< "$status_output")
        else
            # Some hardened hosts keep the Supervisor control socket root-only.
            # In that case, fail closed unless every expected process is an
            # immediate child of the one root-owned supervisord instance using
            # this exact configuration, release path, and command marker.
            mapfile -t process_pids < <(
                monitoring_supervised_process_pids "$supervisord_config" "$release_artisan" "$command_marker"
            )
            [ "${#process_pids[@]}" -eq "$expected_processes" ] || return 1
        fi
        [ "${#process_pids[@]}" -eq "$expected_processes" ] || return 1

        for pid in "${process_pids[@]}"; do
            process_command="$(ps -ww -p "$pid" -o args= 2>/dev/null)" || return 1
            [[ "$process_command" == *"$release_artisan $command_marker"* ]] || return 1
        done
    done

    return 0
}

assert_stable_monitoring_runtime() {
    local deadline=$((SECONDS + DEPLOY_WRITER_DRAIN_TIMEOUT_SECONDS))
    local consecutive_release_bound=0

    while true; do
        if monitoring_runtime_is_release_bound; then
            consecutive_release_bound=$((consecutive_release_bound + 1))
        else
            consecutive_release_bound=0
        fi
        if [ "$consecutive_release_bound" -ge 3 ]; then
            return 0
        fi
        if [ "$SECONDS" -ge "$deadline" ]; then
            echo "✗ deployment refused: monitoring workers and listeners are not stably RUNNING from this exact release."
            return 1
        fi

        sleep 1
    done
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
    if [ ! -e .git ]; then
        echo "✗ deployment refused: --skip-git-update still requires the reviewed Git checkout."
        echo "  The flag skips the network update only; it cannot bypass exact source-revision verification."
        exit 1
    fi
    assert_origin_main_release
    assert_clean_release_checkout
elif [ ! -e .git ]; then
    echo "✗ git update requested, but $(pwd) is not a Git checkout."
    echo "  Re-run from the reviewed Git checkout."
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

echo "▶ lifecycle trigger database preflight"
run_app php artisan database:verify-lifecycle-triggers preflight --json

echo "▶ npm ci && npm run build:ssr"
run_app npm ci
export NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=8192}"
run_app env NODE_OPTIONS="$NODE_OPTIONS" npm run build:ssr

echo "▶ entering maintenance mode and draining active writers"
run_app php artisan down --retry=60
MAINTENANCE_ACTIVE=1
if [ "$DEPLOY_WEB_DRAIN_SECONDS" -gt 0 ]; then
    sleep "$DEPLOY_WEB_DRAIN_SECONDS"
fi
mapfile -t pre_migration_queue_writer_pids < <(queue_writer_pids)
run_app php artisan queue:restart
wait_for_queue_writer_exit "${pre_migration_queue_writer_pids[@]}"

echo "▶ php artisan migrate --force --isolated=75"
run_app php artisan migrate --force --isolated=75

echo "▶ lifecycle trigger database postflight"
run_app php artisan database:verify-lifecycle-triggers postflight --json

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

echo "▶ php artisan inertia:check-ssr"
run_app php artisan inertia:check-ssr

if [ "$SKIP_MONITORING_SUPERVISOR" -eq 1 ]; then
    echo "▶ skipping monitoring Supervisor install (--skip-monitoring-supervisor)"
else
    echo "▶ scripts/monitoring/install-supervisor.sh"
    monitoring_supervisor_args=(
        "--application-path=$(pwd -P)"
        "--run-user=${MONITORING_RUNTIME_USER:-www-data}"
        "--log-directory=${MONITORING_LOG_DIRECTORY:-/var/log/oblivion}"
        "--allow-maintenance-paused-workers"
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
else
    echo "▶ skipping application-owned Queclink install (--skip-queclink; externally managed runtime)"
fi

echo "▶ verifying consecutive Queclink listener readiness"
queclink_consecutive_active=0
queclink_readiness_attempt=0
while [ "$queclink_readiness_attempt" -lt 10 ]; do
    queclink_readiness_attempt=$((queclink_readiness_attempt + 1))
    if systemctl is-active --quiet oblivion-queclink.service; then
        queclink_consecutive_active=$((queclink_consecutive_active + 1))
    else
        queclink_consecutive_active=0
    fi
    if [ "$queclink_consecutive_active" -ge 3 ]; then
        break
    fi
    if [ "$queclink_readiness_attempt" -lt 10 ]; then
        sleep 1
    fi
done
if [ "$queclink_consecutive_active" -lt 3 ]; then
    echo "✗ Queclink listener did not remain active for three consecutive readiness samples."
    exit 1
fi
run_app php artisan queclink:install --check

echo "▶ final application and lifecycle runtime validation"
run_app php artisan about --only=environment --json >/dev/null
run_app php artisan database:verify-lifecycle-triggers postflight --json

echo "▶ final Queclink listener readiness check"
run_app php artisan queclink:install --check

echo "▶ leaving maintenance mode"
run_app php artisan up

echo "▶ php artisan queue:restart after maintenance release"
run_app php artisan queue:restart

echo "▶ verifying monitoring supervision is bound to this exact release"
assert_stable_monitoring_runtime

MAINTENANCE_ACTIVE=0

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
