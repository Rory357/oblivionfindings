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
            'samples must be an integer of at least 5.',
            'interval-seconds must be an integer of at least 60.',
            '/security-devices/runtime-health',
            'status "$program:*"',
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
        ->not->toContain('echo "$health_json"', 'echo "$readiness_json"', '--location', '--insecure');
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
