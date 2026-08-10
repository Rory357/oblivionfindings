#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APPLICATION_PATH="$(cd -- "$SCRIPT_DIRECTORY/../.." && pwd)"
RUN_USER='www-data'
LOG_DIRECTORY='/var/log/oblivion'
INCLUDE_DIRECTORY="${INERTIA_SSR_SUPERVISOR_INCLUDE_DIR:-/etc/supervisor/conf.d}"
SUPERVISORD_CONFIG="${INERTIA_SSR_SUPERVISORD_CONFIG:-}"
PROGRAM='oblivion-inertia-ssr'

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
    echo "Inertia SSR Supervisor installation refused: $1" >&2
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
for command_name in awk chmod cp env flock grep id install mktemp mv node php readlink rm rmdir runuser sleep supervisorctl; do
    command -v "$command_name" >/dev/null 2>&1 || fail "required command $command_name is unavailable."
done

[[ "$APPLICATION_PATH" == /* && "$APPLICATION_PATH" != *[[:space:]]* ]] \
    || fail 'application path must be one absolute path without whitespace.'
APPLICATION_PATH="$(readlink -f "$APPLICATION_PATH")"
[[ -d "$APPLICATION_PATH" && -f "$APPLICATION_PATH/artisan" ]] \
    || fail 'application path is not a complete Oblivion Findings release.'
[[ "$LOG_DIRECTORY" == /* && "$LOG_DIRECTORY" != *[[:space:]]* && "$(readlink -m "$LOG_DIRECTORY")" == "$LOG_DIRECTORY" ]] \
    || fail 'log directory must be one normalised absolute path without whitespace.'
[[ "$INCLUDE_DIRECTORY" == /* && "$INCLUDE_DIRECTORY" != *[[:space:]]* && "$(readlink -m "$INCLUDE_DIRECTORY")" == "$INCLUDE_DIRECTORY" ]] \
    || fail 'Supervisor include directory must be one normalised absolute path without whitespace.'
[[ -d "$INCLUDE_DIRECTORY" ]] \
    || fail 'Supervisor include directory does not exist; supply the daemon included path explicitly.'
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

PHP_BINARY="$(command -v php)"
NODE_BINARY="$(command -v node)"
[[ -n "$PHP_BINARY" && -n "$NODE_BINARY" ]] || fail 'php and node are both required for Inertia SSR.'
PHP_BINARY="$(readlink -f "$PHP_BINARY")"
NODE_BINARY="$(readlink -f "$NODE_BINARY")"
[[ -x "$PHP_BINARY" && -x "$NODE_BINARY" ]] || fail 'php and node must resolve to executable files.'
NODE_DIRECTORY="${NODE_BINARY%/*}"
RUNTIME_PATH="$NODE_DIRECTORY:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

run_runtime() {
    runuser -u "$RUN_USER" -- "$@"
}

run_runtime env PATH="$RUNTIME_PATH" node --version >/dev/null \
    || fail 'node is not executable by the configured runtime user.'
SSR_BUNDLE="$APPLICATION_PATH/bootstrap/ssr/ssr.js"
[[ -f "$SSR_BUNDLE" && ! -L "$SSR_BUNDLE" ]] || fail 'the production SSR bundle is unavailable; run npm run build:ssr.'
run_runtime "$PHP_BINARY" -r 'exit(is_readable($argv[1]) ? 0 : 1);' "$SSR_BUNDLE" \
    || fail 'the production SSR bundle is not readable by the configured runtime user.'

artisan_commands="$(run_runtime "$PHP_BINARY" "$APPLICATION_PATH/artisan" list --raw)" \
    || fail 'Artisan commands could not be inspected as the configured runtime user.'
grep -Eq '^inertia:start-ssr([[:space:]]|$)' <<< "$artisan_commands" \
    || fail 'the installed Inertia package does not provide inertia:start-ssr.'
grep -Eq '^inertia:check-ssr([[:space:]]|$)' <<< "$artisan_commands" \
    || fail 'the installed Inertia package does not provide inertia:check-ssr.'

LOCK_FILE='/run/lock/oblivion-inertia-ssr-supervisor-install.lock'
exec 9>"$LOCK_FILE"
flock -n 9 || fail 'another Inertia SSR Supervisor installation is active.'

supervisorctl -c "$SUPERVISORD_CONFIG" pid >/dev/null \
    || fail 'the configured Supervisor daemon is not reachable.'
install -d -o "$RUN_USER" -g "$RUN_GROUP" -m 0750 "$LOG_DIRECTORY"

TARGET="$INCLUDE_DIRECTORY/$PROGRAM.conf"
[[ ! -L "$TARGET" ]] || fail 'the Inertia SSR Supervisor target must not be a symbolic link.'
STAGE="$INCLUDE_DIRECTORY/.$PROGRAM.conf.$$"
BACKUP_DIRECTORY="$(mktemp -d /tmp/oblivion-inertia-ssr-supervisor.XXXXXX)"
FILES_INSTALLED=false
INSTALL_COMMITTED=false
HAD_TARGET=false

cleanup() {
    local exit_code=$?
    rm -f "$STAGE"
    if [[ "$FILES_INSTALLED" == true && "$INSTALL_COMMITTED" != true ]]; then
        if [[ "$HAD_TARGET" == true ]]; then
            install -o root -g root -m 0644 "$BACKUP_DIRECTORY/$PROGRAM.conf" "$TARGET"
        else
            rm -f "$TARGET"
        fi
        supervisorctl -c "$SUPERVISORD_CONFIG" reread >/dev/null 2>&1 || true
    fi
    rm -f "$BACKUP_DIRECTORY/$PROGRAM.conf"
    rmdir "$BACKUP_DIRECTORY" 2>/dev/null || true
    return "$exit_code"
}
trap cleanup EXIT

if [[ -f "$TARGET" ]]; then
    cp -p "$TARGET" "$BACKUP_DIRECTORY/$PROGRAM.conf"
    HAD_TARGET=true
fi

printf '%s\n' \
    "[program:$PROGRAM]" \
    "command=$PHP_BINARY $APPLICATION_PATH/artisan inertia:start-ssr --runtime=node" \
    "directory=$APPLICATION_PATH" \
    "user=$RUN_USER" \
    'numprocs=1' \
    'autostart=true' \
    'autorestart=true' \
    'startsecs=3' \
    'startretries=3' \
    'stopasgroup=true' \
    'killasgroup=true' \
    'stopwaitsecs=30' \
    'redirect_stderr=true' \
    "stdout_logfile=$LOG_DIRECTORY/inertia-ssr.log" \
    'stdout_logfile_maxbytes=50MB' \
    'stdout_logfile_backups=10' \
    "environment=NODE_ENV=\"production\",PATH=\"$RUNTIME_PATH\"" > "$STAGE"
chmod 0644 "$STAGE"
mv -f "$STAGE" "$TARGET"
FILES_INSTALLED=true

supervisorctl -c "$SUPERVISORD_CONFIG" reread \
    || fail 'the running Supervisor daemon rejected the updated configuration.'
available_programs="$(supervisorctl -c "$SUPERVISORD_CONFIG" avail)" \
    || fail 'Supervisor could not report discovered program definitions.'
grep -Eq "(^|[[:space:]])${PROGRAM}(:|[[:space:]]|$)" <<< "$available_programs" \
    || fail "Supervisor did not discover $PROGRAM from $INCLUDE_DIRECTORY."

supervisorctl -c "$SUPERVISORD_CONFIG" update "$PROGRAM"
INSTALL_COMMITTED=true

# Reload the newly-built bundle even when the Supervisor definition did not change.
supervisorctl -c "$SUPERVISORD_CONFIG" restart "$PROGRAM"

ssr_running=false
for attempt in {1..10}; do
    if status_output="$(supervisorctl -c "$SUPERVISORD_CONFIG" status "$PROGRAM" 2>/dev/null)" \
        && awk 'NF >= 2 && $2 == "RUNNING" { found = 1 } END { exit(found ? 0 : 1) }' <<< "$status_output"; then
        ssr_running=true
        break
    fi
    sleep 1
done
[[ "$ssr_running" == true ]] || fail 'the Inertia SSR Supervisor program did not reach RUNNING state.'

ssr_healthy=false
for attempt in {1..10}; do
    if run_runtime env PATH="$RUNTIME_PATH" "$PHP_BINARY" "$APPLICATION_PATH/artisan" inertia:check-ssr >/dev/null 2>&1; then
        ssr_healthy=true
        break
    fi
    sleep 1
done
[[ "$ssr_healthy" == true ]] || fail 'inertia:check-ssr did not confirm a healthy SSR gateway.'

echo 'Inertia SSR Supervisor runtime installed, running, and healthy.'
echo "Application: $APPLICATION_PATH"
echo "Runtime user: $RUN_USER"
echo "Include directory: $INCLUDE_DIRECTORY"
echo "Status: supervisorctl -c $SUPERVISORD_CONFIG status $PROGRAM"
