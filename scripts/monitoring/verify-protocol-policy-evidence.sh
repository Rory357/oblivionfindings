#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APPLICATION_PATH="$(cd -- "$SCRIPT_DIRECTORY/../.." && pwd)"
SAMPLES=15
INTERVAL_SECONDS=60
WINDOW_MINUTES=60

usage() {
    cat <<'EOF'
Usage: verify-protocol-policy-evidence.sh [options]
  --application-path=/var/www/oblivionfindings
  --samples=15
  --interval-seconds=60
  --window-minutes=60
EOF
}

fail() {
    echo "Protocol and policy verification failed: $1" >&2
    exit 1
}

for argument in "$@"; do
    case "$argument" in
        --application-path=*) APPLICATION_PATH="${argument#*=}" ;;
        --samples=*) SAMPLES="${argument#*=}" ;;
        --interval-seconds=*) INTERVAL_SECONDS="${argument#*=}" ;;
        --window-minutes=*) WINDOW_MINUTES="${argument#*=}" ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; fail "unknown option $argument" ;;
    esac
done

for command_name in date php readlink sleep; do
    command -v "$command_name" >/dev/null 2>&1 || fail "required command $command_name is unavailable."
done
[[ "$APPLICATION_PATH" == /* && "$APPLICATION_PATH" != *[[:space:]]* ]] \
    || fail 'application path must be one absolute path without whitespace.'
APPLICATION_PATH="$(readlink -f "$APPLICATION_PATH")"
[[ -d "$APPLICATION_PATH" && -f "$APPLICATION_PATH/artisan" ]] \
    || fail 'application path is not a complete Oblivion Findings release.'
[[ "$SAMPLES" =~ ^[0-9]+$ && "$SAMPLES" -ge 5 ]] \
    || fail 'samples must be an integer of at least 5.'
[[ "$INTERVAL_SECONDS" =~ ^[0-9]+$ && "$INTERVAL_SECONDS" -ge 60 ]] \
    || fail 'interval-seconds must be an integer of at least 60.'
[[ "$WINDOW_MINUTES" =~ ^[0-9]+$ && "$WINDOW_MINUTES" -ge 5 && "$WINDOW_MINUTES" -le 10080 ]] \
    || fail 'window-minutes must be an integer from 5 to 10080.'

cd "$APPLICATION_PATH"
started_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
evidence_matrix_fingerprint=''
previous_execution_cursor=''
execution_advanced=false
for ((sample = 1; sample <= SAMPLES; sample++)); do
    report="$(php artisan monitoring:protocol-policy-evidence \
        --window-minutes="$WINDOW_MINUTES" \
        --json)" || fail 'one or more protocol or policy evidence checks are not verified.'
    sample_fingerprints="$(printf '%s' "$report" | php -r '
        try {
            $report = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            exit(20);
        }
        if (! is_array($report)
            || ($report["all_verified"] ?? null) !== true
            || ! is_array($report["protocols"] ?? null)
            || ! is_array($report["policy"] ?? null)) {
            exit(21);
        }
        $requiredProtocols = [
            "icmp", "tcp", "dns", "http", "https", "tls", "snmp_v3",
            "snmp_traps", "syslog", "flow", "ssh_read_only", "winrm_read_only",
        ];
        $requiredPolicy = [
            "profiles", "coverage", "dependencies", "maintenance", "confirmation",
            "hysteresis", "stale_unknown", "baselines", "rollups",
        ];
        $requiredProviders = ["provider_unifi", "provider_milesight"];
        foreach ($requiredProtocols as $key) {
            if (! array_key_exists($key, $report["protocols"])) {
                exit(22);
            }
        }
        foreach ($requiredPolicy as $key) {
            if (! array_key_exists($key, $report["policy"])) {
                exit(23);
            }
        }
        foreach ($requiredProviders as $key) {
            if (! array_key_exists($key, $report["protocols"])) {
                exit(24);
            }
        }
        foreach ([...$report["protocols"], ...$report["policy"]] as $row) {
            if (! is_array($row) || ($row["state"] ?? null) !== "verified") {
                exit(25);
            }
        }
        $protocolKeys = array_keys($report["protocols"]);
        $policyKeys = array_keys($report["policy"]);
        sort($protocolKeys, SORT_STRING);
        sort($policyKeys, SORT_STRING);
        $executionCursor = $report["execution_cursor"] ?? null;
        if (! is_string($executionCursor) || preg_match("/\\A[a-f0-9]{64}\\z/", $executionCursor) !== 1) {
            exit(26);
        }
        echo hash("sha256", json_encode([$protocolKeys, $policyKeys], JSON_THROW_ON_ERROR)), ":", $executionCursor;
    ')" || fail 'the evidence report was malformed or incomplete.'
    [[ "$sample_fingerprints" =~ ^([a-f0-9]{64}):([a-f0-9]{64})$ ]] \
        || fail 'the evidence matrix fingerprint or persisted execution cursor is invalid.'
    sample_matrix_fingerprint="${BASH_REMATCH[1]}"
    sample_execution_cursor="${BASH_REMATCH[2]}"
    if [[ -z "$evidence_matrix_fingerprint" ]]; then
        evidence_matrix_fingerprint="$sample_matrix_fingerprint"
    elif [[ "$sample_matrix_fingerprint" != "$evidence_matrix_fingerprint" ]]; then
        fail 'the protocol, provider, or policy evidence set changed during the observation period.'
    fi
    if [[ -n "$previous_execution_cursor" && "$sample_execution_cursor" != "$previous_execution_cursor" ]]; then
        execution_advanced=true
    fi
    previous_execution_cursor="$sample_execution_cursor"

    if [[ "$sample" -lt "$SAMPLES" ]]; then
        sleep "$INTERVAL_SECONDS"
    fi
done
[[ "$execution_advanced" == true ]] \
    || fail 'persisted protocol, listener, provider, or policy execution evidence did not advance during the observation period.'

completed_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
observation_seconds=$(( (SAMPLES - 1) * INTERVAL_SECONDS ))
printf '{"state":"verified","samples":%d,"observation_seconds":%d,"window_minutes":%d,"started_at":"%s","completed_at":"%s"}\n' \
    "$SAMPLES" "$observation_seconds" "$WINDOW_MINUTES" "$started_at" "$completed_at"
