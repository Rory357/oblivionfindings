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
evidence_roster_fingerprint=''
execution_set_fingerprint=''
declare -A execution_rosters=()
declare -A initial_newest_evidence=()
declare -A latest_oldest_evidence=()
for ((sample = 1; sample <= SAMPLES; sample++)); do
    report="$(php artisan monitoring:protocol-policy-evidence \
        --window-minutes="$WINDOW_MINUTES" \
        --json)" || fail 'one or more protocol or policy evidence checks are not verified.'
    sample_evidence="$(printf '%s' "$report" | php -r '
        try {
            $report = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            exit(20);
        }
        if (! is_array($report)
            || ($report["all_verified"] ?? null) !== true
            || ! is_array($report["protocols"] ?? null)
            || ! is_array($report["policy"] ?? null)
            || ! is_array($report["continuous_execution"] ?? null)) {
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
        $executionKeys = array_keys($report["continuous_execution"]);
        sort($executionKeys, SORT_STRING);
        if ($executionKeys !== $protocolKeys) {
            exit(26);
        }
        $rosterFingerprint = $report["evidence_roster_fingerprint"] ?? null;
        $executionCursor = $report["execution_cursor"] ?? null;
        if (! is_string($rosterFingerprint)
            || preg_match("/\\A[a-f0-9]{64}\\z/", $rosterFingerprint) !== 1
            || ! is_string($executionCursor)
            || preg_match("/\\A[a-f0-9]{64}\\z/", $executionCursor) !== 1) {
            exit(27);
        }
        echo "meta:", $rosterFingerprint, ":",
            hash("sha256", json_encode([$protocolKeys, $policyKeys, $executionKeys], JSON_THROW_ON_ERROR)),
            ":", $executionCursor, "\n";
        foreach ($executionKeys as $key) {
            if (preg_match("/\\A[a-z0-9_]+\\z/", $key) !== 1) {
                exit(28);
            }
            $row = $report["continuous_execution"][$key];
            if (! is_array($row)) {
                exit(29);
            }
            $rowRoster = $row["roster_fingerprint"] ?? null;
            $members = $row["members"] ?? null;
            $missing = $row["missing"] ?? null;
            $oldestRaw = $row["oldest_evidence_at"] ?? null;
            $newestRaw = $row["newest_evidence_at"] ?? null;
            if (! is_string($rowRoster)
                || preg_match("/\\A[a-f0-9]{64}\\z/", $rowRoster) !== 1
                || ! is_int($members)
                || $members < 1
                || $missing !== 0
                || ! is_string($oldestRaw)
                || ! is_string($newestRaw)
                || preg_match("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|\\+00:00)$/D", $oldestRaw) !== 1
                || preg_match("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|\\+00:00)$/D", $newestRaw) !== 1) {
                exit(29);
            }
            try {
                $oldest = new DateTimeImmutable($oldestRaw);
                $newest = new DateTimeImmutable($newestRaw);
            } catch (Throwable) {
                exit(30);
            }
            if ($oldest->getOffset() !== 0
                || $newest->getOffset() !== 0
                || $oldest > $newest
                || $newest->getTimestamp() > time() + 5) {
                exit(31);
            }
            echo "row:", $key, ":", $rowRoster, ":", $members, ":",
                $oldest->getTimestamp(), ":", $newest->getTimestamp(), "\n";
        }
    ')" || fail 'the evidence report was malformed or incomplete.'
    mapfile -t sample_lines <<< "$sample_evidence"
    [[ "${#sample_lines[@]}" -ge 2 && "${sample_lines[0]}" =~ ^meta:([a-f0-9]{64}):([a-f0-9]{64}):([a-f0-9]{64})$ ]] \
        || fail 'the evidence roster, contract set, or persisted execution cursor is invalid.'
    sample_roster_fingerprint="${BASH_REMATCH[1]}"
    sample_execution_set_fingerprint="${BASH_REMATCH[2]}"
    if [[ -z "$evidence_roster_fingerprint" ]]; then
        evidence_roster_fingerprint="$sample_roster_fingerprint"
        execution_set_fingerprint="$sample_execution_set_fingerprint"
    elif [[ "$sample_roster_fingerprint" != "$evidence_roster_fingerprint" \
        || "$sample_execution_set_fingerprint" != "$execution_set_fingerprint" ]]; then
        fail 'the configured monitor, provider, or policy roster changed during the observation period.'
    fi

    sample_execution_rows=0
    for line in "${sample_lines[@]:1}"; do
        [[ "$line" =~ ^row:([a-z0-9_]+):([a-f0-9]{64}):([0-9]+):([0-9]+):([0-9]+)$ ]] \
            || fail 'one continuous execution row is malformed.'
        execution_key="${BASH_REMATCH[1]}"
        sample_execution_roster="${BASH_REMATCH[2]}"
        sample_oldest_evidence="${BASH_REMATCH[4]}"
        sample_newest_evidence="${BASH_REMATCH[5]}"
        if [[ "$sample" -eq 1 ]]; then
            execution_rosters[$execution_key]="$sample_execution_roster"
            initial_newest_evidence[$execution_key]="$sample_newest_evidence"
        elif [[ -z "${execution_rosters[$execution_key]+set}" \
            || "${execution_rosters[$execution_key]}" != "$sample_execution_roster" ]]; then
            fail "the continuous execution roster changed for $execution_key."
        fi
        latest_oldest_evidence[$execution_key]="$sample_oldest_evidence"
        sample_execution_rows=$((sample_execution_rows + 1))
    done
    [[ "$sample_execution_rows" -eq "${#execution_rosters[@]}" ]] \
        || fail 'a continuous execution row disappeared during the observation period.'

    if [[ "$sample" -lt "$SAMPLES" ]]; then
        sleep "$INTERVAL_SECONDS"
    fi
done
for execution_key in "${!execution_rosters[@]}"; do
    [[ "${latest_oldest_evidence[$execution_key]}" -gt "${initial_newest_evidence[$execution_key]}" ]] \
        || fail "not every pinned $execution_key execution member produced newer evidence during the observation period."
done

completed_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
observation_seconds=$(( (SAMPLES - 1) * INTERVAL_SECONDS ))
printf '{"state":"verified","samples":%d,"observation_seconds":%d,"window_minutes":%d,"started_at":"%s","completed_at":"%s"}\n' \
    "$SAMPLES" "$observation_seconds" "$WINDOW_MINUTES" "$started_at" "$completed_at"
