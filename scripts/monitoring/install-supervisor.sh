#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APPLICATION_PATH="$(cd -- "$SCRIPT_DIRECTORY/../.." && pwd)"
RUN_USER='www-data'
LOG_DIRECTORY='/var/log/oblivion'
INCLUDE_DIRECTORY="${MONITORING_SUPERVISOR_INCLUDE_DIR:-/etc/supervisor/conf.d}"
SUPERVISORD_CONFIG="${MONITORING_SUPERVISORD_CONFIG:-}"

WORKER_PROGRAMS=(
    oblivion-monitoring-events
    oblivion-monitoring-checks
    oblivion-monitoring-discovery
    oblivion-monitoring-provider
    oblivion-monitoring-topology
    oblivion-monitoring-maintenance
    oblivion-monitoring-orchestration
    oblivion-monitoring-commands
)
WORKER_QUEUES=(
    monitoring-events
    monitoring-checks
    monitoring-discovery
    monitoring-provider
    monitoring-topology
    monitoring-maintenance
    monitoring
    monitoring-commands
)
LISTENER_PROGRAMS=(
    oblivion-monitoring-snmp-traps
    oblivion-monitoring-syslog
    oblivion-monitoring-flow
)
LISTENER_COMMANDS=(
    monitoring:listen-snmp-traps
    monitoring:listen-syslog
    monitoring:listen-flow
)
EXPECTED_PROGRAMS=("${WORKER_PROGRAMS[@]}" "${LISTENER_PROGRAMS[@]}")

usage() {
    cat <<'EOF'
Usage: install-supervisor.sh [options]
  --application-path=/var/www/oblivionfindings
  --run-user=www-data
  --log-directory=/var/log/oblivion
  --include-directory=/etc/supervisor/conf.d
  --supervisord-config=/etc/supervisor/supervisord.conf
EOF
}

fail() {
    echo "Monitoring Supervisor installation refused: $1" >&2
    exit 1
}

for argument in "$@"; do
    case "$argument" in
        --application-path=*) APPLICATION_PATH="${argument#*=}" ;;
        --run-user=*) RUN_USER="${argument#*=}" ;;
        --log-directory=*) LOG_DIRECTORY="${argument#*=}" ;;
        --include-directory=*) INCLUDE_DIRECTORY="${argument#*=}" ;;
        --supervisord-config=*) SUPERVISORD_CONFIG="${argument#*=}" ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; fail "unknown option $argument" ;;
    esac
done

[[ "$EUID" -eq 0 ]] || fail 'run as root or through sudo.'
for command_name in awk cp flock grep id install mktemp mv readlink rm rmdir sleep supervisorctl tr wc; do
    command -v "$command_name" >/dev/null 2>&1 || fail "required command $command_name is unavailable."
done
[[ -x /bin/true ]] || fail 'the non-starting Supervisor discovery probe command is unavailable.'

[[ "$APPLICATION_PATH" == /* && "$APPLICATION_PATH" != *[[:space:]]* ]] || fail 'application path must be one absolute path without whitespace.'
APPLICATION_PATH="$(readlink -f "$APPLICATION_PATH")"
[[ -d "$APPLICATION_PATH" && -f "$APPLICATION_PATH/artisan" ]] || fail 'application path is not a complete Oblivion Findings release.'
[[ "$LOG_DIRECTORY" == /* && "$(readlink -m "$LOG_DIRECTORY")" == "$LOG_DIRECTORY" ]] || fail 'log directory must be one normalised absolute path.'
[[ "$INCLUDE_DIRECTORY" == /* && "$(readlink -m "$INCLUDE_DIRECTORY")" == "$INCLUDE_DIRECTORY" ]] || fail 'Supervisor include directory must be one normalised absolute path.'
[[ -d "$INCLUDE_DIRECTORY" ]] || fail 'Supervisor include directory does not exist; supply the daemon included path explicitly.'
[[ "$RUN_USER" =~ ^[a-z_][a-z0-9_-]*[$]?$ ]] || fail 'the configured runtime user name is invalid.'
id "$RUN_USER" >/dev/null 2>&1 || fail 'the configured runtime user does not exist.'
RUN_GROUP="$(id -gn "$RUN_USER")"

if [[ -z "$SUPERVISORD_CONFIG" ]]; then
    for candidate in /etc/supervisor/supervisord.conf /etc/supervisord.conf; do
        if [[ -f "$candidate" ]]; then
            SUPERVISORD_CONFIG="$candidate"
            break
        fi
    done
fi
[[ -n "$SUPERVISORD_CONFIG" && "$SUPERVISORD_CONFIG" == /* && -f "$SUPERVISORD_CONFIG" ]] \
    || fail 'the active supervisord configuration is unavailable; supply --supervisord-config.'

WORKER_SOURCE="$APPLICATION_PATH/ops/supervisor/oblivion-monitoring-workers.conf"
LISTENER_SOURCE="$APPLICATION_PATH/ops/supervisor/oblivion-monitoring-listeners.conf"
for source_file in "$WORKER_SOURCE" "$LISTENER_SOURCE"; do
    [[ -f "$source_file" && ! -L "$source_file" && -r "$source_file" ]] || fail "required source configuration is unavailable: $source_file"
done

program_section() {
    local file="$1"
    local program="$2"
    awk -v header="[program:${program}]" '
        $0 == header { active = 1; next }
        /^\[program:/ && active { exit }
        active { print }
    ' "$file"
}

program_declaration_count() {
    local program="$1"

    awk -v header="[program:${program}]" '
        $0 == header { count++ }
        END { print count + 0 }
    ' "$WORKER_SOURCE" "$LISTENER_SOURCE"
}

combined_program_count="$(grep -h -E '^\[program:[^]]+\]$' "$WORKER_SOURCE" "$LISTENER_SOURCE" | wc -l | tr -d '[:space:]')"
[[ "$combined_program_count" -eq "${#EXPECTED_PROGRAMS[@]}" ]] || fail 'the source definitions contain an unexpected program count.'

for index in "${!WORKER_PROGRAMS[@]}"; do
    program="${WORKER_PROGRAMS[$index]}"
    queue="${WORKER_QUEUES[$index]}"
    [[ "$(program_declaration_count "$program")" -eq 1 ]] \
        || fail "worker program $program must be declared exactly once."
    section="$(program_section "$WORKER_SOURCE" "$program")"
    [[ -n "$section" ]] || fail "worker program $program has no configuration."
    grep -Fqx "directory=$APPLICATION_PATH" <<< "$section" || fail "worker program $program has the wrong application path."
    grep -Fqx "user=$RUN_USER" <<< "$section" || fail "worker program $program has the wrong runtime user."
    grep -Fqx "autostart=true" <<< "$section" || fail "worker program $program is not configured for supervised start."
    grep -Fqx "stdout_logfile=$LOG_DIRECTORY/${program#oblivion-}.log" <<< "$section" \
        || fail "worker program $program has the wrong log path."
    grep -Fq "command=php $APPLICATION_PATH/artisan queue:work redis " <<< "$section" \
        || fail "worker program $program does not use the exact application release."
    grep -Eq -- "(^|[[:space:]])--queue=${queue}([[:space:]]|$)" <<< "$section" \
        || fail "worker program $program does not isolate queue $queue."
    [[ "$(grep -o -- '--queue=[^[:space:]]*' <<< "$section" | wc -l | tr -d '[:space:]')" -eq 1 ]] \
        || fail "worker program $program must declare exactly one queue."
done

for index in "${!LISTENER_PROGRAMS[@]}"; do
    program="${LISTENER_PROGRAMS[$index]}"
    listener_command="${LISTENER_COMMANDS[$index]}"
    [[ "$(program_declaration_count "$program")" -eq 1 ]] \
        || fail "listener program $program must be declared exactly once."
    section="$(program_section "$LISTENER_SOURCE" "$program")"
    [[ -n "$section" ]] || fail "listener program $program has no configuration."
    grep -Fqx "command=php $APPLICATION_PATH/artisan $listener_command" <<< "$section" \
        || fail "listener program $program has the wrong command or application release."
    grep -Fqx "directory=$APPLICATION_PATH" <<< "$section" || fail "listener program $program has the wrong application path."
    grep -Fqx "user=$RUN_USER" <<< "$section" || fail "listener program $program has the wrong runtime user."
    grep -Fqx "autostart=true" <<< "$section" || fail "listener program $program is not configured for supervised start."
    grep -Fqx "stdout_logfile=$LOG_DIRECTORY/${program#oblivion-}.log" <<< "$section" \
        || fail "listener program $program has the wrong log path."
done

LOCK_FILE='/run/lock/oblivion-monitoring-supervisor-install.lock'
exec 9>"$LOCK_FILE"
flock -n 9 || fail 'another monitoring Supervisor installation is active.'

supervisorctl -c "$SUPERVISORD_CONFIG" pid >/dev/null \
    || fail 'the configured Supervisor daemon is not reachable.'

install -d -o "$RUN_USER" -g "$RUN_GROUP" -m 0750 "$LOG_DIRECTORY"

WORKER_TARGET="$INCLUDE_DIRECTORY/oblivion-monitoring-workers.conf"
LISTENER_TARGET="$INCLUDE_DIRECTORY/oblivion-monitoring-listeners.conf"
[[ ! -L "$WORKER_TARGET" && ! -L "$LISTENER_TARGET" ]] || fail 'monitoring Supervisor targets must not be symbolic links.'
WORKER_STAGE="$INCLUDE_DIRECTORY/.oblivion-monitoring-workers.conf.$$"
LISTENER_STAGE="$INCLUDE_DIRECTORY/.oblivion-monitoring-listeners.conf.$$"
PROBE_PROGRAM="oblivion-monitoring-install-probe-$$"
PROBE_FILE="$INCLUDE_DIRECTORY/$PROBE_PROGRAM.conf"
BACKUP_DIRECTORY="$(mktemp -d /tmp/oblivion-monitoring-supervisor.XXXXXX)"
FILES_INSTALLED=false
INSTALL_COMMITTED=false
PROBE_PRESENT=false
HAD_WORKER_TARGET=false
HAD_LISTENER_TARGET=false

cleanup() {
    local exit_code=$?
    rm -f "$WORKER_STAGE" "$LISTENER_STAGE"
    if [[ "$PROBE_PRESENT" == true ]]; then
        rm -f "$PROBE_FILE"
        supervisorctl -c "$SUPERVISORD_CONFIG" reread >/dev/null 2>&1 || true
    fi
    if [[ "$FILES_INSTALLED" == true && "$INSTALL_COMMITTED" != true ]]; then
        if [[ "$HAD_WORKER_TARGET" == true ]]; then
            install -o root -g root -m 0644 "$BACKUP_DIRECTORY/oblivion-monitoring-workers.conf" "$WORKER_TARGET"
        else
            rm -f "$WORKER_TARGET"
        fi
        if [[ "$HAD_LISTENER_TARGET" == true ]]; then
            install -o root -g root -m 0644 "$BACKUP_DIRECTORY/oblivion-monitoring-listeners.conf" "$LISTENER_TARGET"
        else
            rm -f "$LISTENER_TARGET"
        fi
        supervisorctl -c "$SUPERVISORD_CONFIG" reread >/dev/null 2>&1 || true
    fi
    rm -f "$BACKUP_DIRECTORY/oblivion-monitoring-workers.conf" "$BACKUP_DIRECTORY/oblivion-monitoring-listeners.conf"
    rmdir "$BACKUP_DIRECTORY" 2>/dev/null || true
    return "$exit_code"
}
trap cleanup EXIT

[[ ! -e "$PROBE_FILE" ]] || fail 'the temporary Supervisor discovery probe path is already in use.'
PROBE_PRESENT=true
printf '%s\n' \
    "[program:$PROBE_PROGRAM]" \
    'command=/bin/true' \
    'autostart=false' \
    'autorestart=false' > "$PROBE_FILE"
chmod 0644 "$PROBE_FILE"
supervisorctl -c "$SUPERVISORD_CONFIG" reread >/dev/null \
    || fail 'the running Supervisor daemon rejected the include path probe.'
probe_programs="$(supervisorctl -c "$SUPERVISORD_CONFIG" avail)" \
    || fail 'Supervisor could not report the include path probe.'
grep -Eq "(^|[[:space:]])${PROBE_PROGRAM}(:|[[:space:]]|$)" <<< "$probe_programs" \
    || fail "Supervisor does not discover .conf files from $INCLUDE_DIRECTORY."
rm -f "$PROBE_FILE"
supervisorctl -c "$SUPERVISORD_CONFIG" reread >/dev/null
PROBE_PRESENT=false

if [[ -f "$WORKER_TARGET" ]]; then
    cp -p "$WORKER_TARGET" "$BACKUP_DIRECTORY/oblivion-monitoring-workers.conf"
    HAD_WORKER_TARGET=true
fi
if [[ -f "$LISTENER_TARGET" ]]; then
    cp -p "$LISTENER_TARGET" "$BACKUP_DIRECTORY/oblivion-monitoring-listeners.conf"
    HAD_LISTENER_TARGET=true
fi

install -o root -g root -m 0644 "$WORKER_SOURCE" "$WORKER_STAGE"
install -o root -g root -m 0644 "$LISTENER_SOURCE" "$LISTENER_STAGE"
mv -f "$WORKER_STAGE" "$WORKER_TARGET"
mv -f "$LISTENER_STAGE" "$LISTENER_TARGET"
FILES_INSTALLED=true

supervisorctl -c "$SUPERVISORD_CONFIG" reread \
    || fail 'the running Supervisor daemon rejected the complete monitoring configuration.'
available_programs="$(supervisorctl -c "$SUPERVISORD_CONFIG" avail)" \
    || fail 'Supervisor could not report discovered program definitions.'
for program in "${EXPECTED_PROGRAMS[@]}"; do
    grep -Eq "(^|[[:space:]])${program}(:|[[:space:]]|$)" <<< "$available_programs" \
        || fail "Supervisor did not discover $program from $INCLUDE_DIRECTORY."
done

# One scoped update occurs only after both files and all eleven programs pass
# source, daemon-config, and discovery validation. Unrelated groups are untouched.
supervisorctl -c "$SUPERVISORD_CONFIG" update "${EXPECTED_PROGRAMS[@]}"
INSTALL_COMMITTED=true

# An unchanged Supervisor definition does not restart a previously failed
# listener or reload an already-running worker onto the deployed release. Every
# expected group is therefore restarted explicitly after the scoped update.
for program in "${EXPECTED_PROGRAMS[@]}"; do
    supervisorctl -c "$SUPERVISORD_CONFIG" restart "$program:*" \
        || fail "Supervisor could not restart $program on the deployed release."
done

for attempt in {1..30}; do
    all_running=true
    for program in "${EXPECTED_PROGRAMS[@]}"; do
        if status_output="$(supervisorctl -c "$SUPERVISORD_CONFIG" status "$program:*")"; then
            awk 'NF < 2 || $2 != "RUNNING" { exit 1 } END { if (NR == 0) exit 1 }' <<< "$status_output" \
                || all_running=false
        else
            all_running=false
        fi
    done
    [[ "$all_running" == true ]] && break
    sleep 1
done
[[ "$all_running" == true ]] || fail 'one or more monitoring Supervisor programs did not reach RUNNING state.'

echo 'Monitoring Supervisor runtime installed and running.'
echo "Application: $APPLICATION_PATH"
echo "Runtime user: $RUN_USER"
echo "Include directory: $INCLUDE_DIRECTORY"
echo "Status: supervisorctl -c $SUPERVISORD_CONFIG status"
