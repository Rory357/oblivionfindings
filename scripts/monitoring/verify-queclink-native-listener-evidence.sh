#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIRECTORY="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APPLICATION_PATH="$(cd -- "$SCRIPT_DIRECTORY/../.." && pwd)"
SAMPLES=5
INTERVAL_SECONDS=60
MAX_FRAME_AGE=900

usage() {
    cat <<'EOF'
Usage: verify-queclink-native-listener-evidence.sh [options]
  --application-path=/var/www/oblivionfindings
  --samples=5
  --interval-seconds=60
  --max-frame-age=900
EOF
}

fail() {
    echo "Queclink native-listener verification failed: $1" >&2
    exit 1
}

for argument in "$@"; do
    case "$argument" in
        --application-path=*) APPLICATION_PATH="${argument#*=}" ;;
        --samples=*) SAMPLES="${argument#*=}" ;;
        --interval-seconds=*) INTERVAL_SECONDS="${argument#*=}" ;;
        --max-frame-age=*) MAX_FRAME_AGE="${argument#*=}" ;;
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
[[ "$MAX_FRAME_AGE" =~ ^[0-9]+$ && "$MAX_FRAME_AGE" -ge 60 && "$MAX_FRAME_AGE" -le 86400 ]] \
    || fail 'max-frame-age must be an integer from 60 to 86400 seconds.'

cd "$APPLICATION_PATH"
started_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
initial_roster_fingerprint=''
initial_execution_fingerprint=''
initial_newest_evidence=0
latest_execution_fingerprint=''
latest_oldest_evidence=0
canonical_trackers=0
fresh_trackers=0

for ((sample = 1; sample <= SAMPLES; sample++)); do
    report="$(php artisan queclink:status \
        --evidence-json \
        --max-frame-age="$MAX_FRAME_AGE")" \
        || fail 'the listener snapshot is not currently verified.'
    sample_evidence="$(printf '%s' "$report" | php -r '
        try {
            $report = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            exit(20);
        }
        $acceptance = $report["acceptance"] ?? null;
        if (! is_array($acceptance)
            || ($acceptance["state"] ?? null) !== "verified"
            || ($acceptance["listener_state"] ?? null) !== "active"
            || ($acceptance["reason_codes"] ?? null) !== []) {
            exit(21);
        }
        $roster = $acceptance["canonical_roster_fingerprint"] ?? null;
        $execution = $acceptance["frame_execution_fingerprint"] ?? null;
        $canonical = $acceptance["canonical_paired_trackers"] ?? null;
        $fresh = $acceptance["fresh_trackers_observed"] ?? null;
        $window = $acceptance["frame_window"] ?? null;
        $oldestRaw = is_array($window) ? ($window["oldest_observed_at"] ?? null) : null;
        $newestRaw = is_array($window) ? ($window["newest_observed_at"] ?? null) : null;
        if (! is_string($roster)
            || preg_match("/\\A[a-f0-9]{64}\\z/", $roster) !== 1
            || ! is_string($execution)
            || preg_match("/\\A[a-f0-9]{64}\\z/", $execution) !== 1
            || ! is_int($canonical)
            || $canonical < 1
            || ! is_int($fresh)
            || $fresh !== $canonical
            || ! is_string($oldestRaw)
            || ! is_string($newestRaw)) {
            exit(22);
        }
        try {
            $oldest = new DateTimeImmutable($oldestRaw);
            $newest = new DateTimeImmutable($newestRaw);
        } catch (Throwable) {
            exit(23);
        }
        if ($oldest->getOffset() !== 0
            || $newest->getOffset() !== 0
            || $oldest > $newest
            || $newest->getTimestamp() > time() + 5) {
            exit(24);
        }
        echo implode(":", [
            $roster,
            $execution,
            $canonical,
            $fresh,
            $oldest->getTimestamp(),
            $newest->getTimestamp(),
        ]);
    ')" || fail 'the listener evidence snapshot was malformed or incomplete.'

    [[ "$sample_evidence" =~ ^([a-f0-9]{64}):([a-f0-9]{64}):([0-9]+):([0-9]+):([0-9]+):([0-9]+)$ ]] \
        || fail 'the listener evidence fingerprints or frame window are invalid.'
    sample_roster_fingerprint="${BASH_REMATCH[1]}"
    sample_execution_fingerprint="${BASH_REMATCH[2]}"
    canonical_trackers="${BASH_REMATCH[3]}"
    fresh_trackers="${BASH_REMATCH[4]}"
    sample_oldest_evidence="${BASH_REMATCH[5]}"
    sample_newest_evidence="${BASH_REMATCH[6]}"

    if [[ "$sample" -eq 1 ]]; then
        initial_roster_fingerprint="$sample_roster_fingerprint"
        initial_execution_fingerprint="$sample_execution_fingerprint"
        initial_newest_evidence="$sample_newest_evidence"
    elif [[ "$sample_roster_fingerprint" != "$initial_roster_fingerprint" ]]; then
        fail 'the canonical paired-tracker roster changed during the observation period.'
    fi

    latest_execution_fingerprint="$sample_execution_fingerprint"
    latest_oldest_evidence="$sample_oldest_evidence"
    if [[ "$sample" -lt "$SAMPLES" ]]; then
        sleep "$INTERVAL_SECONDS"
    fi
done

[[ "$latest_execution_fingerprint" != "$initial_execution_fingerprint" ]] \
    || fail 'persisted native-listener frame evidence did not advance during the observation period.'
[[ "$latest_oldest_evidence" -gt "$initial_newest_evidence" ]] \
    || fail 'every canonical tracker must advance beyond the initial native-listener evidence window.'

completed_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
observation_seconds=$(( (SAMPLES - 1) * INTERVAL_SECONDS ))
printf '{"state":"verified","samples":%d,"observation_seconds":%d,"max_frame_age_seconds":%d,"canonical_paired_trackers":%d,"fresh_trackers_observed":%d,"started_at":"%s","completed_at":"%s"}\n' \
    "$SAMPLES" "$observation_seconds" "$MAX_FRAME_AGE" "$canonical_trackers" "$fresh_trackers" "$started_at" "$completed_at"
