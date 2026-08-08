#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APPLICATION_PATH="$(cd -- "$SCRIPT_DIRECTORY/../.." && pwd)"
SUPERVISORD_CONFIG="${MONITORING_SUPERVISORD_CONFIG:-}"
HEALTH_URL="${MONITORING_RUNTIME_HEALTH_URL:-}"
SESSION_COOKIE="${MONITORING_HEALTH_SESSION_COOKIE:-}"
SAMPLES=15
INTERVAL_SECONDS=60

PROGRAMS=(
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
EXPECTED_PROCESSES=(4 8 2 3 2 1 2 2 1 1 1)

usage() {
    cat <<'EOF'
Usage: verify-central-runtime.sh [options]
  --application-path=/var/www/oblivionfindings
  --supervisord-config=/etc/supervisor/supervisord.conf
  --health-url=https://oblivion.example/security-devices/runtime-health
  --samples=15
  --interval-seconds=60

MONITORING_HEALTH_SESSION_COOKIE must contain an authorised session cookie.
The script is read-only and prints only a value-free aggregate result.
EOF
}

fail() {
    echo "Central runtime verification failed: $1" >&2
    exit 1
}

for argument in "$@"; do
    case "$argument" in
        --application-path=*) APPLICATION_PATH="${argument#*=}" ;;
        --supervisord-config=*) SUPERVISORD_CONFIG="${argument#*=}" ;;
        --health-url=*) HEALTH_URL="${argument#*=}" ;;
        --samples=*) SAMPLES="${argument#*=}" ;;
        --interval-seconds=*) INTERVAL_SECONDS="${argument#*=}" ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; fail "unknown option $argument" ;;
    esac
done

for command_name in curl date php readlink sleep supervisorctl; do
    command -v "$command_name" >/dev/null 2>&1 || fail "required command $command_name is unavailable."
done

[[ "$APPLICATION_PATH" == /* && "$APPLICATION_PATH" != *[[:space:]]* ]] \
    || fail 'application path must be one absolute path without whitespace.'
APPLICATION_PATH="$(readlink -f "$APPLICATION_PATH")"
[[ -d "$APPLICATION_PATH" && -f "$APPLICATION_PATH/artisan" ]] \
    || fail 'application path is not a complete Oblivion Findings release.'

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

[[ "$SAMPLES" =~ ^[0-9]+$ && "$SAMPLES" -ge 5 ]] \
    || fail 'samples must be an integer of at least 5.'
[[ "$INTERVAL_SECONDS" =~ ^[0-9]+$ && "$INTERVAL_SECONDS" -ge 60 ]] \
    || fail 'interval-seconds must be an integer of at least 60.'
[[ -n "$HEALTH_URL" ]] || fail 'an authenticated runtime health URL is required.'
[[ -n "$SESSION_COOKIE" ]] || fail 'MONITORING_HEALTH_SESSION_COOKIE is required; no unauthenticated bypass is permitted.'
[[ "$SESSION_COOKIE" != *$'\n'* && "$SESSION_COOKIE" != *$'\r'* && "$SESSION_COOKIE" != *'"'* && "$SESSION_COOKIE" != *'\\'* ]] \
    || fail 'the session cookie contains characters that cannot be passed safely.'

"$(command -v php)" -r '
    $url = $argv[1] ?? "";
    $parts = parse_url($url);
    if (! is_array($parts)
        || ($parts["scheme"] ?? null) !== "https"
        || ! is_string($parts["host"] ?? null)
        || ($parts["host"] ?? "") === ""
        || isset($parts["user"])
        || isset($parts["pass"])
        || isset($parts["query"])
        || isset($parts["fragment"])
        || (($parts["port"] ?? 443) !== 443)
        || (($parts["path"] ?? "") !== "/security-devices/runtime-health")) {
        exit(1);
    }
' "$HEALTH_URL" || fail 'health-url must be the HTTPS runtime-health path on port 443 without credentials, query, or fragment.'

check_supervisor() {
    local index program expected output count
    for index in "${!PROGRAMS[@]}"; do
        program="${PROGRAMS[$index]}"
        expected="${EXPECTED_PROCESSES[$index]}"
        output="$(supervisorctl -c "$SUPERVISORD_CONFIG" status "$program:*")" \
            || fail "Supervisor could not report $program."
        count="$(awk 'NF { count++ } END { print count + 0 }' <<< "$output")"
        [[ "$count" -eq "$expected" ]] \
            || fail "$program has $count configured processes; expected $expected."
        awk 'NF < 2 || $2 != "RUNNING" { exit 1 }' <<< "$output" \
            || fail "$program is not fully RUNNING."
    done
}

check_health() {
    local health_json
    health_json="$({
        printf 'header = "Accept: application/json"\n'
        printf 'header = "Cache-Control: no-cache, no-store"\n'
        printf 'header = "Pragma: no-cache"\n'
        printf 'header = "Cookie: %s"\n' "$SESSION_COOKIE"
    } | curl \
        --config - \
        --silent \
        --show-error \
        --fail \
        --connect-timeout 5 \
        --max-time 20 \
        --request GET \
        "$HEALTH_URL")" || fail 'the authenticated runtime health request failed.'

    printf '%s' "$health_json" | php -r '
        try {
            $health = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            exit(20);
        }
        if (! is_array($health) || ($health["state"] ?? null) !== "operational") {
            exit(21);
        }
        $workers = $health["workers"] ?? null;
        if (! is_array($workers)
            || ($workers["state"] ?? null) !== "available"
            || ($workers["available"] ?? null) !== 8
            || ($workers["total"] ?? null) !== 8
            || ($workers["attention"] ?? null) !== 0
            || ($workers["not_observed"] ?? null) !== 0) {
            exit(22);
        }
        $listeners = $health["listeners"] ?? null;
        foreach (["snmp_traps", "syslog", "flow"] as $listener) {
            if (! is_array($listeners[$listener] ?? null)
                || ($listeners[$listener]["state"] ?? null) !== "available") {
                exit(23);
            }
        }
        $external = $health["external_heartbeat"] ?? null;
        if (! is_array($external) || ($external["state"] ?? null) !== "sent") {
            exit(24);
        }
        $observedRaw = $health["observed_at"] ?? null;
        if (! is_string($observedRaw)
            || preg_match("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|\\+00:00)$/D", $observedRaw) !== 1) {
            exit(25);
        }
        try {
            $observedAt = new DateTimeImmutable($observedRaw);
        } catch (Throwable) {
            exit(25);
        }
        $observedAgeSeconds = time() - $observedAt->getTimestamp();
        if ($observedAt->getOffset() !== 0 || $observedAgeSeconds < -5 || $observedAgeSeconds > 60) {
            exit(25);
        }
        echo $observedAt->getTimestamp();
    ' || fail 'runtime health is not fully operational or the independent heartbeat is not current.'
}

check_central_readiness() {
    local readiness_json
    readiness_json="$(php artisan monitoring:central-site-readiness --all --json)" \
        || fail 'collector-free Site readiness failed.'

    printf '%s' "$readiness_json" | php -r '
        try {
            $report = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            exit(30);
        }
        if (! is_array($report)
            || ($report["all_sites_verified"] ?? null) !== true
            || ! is_array($report["sites"] ?? null)
            || count($report["sites"]) < 1) {
            exit(31);
        }
        $siteIds = [];
        $siteRoster = [];
        $oldestEvidenceEpochs = [];
        $newestEvidenceEpochs = [];
        foreach ($report["sites"] as $site) {
            if (! is_array($site)) {
                exit(32);
            }
            $directMonitors = $site["direct_monitors"] ?? null;
            $durableDirectEvidence = $site["durable_direct_evidence"] ?? null;
            $fresh = $site["fresh"] ?? null;
            $stale = $site["stale"] ?? null;
            $neverObserved = $site["never_observed"] ?? null;
            $directMonitorFingerprint = $site["direct_monitor_fingerprint"] ?? null;
            $oldestEvidenceRaw = $site["oldest_evidence_at"] ?? null;
            $newestEvidenceRaw = $site["evidence_at"] ?? null;
            if (! is_array($site["site"] ?? null)
                || ! is_int($site["site"]["id"] ?? null)
                || $site["site"]["id"] < 1
                || ($site["proof_state"] ?? null) !== "verified"
                || ! is_int($directMonitors)
                || ! is_int($durableDirectEvidence)
                || ! is_int($fresh)
                || ! is_int($stale)
                || ! is_int($neverObserved)
                || $directMonitors < 1
                || $durableDirectEvidence !== $directMonitors
                || $fresh !== $directMonitors
                || $stale !== 0
                || $neverObserved !== 0
                || ! is_string($directMonitorFingerprint)
                || preg_match("/\A[a-f0-9]{64}\z/", $directMonitorFingerprint) !== 1
                || ! is_string($oldestEvidenceRaw)
                || preg_match("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|\\+00:00)$/D", $oldestEvidenceRaw) !== 1
                || ! is_string($newestEvidenceRaw)
                || preg_match("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|\\+00:00)$/D", $newestEvidenceRaw) !== 1) {
                exit(32);
            }
            try {
                $oldestEvidenceAt = new DateTimeImmutable($oldestEvidenceRaw);
                $newestEvidenceAt = new DateTimeImmutable($newestEvidenceRaw);
            } catch (Throwable) {
                exit(32);
            }
            if ($oldestEvidenceAt->getOffset() !== 0
                || $newestEvidenceAt->getOffset() !== 0
                || $oldestEvidenceAt > $newestEvidenceAt
                || $newestEvidenceAt->getTimestamp() > time() + 5) {
                exit(32);
            }
            $siteIds[] = $site["site"]["id"];
            $siteRoster[] = $site["site"]["id"].":".$directMonitorFingerprint;
            $oldestEvidenceEpochs[] = $oldestEvidenceAt->getTimestamp();
            $newestEvidenceEpochs[] = $newestEvidenceAt->getTimestamp();
        }
        sort($siteIds, SORT_NUMERIC);
        sort($siteRoster, SORT_STRING);
        if (count(array_unique($siteIds, SORT_REGULAR)) !== count($siteIds)) {
            exit(33);
        }
        echo count($siteRoster).":".hash("sha256", implode(",", $siteRoster)).":"
            .min($oldestEvidenceEpochs).":".max($newestEvidenceEpochs);
    ' || fail 'one or more Sites lack durable collector-free monitoring evidence.'
}

started_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
verified_sites=''
verified_monitor_roster_fingerprint=''
initial_newest_evidence_at=''
latest_oldest_evidence_at=''
previous_health_observed_at=''

cd "$APPLICATION_PATH"
for ((sample = 1; sample <= SAMPLES; sample++)); do
    check_supervisor
    sample_health_observed_at="$(check_health)"
    [[ "$sample_health_observed_at" =~ ^[0-9]+$ ]] \
        || fail 'runtime health did not return a valid observed_at timestamp.'
    if [[ -n "$previous_health_observed_at" && "$sample_health_observed_at" -le "$previous_health_observed_at" ]]; then
        fail 'runtime health evidence did not advance between sustained samples.'
    fi
    previous_health_observed_at="$sample_health_observed_at"
    sample_readiness="$(check_central_readiness)"
    [[ "$sample_readiness" =~ ^([0-9]+):([a-f0-9]{64}):([0-9]+):([0-9]+)$ ]] \
        || fail 'collector-free Site readiness did not return a valid monitor roster and evidence window.'
    sample_sites="${BASH_REMATCH[1]}"
    sample_monitor_roster_fingerprint="${BASH_REMATCH[2]}"
    latest_oldest_evidence_at="${BASH_REMATCH[3]}"
    sample_newest_evidence_at="${BASH_REMATCH[4]}"
    if [[ -z "$verified_sites" ]]; then
        verified_sites="$sample_sites"
        verified_monitor_roster_fingerprint="$sample_monitor_roster_fingerprint"
        initial_newest_evidence_at="$sample_newest_evidence_at"
    elif [[ "$sample_sites" != "$verified_sites" || "$sample_monitor_roster_fingerprint" != "$verified_monitor_roster_fingerprint" ]]; then
        fail 'the operational Site or direct-monitor roster changed during the observation period.'
    fi

    if [[ "$sample" -lt "$SAMPLES" ]]; then
        sleep "$INTERVAL_SECONDS"
    fi
done
[[ "$latest_oldest_evidence_at" -gt "$initial_newest_evidence_at" ]] \
    || fail 'not every configured direct monitor produced newer durable central-runtime evidence during the observation period.'

completed_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
observation_seconds=$(( (SAMPLES - 1) * INTERVAL_SECONDS ))
printf '{"state":"verified","samples":%d,"observation_seconds":%d,"verified_sites":%d,"supervised_programs":%d,"started_at":"%s","completed_at":"%s"}\n' \
    "$SAMPLES" \
    "$observation_seconds" \
    "$verified_sites" \
    "${#PROGRAMS[@]}" \
    "$started_at" \
    "$completed_at"
