<?php

it('requires a complete value-free sustained protocol and policy evidence matrix', function () {
    $service = (string) file_get_contents(__DIR__.'/../../app/Domain/Monitoring/Services/ProtocolPolicyEvidenceService.php');
    $command = (string) file_get_contents(__DIR__.'/../../app/Console/Commands/MonitoringProtocolPolicyEvidence.php');
    $script = (string) file_get_contents(__DIR__.'/../../scripts/monitoring/verify-protocol-policy-evidence.sh');

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
    expect($script)
        ->toContain(
            'samples must be an integer of at least 5.',
            'interval-seconds must be an integer of at least 60.',
            'monitoring:protocol-policy-evidence',
            '($report["all_verified"] ?? null) !== true',
            '$requiredProtocols',
            '$requiredPolicy',
            '$requiredProviders = ["provider_unifi", "provider_milesight"]',
            'evidence_matrix_fingerprint',
            'the protocol, provider, or policy evidence set changed during the observation period.',
            '$report["execution_cursor"] ?? null',
            'previous_execution_cursor',
            'execution_advanced=false',
            'persisted protocol, listener, provider, or policy execution evidence did not advance during the observation period.',
            'observation_seconds',
        )
        ->not->toContain('echo "$report"', '--insecure');
});
