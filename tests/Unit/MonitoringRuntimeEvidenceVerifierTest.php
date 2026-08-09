<?php

it('requires sustained supervised collector-free runtime and independent heartbeat evidence', function () {
    $script = (string) file_get_contents(__DIR__.'/../../scripts/monitoring/verify-central-runtime.sh');

    foreach ([
        'oblivion-monitoring-events',
        'oblivion-monitoring-checks',
        'oblivion-monitoring-discovery',
        'oblivion-monitoring-provider',
        'oblivion-monitoring-topology',
        'oblivion-monitoring-maintenance',
        'oblivion-monitoring-orchestration',
        'oblivion-monitoring-commands',
        'oblivion-monitoring-snmp-traps',
        'oblivion-monitoring-syslog',
        'oblivion-monitoring-flow',
    ] as $program) {
        expect($script)->toContain($program);
    }

    expect($script)
        ->toContain(
            'MONITORING_HEALTH_SESSION_COOKIE',
            "readonly GIT_BINARY='/usr/bin/git'",
            "readonly PHP_BINARY='/usr/bin/php8.4'",
            "readonly CURL_BINARY='/usr/bin/curl'",
            "readonly SUPERVISORCTL_BINARY='/usr/bin/supervisorctl'",
            'caller shell startup state is not permitted.',
            "\"\$ENV_BINARY\" -i PATH='/usr/bin:/bin'",
            'protected runtime binary invalid.',
            'for variable_name in ${!GIT_@}',
            'GIT_OPTIONAL_LOCKS=0',
            '--no-optional-locks',
            '-c core.fsmonitor=false',
            '-c core.untrackedCache=false',
            'rev-parse --show-toplevel',
            'rev-parse --verify refs/remotes/origin/main',
            'status --porcelain=v1 --untracked-files=all',
            'the deployed HEAD does not equal the reviewed origin/main revision.',
            'the deployed checkout contains tracked or untracked source changes.',
            'verify-central-runtime-authority.php',
            'CentralRuntimeReleaseAuthorityVerifier.php',
            'the central runtime release gate is not fully tracked by the reviewed release.',
            'read_protected_configuration_hash',
            'read_release_authority',
            'the protected central runtime release authority is unavailable or invalid.',
            'the deployed release, environment endpoint, or Supervisor configuration is not approved by the protected authority.',
            'completed_release_authority_snapshot',
            'the protected release authority or bound environment identity changed during the observation period.',
            '"evidence_class":"monitoring_central_runtime_release_evidence_v1"',
            '"authority_reference":"%s"',
            '"authority_sha256":"%s"',
            '"environment_reference_sha256":"%s"',
            '"watchdog_attestation_public_key_sha256":"%s"',
            '"protected_authority_verified":true',
            'completed_release_revision="$(read_release_revision)"',
            'the deployed release revision changed during the observation period.',
            '"release_revision":"%s"',
            '"checkout_clean_verified":true',
            'samples must be an integer of at least 5.',
            'interval-seconds must be an integer of at least 60.',
            '/security-devices/runtime-health',
            'status "$program:*"',
            'queue:work redis --queue=monitoring-events ',
            'queue:work redis --queue=monitoring-commands ',
            'monitoring:listen-snmp-traps',
            'monitoring:listen-syslog',
            'monitoring:listen-flow',
            'file_get_contents("/proc/{$pid}/cmdline")',
            '$program is not running from the exact deployed release command.',
            'monitoring:central-site-readiness --all --json',
            '($health["state"] ?? null) !== "operational"',
            '($external["state"] ?? null) !== "sent"',
            '($site["proof_state"] ?? null) !== "verified"',
            '! is_int($site["site"]["id"] ?? null)',
            '$directMonitors = $site["direct_monitors"] ?? null;',
            '! is_int($directMonitors)',
            '! is_int($durableDirectEvidence)',
            '! is_int($fresh)',
            '! is_int($stale)',
            '! is_int($neverObserved)',
            '$durableDirectEvidence !== $directMonitors',
            '$fresh !== $directMonitors',
            '$stale !== 0',
            '$neverObserved !== 0',
            '$site["direct_monitor_fingerprint"] ?? null',
            '$site["direct_monitor_max_interval_seconds"] ?? null',
            '$site["release_evidence_deadline_at"] ?? null',
            '$site["oldest_evidence_at"] ?? null',
            'verified_monitor_roster_fingerprint',
            'the operational Site or direct-monitor roster changed during the observation period.',
            'the observation period must cover at least two cycles of the slowest configured direct monitor.',
            'not every configured direct monitor advanced during the first half of the observation period.',
            'not every configured direct monitor advanced during the second half of the observation period.',
            'one or more direct monitors lack cadence-current evidence at the end of the observation period.',
            'observation_seconds',
        )
        ->not->toContain(
            'echo "$health_json"',
            'echo "$readiness_json"',
            '--location',
            '--insecure',
            'git fetch',
            'git pull',
            'git reset',
            'git clean',
            'command -v',
            'vendor/autoload.php',
        );
});

it('rejects cached or replayed runtime health evidence', function () {
    $script = (string) file_get_contents(__DIR__.'/../../scripts/monitoring/verify-central-runtime.sh');
    $runbook = (string) file_get_contents(__DIR__.'/../../docs/runbooks/monitoring/runtime-and-regional-outage.md');

    expect($script)
        ->toContain(
            'Cache-Control: no-cache, no-store',
            'Pragma: no-cache',
            '$health["observed_at"] ?? null',
            '$observedAt->getOffset() !== 0',
            '$observedAgeSeconds < -5 || $observedAgeSeconds > 60',
            'previous_health_observed_at',
            'runtime health evidence did not advance between sustained samples.',
        )
        ->and($runbook)
        ->toContain(
            'a fresh UTC runtime observation that',
            'every configured direct monitor at each operational Site to have current durable central-runtime evidence',
            'The request',
            'explicitly bypasses intermediary caches and rejects',
        );
});
