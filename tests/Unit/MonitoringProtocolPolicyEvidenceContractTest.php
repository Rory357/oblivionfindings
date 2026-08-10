<?php

it('requires a complete value-free sustained protocol and policy evidence matrix', function () {
    $service = (string) file_get_contents(__DIR__.'/../../app/Domain/Monitoring/Services/ProtocolPolicyEvidenceService.php');
    $command = (string) file_get_contents(__DIR__.'/../../app/Console/Commands/MonitoringProtocolPolicyEvidence.php');
    $script = (string) file_get_contents(__DIR__.'/../../scripts/monitoring/verify-protocol-policy-evidence.sh');
    $runbook = (string) file_get_contents(__DIR__.'/../../docs/runbooks/monitoring/protocol-policy-release-acceptance.md');

    foreach ([
        "'icmp'",
        "'tcp'",
        "'dns'",
        "'http'",
        "'https'",
        "'tls'",
        "'snmp_v3'",
        "'snmp_traps'",
        "'syslog'",
        "'flow'",
        "'ssh_read_only'",
        "'winrm_read_only'",
        'ObservationCollectionCapability::class',
        "'profiles'",
        "'coverage'",
        "'dependencies'",
        "'maintenance'",
        "'confirmation'",
        "'hysteresis'",
        "'stale_unknown'",
        "'baselines'",
        "'rollups'",
    ] as $contract) {
        expect($service)->toContain($contract);
    }

    expect($command)
        ->toContain('monitoring:protocol-policy-evidence', 'all_verified', 'JSON_THROW_ON_ERROR')
        ->not->toContain('target', 'payload', 'site_id', 'device_id', 'credential');
    expect($service)
        ->toContain(
            "'evidence_roster_fingerprint'",
            "'continuous_execution'",
            '$fresh->count() === $configured->count()',
            "'canonical_scope_failures'",
            "':site:'.(\$canonicalSiteId ?? 'unresolved')",
            'private function continuousExecutionEvidence(',
            'private function executionWindow(',
            'private function opaqueFingerprint(',
            'private function recentMaintenanceWindows(',
            'Collection $recentMaintenanceWindows',
            'foreach ($recentMaintenanceWindows as $window)',
            "'oldest_evidence_at'",
            "'newest_evidence_at'",
        );
    expect($script)
        ->toContain(
            'samples must be an integer of at least 5.',
            'interval-seconds must be an integer of at least 60.',
            'monitoring:protocol-policy-evidence',
            '($report["all_verified"] ?? null) !== true',
            '$requiredProtocols',
            '$requiredPolicy',
            '$requiredProviders = ["provider_unifi", "provider_milesight"]',
            '$report["evidence_roster_fingerprint"] ?? null',
            '$report["continuous_execution"] ?? null',
            '$executionKeys !== $protocolKeys',
            'the configured monitor, provider, or policy roster changed during the observation period.',
            '$report["execution_cursor"] ?? null',
            'initial_newest_evidence',
            'latest_oldest_evidence',
            'not every pinned $execution_key execution member produced newer evidence during the observation period.',
            'observation_seconds',
        )
        ->not->toContain('echo "$report"', '--insecure');
    expect($runbook)->toContain(
        'same recurrence-aware bounded',
        'first occurrence ended',
        'invalidates the sustained observation fingerprint',
    );
});
