#!/usr/bin/env bash
set -euo pipefail

SERVICE_USER='oblivion-monitoring-collector'
SERVICE_GROUP='oblivion-monitoring-collector'
STATE_DIRECTORY='/var/lib/oblivion-monitoring-collector'
INSTALLED_ENVIRONMENT='/etc/oblivion/monitoring-collector.env'
INSTALLED_RUNNER='/usr/local/libexec/oblivion-monitoring-collector-run'
SERVICE_UNIT='/etc/systemd/system/oblivion-monitoring-collector.service'
TIMER_UNIT='/etc/systemd/system/oblivion-monitoring-collector.timer'

SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APPLICATION_DIRECTORY="$(cd -- "$SCRIPT_DIRECTORY/../.." && pwd)"
ENVIRONMENT_SOURCE="$APPLICATION_DIRECTORY/ops/systemd/monitoring-collector.env"

usage() {
    echo 'Usage: install-collector-systemd.sh --environment-file=/root/monitoring-collector.env'
}

fail() {
    echo "Collector service installation refused: $1" >&2
    exit 1
}

for argument in "$@"; do
    case "$argument" in
        --environment-file=*) ENVIRONMENT_SOURCE="${argument#*=}" ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; fail "unknown option $argument" ;;
    esac
done

[[ "$EUID" -eq 0 ]] || fail 'run as root after reviewing the environment and unit files.'
[[ -d /run/systemd/system && -x "$(command -v systemctl || true)" ]] || fail 'an active systemd host is required.'

for command_name in chmod chown cut getent grep groupadd install readlink stat systemctl useradd flock; do
    command -v "$command_name" >/dev/null 2>&1 || fail "required command $command_name is unavailable."
done

[[ -f "$ENVIRONMENT_SOURCE" && ! -L "$ENVIRONMENT_SOURCE" ]] || fail 'the environment source must be a regular non-symlink file.'
[[ "$(stat -c '%u' "$ENVIRONMENT_SOURCE")" -eq 0 ]] || fail 'the environment source must be owned by root.'
environment_mode="$(stat -c '%a' "$ENVIRONMENT_SOURCE")"
(( (8#$environment_mode & 0077) == 0 )) || fail 'the environment source must not be readable or writable by group or others.'

allowed_environment_keys=(
    OBLIVION_COLLECTOR_PHP_BINARY
    OBLIVION_COLLECTOR_ARTIFACT_DIR
    OBLIVION_COLLECTOR_IDENTITY_FILE
    OBLIVION_COLLECTOR_CONFIG_FILE
)

while IFS= read -r environment_line || [[ -n "$environment_line" ]]; do
    [[ -z "$environment_line" || "$environment_line" == \#* ]] && continue
    accepted=false
    for environment_key in "${allowed_environment_keys[@]}"; do
        if [[ "$environment_line" == "$environment_key="* ]]; then
            accepted=true
            break
        fi
    done
    [[ "$accepted" == true ]] || fail 'the environment source contains an unknown or secret-bearing key.'
done < "$ENVIRONMENT_SOURCE"

environment_value() {
    local key="$1"
    local matches=()
    mapfile -t matches < <(grep -E "^${key}=" "$ENVIRONMENT_SOURCE" || true)
    [[ "${#matches[@]}" -eq 1 ]] || fail "the environment source must define $key exactly once."
    local value="${matches[0]#*=}"
    [[ -n "$value" && "$value" == /* && "$value" != *[[:space:]]* ]] || fail "$key must be one absolute path without whitespace."
    printf '%s' "$value"
}

PHP_BINARY="$(environment_value OBLIVION_COLLECTOR_PHP_BINARY)"
ARTIFACT_DIRECTORY="$(environment_value OBLIVION_COLLECTOR_ARTIFACT_DIR)"
IDENTITY_FILE="$(environment_value OBLIVION_COLLECTOR_IDENTITY_FILE)"
CONFIG_FILE="$(environment_value OBLIVION_COLLECTOR_CONFIG_FILE)"

[[ "$(readlink -m "$IDENTITY_FILE")" == "$IDENTITY_FILE" && "$IDENTITY_FILE" == "$STATE_DIRECTORY/"* ]] \
    || fail 'the identity file must be a normalised path inside the private collector state directory.'
[[ "$(readlink -m "$CONFIG_FILE")" == "$CONFIG_FILE" && "$CONFIG_FILE" == "$STATE_DIRECTORY/"* ]] \
    || fail 'the configuration file must be a normalised path inside the private collector state directory.'
[[ "$(readlink -m "$ARTIFACT_DIRECTORY")" == "$ARTIFACT_DIRECTORY" && "$ARTIFACT_DIRECTORY" == /opt/oblivion-monitoring-collector/* ]] \
    || fail 'the artifact directory must be a normalised path below /opt/oblivion-monitoring-collector.'

[[ -x "$PHP_BINARY" ]] || fail 'the configured PHP binary is unavailable or not executable.'
[[ -x /usr/sbin/nologin ]] || fail 'the non-login service shell /usr/sbin/nologin is unavailable.'
"$PHP_BINARY" -r '
    $required = ["curl", "json", "openssl", "sockets", "sodium"];
    if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) { exit(10); }
    foreach ($required as $extension) { if (! extension_loaded($extension)) { exit(11); } }
' || fail 'PHP 8.4 with curl, json, openssl, sockets, and sodium is required.'

[[ -r "$ARTIFACT_DIRECTORY/bin/oblivion-collector" ]] || fail 'the prebuilt collector entrypoint is unavailable.'
[[ -r "$ARTIFACT_DIRECTORY/vendor/autoload.php" ]] || fail 'the prebuilt production Composer artifact is incomplete.'
[[ -d "$ARTIFACT_DIRECTORY/src" && -r "$ARTIFACT_DIRECTORY/composer.json" ]] || fail 'the collector source artifact is incomplete.'

if getent group "$SERVICE_GROUP" >/dev/null; then
    :
else
    groupadd --system "$SERVICE_GROUP"
fi

if getent passwd "$SERVICE_USER" >/dev/null; then
    user_home="$(getent passwd "$SERVICE_USER" | cut -d: -f6)"
    user_shell="$(getent passwd "$SERVICE_USER" | cut -d: -f7)"
    user_group_id="$(getent passwd "$SERVICE_USER" | cut -d: -f4)"
    service_group_id="$(getent group "$SERVICE_GROUP" | cut -d: -f3)"
    [[ "$user_home" == "$STATE_DIRECTORY" ]] || fail 'the existing collector service user has an unexpected home directory.'
    [[ "$user_shell" == '/usr/sbin/nologin' || "$user_shell" == '/sbin/nologin' ]] || fail 'the existing collector service user is not non-login.'
    [[ "$user_group_id" == "$service_group_id" ]] || fail 'the existing collector service user has an unexpected primary group.'
else
    useradd \
        --system \
        --gid "$SERVICE_GROUP" \
        --home-dir "$STATE_DIRECTORY" \
        --shell /usr/sbin/nologin \
        --no-create-home \
        "$SERVICE_USER"
fi

install -d -o "$SERVICE_USER" -g "$SERVICE_GROUP" -m 0700 "$STATE_DIRECTORY"

if [[ ! -f "$IDENTITY_FILE" || -L "$IDENTITY_FILE" ]]; then
    fail "collector identity is missing; enrol it separately as $SERVICE_USER, then rerun this installer."
fi

identity_validation="$({
    OBLIVION_COLLECTOR_IDENTITY_FILE="$IDENTITY_FILE" \
    OBLIVION_COLLECTOR_EXPECTED_STATE="$STATE_DIRECTORY" \
    "$PHP_BINARY" -r '
        $identityPath = getenv("OBLIVION_COLLECTOR_IDENTITY_FILE");
        $expectedState = getenv("OBLIVION_COLLECTOR_EXPECTED_STATE");
        try {
            $identity = json_decode((string) file_get_contents($identityPath), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            exit(20);
        }
        foreach (["collector_id", "central_url", "tls_public_key_pin", "central_signing_public_key", "request_signing_secret_key", "state_directory", "client_certificate_file", "client_private_key_file", "client_certificate_fingerprint"] as $key) {
            if (! is_string($identity[$key] ?? null) || trim($identity[$key]) === "") { exit(21); }
        }
        if (! is_int($identity["site_id"] ?? null)
            || $identity["site_id"] < 1
            || $identity["state_directory"] !== $expectedState
            || preg_match("/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/i", $identity["collector_id"]) !== 1
            || preg_match("/\\A[a-f0-9]{64}\\z/", $identity["client_certificate_fingerprint"]) !== 1) { exit(22); }
        $statePrefix = rtrim($expectedState, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $resolved = [];
        foreach (["client_certificate_file", "client_private_key_file"] as $key) {
            $path = $identity[$key];
            $real = realpath($path);
            if ($real === false || ! is_file($real) || ! str_starts_with($real, $statePrefix)) { exit(23); }
            $resolved[$key] = $real;
        }
        $certificateSize = filesize($resolved["client_certificate_file"]);
        $certificatePem = is_int($certificateSize) && $certificateSize > 0 && $certificateSize <= 262144
            ? file_get_contents($resolved["client_certificate_file"])
            : false;
        $privateKeySize = filesize($resolved["client_private_key_file"]);
        $privateKeyPem = is_int($privateKeySize) && $privateKeySize > 0 && $privateKeySize <= 262144
            ? file_get_contents($resolved["client_private_key_file"])
            : false;
        $certificate = is_string($certificatePem) ? openssl_x509_read($certificatePem) : false;
        $privateKey = is_string($privateKeyPem) ? openssl_pkey_get_private($privateKeyPem) : false;
        $parsed = $certificate === false ? false : openssl_x509_parse($certificate, false);
        $fingerprint = $certificate === false ? false : openssl_x509_fingerprint($certificate, "sha256");
        $now = time();
        if (! is_array($parsed)
            || $privateKey === false
            || ! openssl_x509_check_private_key($certificate, $privateKey)
            || ! is_string($fingerprint)
            || ! hash_equals($identity["client_certificate_fingerprint"], strtolower($fingerprint))
            || ($parsed["subject"]["CN"] ?? null) !== "oblivion-collector-".$identity["collector_id"]
            || ! is_int($parsed["validFrom_time_t"] ?? null)
            || ! is_int($parsed["validTo_time_t"] ?? null)
            || $now < $parsed["validFrom_time_t"]
            || $now >= $parsed["validTo_time_t"]) { exit(24); }
        echo $resolved["client_certificate_file"], PHP_EOL;
        echo $resolved["client_private_key_file"], PHP_EOL;
    '
})" || fail 'the collector identity is invalid, incomplete, or references material outside its private state directory.'
mapfile -t identity_files <<< "$identity_validation"
[[ "${#identity_files[@]}" -eq 2 ]] || fail 'the collector identity did not resolve exactly one certificate and private key.'

chown -R "$SERVICE_USER:$SERVICE_GROUP" "$STATE_DIRECTORY"
chmod 0700 "$STATE_DIRECTORY"
chmod 0600 "$IDENTITY_FILE" "${identity_files[@]}"
[[ ! -e "$CONFIG_FILE" || ( -f "$CONFIG_FILE" && ! -L "$CONFIG_FILE" ) ]] || fail 'the collector configuration path is not a regular file.'
[[ ! -f "$CONFIG_FILE" ]] || chmod 0600 "$CONFIG_FILE"

install -d -o root -g root -m 0755 /etc/oblivion /usr/local/libexec /etc/systemd/system
if [[ "$(readlink -m "$ENVIRONMENT_SOURCE")" != "$INSTALLED_ENVIRONMENT" ]]; then
    install -o root -g root -m 0600 "$ENVIRONMENT_SOURCE" "$INSTALLED_ENVIRONMENT"
else
    chown root:root "$INSTALLED_ENVIRONMENT"
    chmod 0600 "$INSTALLED_ENVIRONMENT"
fi
install -o root -g root -m 0755 "$APPLICATION_DIRECTORY/ops/systemd/oblivion-monitoring-collector-run" "$INSTALLED_RUNNER"
install -o root -g root -m 0644 "$APPLICATION_DIRECTORY/ops/systemd/oblivion-monitoring-collector.service" "$SERVICE_UNIT"
install -o root -g root -m 0644 "$APPLICATION_DIRECTORY/ops/systemd/oblivion-monitoring-collector.timer" "$TIMER_UNIT"

systemctl daemon-reload
systemctl enable --now oblivion-monitoring-collector.timer

echo 'Collector systemd timer installed.'
echo 'Status: systemctl status oblivion-monitoring-collector.timer oblivion-monitoring-collector.service'
echo 'Next run: systemctl list-timers oblivion-monitoring-collector.timer'
echo 'Logs: journalctl -u oblivion-monitoring-collector.service'
