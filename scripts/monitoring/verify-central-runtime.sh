#!/usr/bin/env bash
set -euo pipefail

if [[ -n "${BASH_ENV:-}" || -n "${ENV:-}" || -n "$(builtin declare -F)" ]]; then
    printf 'Central runtime verification failed: caller shell startup state is not permitted.\n' >&2
    exit 1
fi

readonly CURL_BINARY='/usr/bin/curl'
readonly DATE_BINARY='/usr/bin/date'
readonly DIRNAME_BINARY='/usr/bin/dirname'
readonly ENV_BINARY='/usr/bin/env'
readonly GIT_BINARY='/usr/bin/git'
readonly PHP_BINARY='/usr/bin/php8.4'
readonly READLINK_BINARY='/usr/bin/readlink'
readonly SLEEP_BINARY='/usr/bin/sleep'
readonly SUPERVISORCTL_BINARY='/usr/bin/supervisorctl'

SUPERVISORD_CONFIG="${MONITORING_SUPERVISORD_CONFIG:-}"
HEALTH_URL="${MONITORING_RUNTIME_HEALTH_URL:-}"
SESSION_COOKIE="${MONITORING_HEALTH_SESSION_COOKIE:-}"
SAMPLES=15
INTERVAL_SECONDS=60

for variable_name in ${!GIT_@}; do
    unset "$variable_name"
done
for variable_name in \
    BASH_ENV CDPATH CURL_CA_BUNDLE CURL_HOME ENV GLOBIGNORE \
    LD_LIBRARY_PATH LD_PRELOAD PHPRC PHP_INI_SCAN_DIR PYTHONHOME PYTHONPATH \
    SSL_CERT_DIR SSL_CERT_FILE all_proxy http_proxy https_proxy \
    no_proxy ALL_PROXY HTTP_PROXY HTTPS_PROXY NO_PROXY; do
    unset "$variable_name"
done
export GIT_OPTIONAL_LOCKS=0
export PATH='/usr/bin:/bin'

for protected_binary in \
    "$CURL_BINARY" "$DATE_BINARY" "$DIRNAME_BINARY" \
    "$ENV_BINARY" "$GIT_BINARY" "$PHP_BINARY" "$READLINK_BINARY" \
    "$SLEEP_BINARY" "$SUPERVISORCTL_BINARY"; do
    [[ -f "$protected_binary" && ! -L "$protected_binary" && -x "$protected_binary" ]] \
        || { printf 'Central runtime verification failed: protected runtime binary unavailable.\n' >&2; exit 1; }
done

"$PHP_BINARY" -r '
    foreach (array_slice($argv, 1) as $path) {
        $metadata = @lstat($path);
        $mode = is_array($metadata) ? ($metadata["mode"] ?? null) : null;
        if (! is_array($metadata)
            || ! is_int($mode)
            || ($mode & 0170000) !== 0100000
            || ($mode & 0022) !== 0
            || ($metadata["uid"] ?? null) !== 0) {
            exit(1);
        }
    }
' -- "$CURL_BINARY" "$DATE_BINARY" "$DIRNAME_BINARY" \
    "$ENV_BINARY" "$GIT_BINARY" "$PHP_BINARY" "$READLINK_BINARY" \
    "$SLEEP_BINARY" "$SUPERVISORCTL_BINARY" \
    || { printf 'Central runtime verification failed: protected runtime binary invalid.\n' >&2; exit 1; }

SCRIPT_DIRECTORY="$(cd -- "$("$DIRNAME_BINARY" -- "${BASH_SOURCE[0]}")" && pwd)"
APPLICATION_PATH="$(cd -- "$SCRIPT_DIRECTORY/../.." && pwd)"
readonly AUTHORITY_VERIFIER="$SCRIPT_DIRECTORY/verify-central-runtime-authority.php"

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
COMMAND_MARKERS=(
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

[[ "$APPLICATION_PATH" == /* && "$APPLICATION_PATH" != *[[:space:]]* ]] \
    || fail 'application path must be one absolute path without whitespace.'
APPLICATION_PATH="$("$READLINK_BINARY" -f "$APPLICATION_PATH")"
[[ -d "$APPLICATION_PATH" && -f "$APPLICATION_PATH/artisan" ]] \
    || fail 'application path is not a complete Oblivion Findings release.'
[[ ! -L "${BASH_SOURCE[0]}" \
    && "$SCRIPT_DIRECTORY" == "$APPLICATION_PATH/scripts/monitoring" ]] \
    || fail 'the release verifier must execute from the selected application checkout.'
[[ -f "$AUTHORITY_VERIFIER" && ! -L "$AUTHORITY_VERIFIER" ]] \
    || fail 'the tracked central runtime authority verifier is unavailable.'

run_release_php() {
    "$ENV_BINARY" -i PATH='/usr/bin:/bin' "$PHP_BINARY" "$@"
}

run_release_git() (
    "$ENV_BINARY" -i PATH='/usr/bin:/bin' GIT_OPTIONAL_LOCKS=0 "$GIT_BINARY" \
        --no-optional-locks \
        -c core.fsmonitor=false \
        -c core.untrackedCache=false \
        -C "$APPLICATION_PATH" \
        "$@"
)

read_release_revision() {
    local checkout_root head_revision origin_main_revision checkout_status

    checkout_root="$(run_release_git rev-parse --show-toplevel 2>/dev/null)" \
        || fail 'the application path is not a verifiable Git checkout.'
    checkout_root="$("$READLINK_BINARY" -f "$checkout_root")"
    [[ "$checkout_root" == "$APPLICATION_PATH" ]] \
        || fail 'the Git checkout root does not match the application path.'

    head_revision="$(run_release_git rev-parse --verify HEAD 2>/dev/null)" \
        || fail 'the deployed HEAD revision is unavailable.'
    origin_main_revision="$(run_release_git rev-parse --verify refs/remotes/origin/main 2>/dev/null)" \
        || fail 'the deployed origin/main revision is unavailable.'
    [[ "$head_revision" =~ ^[a-f0-9]{40}$ && "$head_revision" == "$origin_main_revision" ]] \
        || fail 'the deployed HEAD does not equal the reviewed origin/main revision.'

    checkout_status="$(run_release_git status --porcelain=v1 --untracked-files=all 2>/dev/null)" \
        || fail 'the deployed checkout state could not be verified.'
    [[ -z "$checkout_status" ]] \
        || fail 'the deployed checkout contains tracked or untracked source changes.'

    printf '%s' "$head_revision"
}

release_revision="$(read_release_revision)"
run_release_git ls-files --error-unmatch -- \
    scripts/monitoring/verify-central-runtime.sh \
    scripts/monitoring/verify-central-runtime-authority.php \
    app/Support/Monitoring/StrictJsonObjectDecoder.php \
    app/Support/Monitoring/CentralRuntimeReleaseAuthorityVerifier.php \
    >/dev/null 2>&1 \
    || fail 'the central runtime release gate is not fully tracked by the reviewed release.'

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

run_release_php -r '
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

sha256_value() {
    run_release_php -r 'echo hash("sha256", $argv[1] ?? "");' "$1"
}

read_protected_configuration_hash() {
    run_release_php -r '
        $path = $argv[1] ?? "";
        if ($path === "" || is_link($path)) {
            exit(1);
        }
        $before = @lstat($path);
        $handle = @fopen($path, "rb");
        if (! is_array($before) || $handle === false) {
            exit(1);
        }
        try {
            $opened = @fstat($handle);
            $size = is_array($opened) ? ($opened["size"] ?? null) : null;
            $mode = is_array($opened) ? ($opened["mode"] ?? null) : null;
            if (! is_array($opened)
                || ! is_int($size)
                || $size < 1
                || $size > 1048576
                || ! is_int($mode)
                || ($mode & 0170000) !== 0100000
                || ($mode & 0022) !== 0
                || ($opened["uid"] ?? null) !== 0) {
                exit(1);
            }
            $raw = stream_get_contents($handle, 1048577);
            $after = @lstat($path);
            if (! is_string($raw)
                || strlen($raw) !== $size
                || ! is_array($after)
                || ($before["dev"] ?? null) !== ($opened["dev"] ?? null)
                || ($before["ino"] ?? null) !== ($opened["ino"] ?? null)
                || ($opened["dev"] ?? null) !== ($after["dev"] ?? null)
                || ($opened["ino"] ?? null) !== ($after["ino"] ?? null)
                || ($opened["size"] ?? null) !== ($after["size"] ?? null)
                || ($opened["mtime"] ?? null) !== ($after["mtime"] ?? null)) {
                exit(1);
            }
            echo hash("sha256", $raw);
        } finally {
            fclose($handle);
        }
    ' "$1"
}

read_release_authority() {
    local authority_json
    authority_json="$(run_release_php "$AUTHORITY_VERIFIER")" \
        || fail 'the protected central runtime release authority is unavailable or invalid.'

    printf '%s' "$authority_json" | run_release_php -r '
        try {
            $authority = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            exit(1);
        }
        $expected = [
            "application_path_sha256",
            "authority_reference",
            "authority_sha256",
            "environment_reference_sha256",
            "health_url_sha256",
            "not_after_epoch",
            "not_before_epoch",
            "release_revision",
            "supervisor_configuration_sha256",
            "watchdog_attestation_public_key_sha256",
        ];
        if (! is_array($authority)) {
            exit(1);
        }
        $actual = array_keys($authority);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || preg_match("/\AAUTHORITY-[a-f0-9]{32}\z/", $authority["authority_reference"] ?? "") !== 1) {
            exit(1);
        }
        foreach ([
            "application_path_sha256",
            "authority_sha256",
            "environment_reference_sha256",
            "health_url_sha256",
            "supervisor_configuration_sha256",
        ] as $key) {
            if (preg_match("/\A[a-f0-9]{64}\z/", $authority[$key] ?? "") !== 1) {
                exit(1);
            }
        }
        if (preg_match("/\A[a-f0-9]{40}\z/", $authority["release_revision"] ?? "") !== 1) {
            exit(1);
        }
        if (! is_int($authority["not_before_epoch"] ?? null)
            || ! is_int($authority["not_after_epoch"] ?? null)
            || $authority["not_before_epoch"] >= $authority["not_after_epoch"]
            || preg_match("/\A[a-f0-9]{64}\z/", $authority["watchdog_attestation_public_key_sha256"] ?? "") !== 1) {
            exit(1);
        }
        echo implode(":", [
            $authority["authority_reference"],
            $authority["authority_sha256"],
            $authority["environment_reference_sha256"],
            $authority["release_revision"],
            $authority["application_path_sha256"],
            $authority["health_url_sha256"],
            $authority["supervisor_configuration_sha256"],
            $authority["watchdog_attestation_public_key_sha256"],
            $authority["not_before_epoch"],
            $authority["not_after_epoch"],
        ]);
    ' || fail 'the protected central runtime release authority response is invalid.'
}

application_path_sha256="$(sha256_value "$APPLICATION_PATH")"
health_url_sha256="$(sha256_value "$HEALTH_URL")"
supervisor_configuration_sha256="$(read_protected_configuration_hash "$SUPERVISORD_CONFIG")" \
    || fail 'the Supervisor configuration is not one stable root-protected file.'
release_authority_snapshot="$(read_release_authority)"
IFS=':' read -r authority_reference authority_sha256 environment_reference_sha256 \
    authority_release_revision authority_application_path_sha256 authority_health_url_sha256 \
    authority_supervisor_configuration_sha256 watchdog_attestation_public_key_sha256 \
    authority_not_before_epoch authority_not_after_epoch <<< "$release_authority_snapshot"
[[ "$release_revision" == "$authority_release_revision" \
    && "$application_path_sha256" == "$authority_application_path_sha256" \
    && "$health_url_sha256" == "$authority_health_url_sha256" \
    && "$supervisor_configuration_sha256" == "$authority_supervisor_configuration_sha256" ]] \
    || fail 'the deployed release, environment endpoint, or Supervisor configuration is not approved by the protected authority.'

check_supervisor() {
    local index program expected command_marker output summary count pid_list pid process_command
    local -a process_pids
    for index in "${!PROGRAMS[@]}"; do
        program="${PROGRAMS[$index]}"
        expected="${EXPECTED_PROCESSES[$index]}"
        command_marker="${COMMAND_MARKERS[$index]}"
        output="$("$ENV_BINARY" -i PATH='/usr/bin:/bin' "$SUPERVISORCTL_BINARY" -c "$SUPERVISORD_CONFIG" status "$program:*")" \
            || fail "Supervisor could not report $program."
        summary="$(printf '%s\n' "$output" | run_release_php -r '
            $lines = preg_split("/\r?\n/", trim(stream_get_contents(STDIN)));
            if (! is_array($lines) || $lines === [""]) {
                exit(1);
            }
            $pids = [];
            foreach ($lines as $line) {
                $fields = preg_split("/\s+/", trim($line));
                if (! is_array($fields)
                    || count($fields) < 4
                    || ($fields[1] ?? null) !== "RUNNING"
                    || ($fields[2] ?? null) !== "pid"
                    || preg_match("/\A[0-9]+,\z/", $fields[3] ?? "") !== 1) {
                    exit(1);
                }
                $pid = (int) rtrim($fields[3], ",");
                if ($pid < 1 || in_array($pid, $pids, true)) {
                    exit(1);
                }
                $pids[] = $pid;
            }
            echo count($lines).":".implode(",", $pids);
        ')" || fail "$program is not fully RUNNING."
        IFS=':' read -r count pid_list <<< "$summary"
        [[ "$count" -eq "$expected" ]] \
            || fail "$program has $count configured processes; expected $expected."
        IFS=',' read -r -a process_pids <<< "$pid_list"
        [[ "${#process_pids[@]}" -eq "$expected" ]] \
            || fail "$program did not return the exact running process roster."
        for pid in "${process_pids[@]}"; do
            process_command="$(run_release_php -r '
                $pid = $argv[1] ?? "";
                if (preg_match("/\A[0-9]+\z/", $pid) !== 1) {
                    exit(1);
                }
                $command = @file_get_contents("/proc/{$pid}/cmdline");
                if (! is_string($command) || $command === "") {
                    exit(1);
                }
                echo trim(str_replace("\0", " ", $command));
            ' "$pid")" || fail "$program process identity is unavailable."
            [[ "$process_command" == *"$APPLICATION_PATH/artisan $command_marker"* ]] \
                || fail "$program is not running from the exact deployed release command."
        done
    done
}

check_health() {
    local health_json
    health_json="$({
        printf 'header = "Accept: application/json"\n'
        printf 'header = "Cache-Control: no-cache, no-store"\n'
        printf 'header = "Pragma: no-cache"\n'
        printf 'header = "Cookie: %s"\n' "$SESSION_COOKIE"
    } | "$ENV_BINARY" -i PATH='/usr/bin:/bin' "$CURL_BINARY" \
        --disable \
        --config - \
        --silent \
        --show-error \
        --fail \
        --connect-timeout 5 \
        --max-time 20 \
        --request GET \
        "$HEALTH_URL")" || fail 'the authenticated runtime health request failed.'

    printf '%s' "$health_json" | run_release_php -r '
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
    readiness_json="$(run_release_php artisan monitoring:central-site-readiness --all --json)" \
        || fail 'collector-free Site readiness failed.'

    printf '%s' "$readiness_json" | run_release_php -r '
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
        $maximumIntervalEpochs = [];
        $releaseEvidenceDeadlineEpochs = [];
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
            $maximumIntervalSeconds = $site["direct_monitor_max_interval_seconds"] ?? null;
            $oldestEvidenceRaw = $site["oldest_evidence_at"] ?? null;
            $newestEvidenceRaw = $site["evidence_at"] ?? null;
            $releaseEvidenceDeadlineRaw = $site["release_evidence_deadline_at"] ?? null;
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
                || ! is_int($maximumIntervalSeconds)
                || $maximumIntervalSeconds < 30
                || ! is_string($oldestEvidenceRaw)
                || preg_match("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|\\+00:00)$/D", $oldestEvidenceRaw) !== 1
                || ! is_string($newestEvidenceRaw)
                || preg_match("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|\\+00:00)$/D", $newestEvidenceRaw) !== 1
                || ! is_string($releaseEvidenceDeadlineRaw)
                || preg_match("/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|\\+00:00)$/D", $releaseEvidenceDeadlineRaw) !== 1) {
                exit(32);
            }
            try {
                $oldestEvidenceAt = new DateTimeImmutable($oldestEvidenceRaw);
                $newestEvidenceAt = new DateTimeImmutable($newestEvidenceRaw);
                $releaseEvidenceDeadlineAt = new DateTimeImmutable($releaseEvidenceDeadlineRaw);
            } catch (Throwable) {
                exit(32);
            }
            if ($oldestEvidenceAt->getOffset() !== 0
                || $newestEvidenceAt->getOffset() !== 0
                || $releaseEvidenceDeadlineAt->getOffset() !== 0
                || $oldestEvidenceAt > $newestEvidenceAt
                || $releaseEvidenceDeadlineAt <= $oldestEvidenceAt
                || $newestEvidenceAt->getTimestamp() > time() + 5) {
                exit(32);
            }
            $siteIds[] = $site["site"]["id"];
            $siteRoster[] = $site["site"]["id"].":".$directMonitorFingerprint;
            $oldestEvidenceEpochs[] = $oldestEvidenceAt->getTimestamp();
            $newestEvidenceEpochs[] = $newestEvidenceAt->getTimestamp();
            $maximumIntervalEpochs[] = $maximumIntervalSeconds;
            $releaseEvidenceDeadlineEpochs[] = $releaseEvidenceDeadlineAt->getTimestamp();
        }
        sort($siteIds, SORT_NUMERIC);
        sort($siteRoster, SORT_STRING);
        if (count(array_unique($siteIds, SORT_REGULAR)) !== count($siteIds)) {
            exit(33);
        }
        echo count($siteRoster).":".hash("sha256", implode(",", $siteRoster)).":"
            .min($oldestEvidenceEpochs).":".max($newestEvidenceEpochs).":"
            .max($maximumIntervalEpochs).":".min($releaseEvidenceDeadlineEpochs);
    ' || fail 'one or more Sites lack durable collector-free monitoring evidence.'
}

started_at="$("$DATE_BINARY" -u +'%Y-%m-%dT%H:%M:%SZ')"
verified_sites=''
verified_monitor_roster_fingerprint=''
initial_newest_evidence_at=''
checkpoint_newest_evidence_at=''
latest_oldest_evidence_at=''
latest_release_evidence_deadline_at=''
previous_health_observed_at=''
observation_seconds=$(( (SAMPLES - 1) * INTERVAL_SECONDS ))
checkpoint_sample=$(( (SAMPLES + 1) / 2 ))

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
    [[ "$sample_readiness" =~ ^([0-9]+):([a-f0-9]{64}):([0-9]+):([0-9]+):([0-9]+):([0-9]+)$ ]] \
        || fail 'collector-free Site readiness did not return a valid monitor roster and evidence window.'
    sample_sites="${BASH_REMATCH[1]}"
    sample_monitor_roster_fingerprint="${BASH_REMATCH[2]}"
    latest_oldest_evidence_at="${BASH_REMATCH[3]}"
    sample_newest_evidence_at="${BASH_REMATCH[4]}"
    sample_maximum_interval_seconds="${BASH_REMATCH[5]}"
    latest_release_evidence_deadline_at="${BASH_REMATCH[6]}"
    if [[ -z "$verified_sites" ]]; then
        verified_sites="$sample_sites"
        verified_monitor_roster_fingerprint="$sample_monitor_roster_fingerprint"
        initial_newest_evidence_at="$sample_newest_evidence_at"
        [[ "$observation_seconds" -ge $(( sample_maximum_interval_seconds * 2 )) ]] \
            || fail 'the observation period must cover at least two cycles of the slowest configured direct monitor.'
    elif [[ "$sample_sites" != "$verified_sites" || "$sample_monitor_roster_fingerprint" != "$verified_monitor_roster_fingerprint" ]]; then
        fail 'the operational Site or direct-monitor roster changed during the observation period.'
    fi

    if [[ "$sample" -eq "$checkpoint_sample" ]]; then
        [[ "$latest_oldest_evidence_at" -gt "$initial_newest_evidence_at" ]] \
            || fail 'not every configured direct monitor advanced during the first half of the observation period.'
        checkpoint_newest_evidence_at="$sample_newest_evidence_at"
    fi

    if [[ "$sample" -lt "$SAMPLES" ]]; then
        "$SLEEP_BINARY" "$INTERVAL_SECONDS"
    fi
done
[[ -n "$checkpoint_newest_evidence_at" && "$latest_oldest_evidence_at" -gt "$checkpoint_newest_evidence_at" ]] \
    || fail 'not every configured direct monitor advanced during the second half of the observation period.'
completed_epoch="$("$DATE_BINARY" -u +'%s')"
[[ "$latest_release_evidence_deadline_at" -ge "$completed_epoch" ]] \
    || fail 'one or more direct monitors lack cadence-current evidence at the end of the observation period.'

completed_at="$("$DATE_BINARY" -u +'%Y-%m-%dT%H:%M:%SZ')"
completed_release_revision="$(read_release_revision)"
[[ "$completed_release_revision" == "$release_revision" ]] \
    || fail 'the deployed release revision changed during the observation period.'
completed_application_path_sha256="$(sha256_value "$APPLICATION_PATH")"
completed_health_url_sha256="$(sha256_value "$HEALTH_URL")"
completed_supervisor_configuration_sha256="$(read_protected_configuration_hash "$SUPERVISORD_CONFIG")" \
    || fail 'the protected Supervisor configuration changed or became unavailable during the observation period.'
completed_release_authority_snapshot="$(read_release_authority)"
[[ "$completed_release_authority_snapshot" == "$release_authority_snapshot" \
    && "$completed_application_path_sha256" == "$application_path_sha256" \
    && "$completed_health_url_sha256" == "$health_url_sha256" \
    && "$completed_supervisor_configuration_sha256" == "$supervisor_configuration_sha256" ]] \
    || fail 'the protected release authority or bound environment identity changed during the observation period.'

printf '{"state":"verified","evidence_class":"monitoring_central_runtime_release_evidence_v1","authority_reference":"%s","authority_sha256":"%s","environment_reference_sha256":"%s","watchdog_attestation_public_key_sha256":"%s","release_revision":"%s","checkout_clean_verified":true,"protected_authority_verified":true,"samples":%d,"observation_seconds":%d,"verified_sites":%d,"supervised_programs":%d,"started_at":"%s","completed_at":"%s"}\n' \
    "$authority_reference" \
    "$authority_sha256" \
    "$environment_reference_sha256" \
    "$watchdog_attestation_public_key_sha256" \
    "$release_revision" \
    "$SAMPLES" \
    "$observation_seconds" \
    "$verified_sites" \
    "${#PROGRAMS[@]}" \
    "$started_at" \
    "$completed_at"
