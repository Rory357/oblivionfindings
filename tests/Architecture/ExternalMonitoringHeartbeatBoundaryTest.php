<?php

it('keeps every UDP listener alive while idle and separately supervised', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $livenessPath = $root.'/app/Domain/Monitoring/Services/UdpListenerLiveness.php';

    expect(file_exists($livenessPath))->toBeTrue();
    $liveness = (string) file_get_contents($livenessPath);
    expect($liveness)
        ->toContain('stream_set_blocking(', 'stream_select(', '->alive($listener)')
        ->not->toContain('sleep(');

    foreach (['SnmpTraps', 'Syslog', 'Flow'] as $listener) {
        $source = (string) file_get_contents($root."/app/Console/Commands/MonitoringListen{$listener}.php");
        expect($source)
            ->toContain('ListenerHeartbeatReporter', 'UdpListenerLiveness')
            ->toContain("->started('")
            ->toContain('->wait(');
    }

    $reporter = (string) file_get_contents($root.'/app/Domain/Monitoring/Services/ListenerHeartbeatReporter.php');
    $supervisor = (string) file_get_contents($root.'/ops/supervisor/oblivion-monitoring-listeners.conf');
    $snmpCommand = (string) file_get_contents($root.'/app/Console/Commands/MonitoringListenSnmpTraps.php');
    $monitoringConfig = (string) file_get_contents($root.'/config/monitoring.php');
    $environmentExample = (string) file_get_contents($root.'/.env.example');
    expect($reporter)
        ->toContain("['flow', 'snmp_traps', 'syslog']", 'public function alive(')
        ->and($supervisor)->toContain(
            'monitoring:listen-snmp-traps',
            'monitoring:listen-syslog',
            'monitoring:listen-flow',
            'autorestart=true',
        )
        ->and($snmpCommand)->toContain("config('monitoring.snmp.traps.port', 1162)")
        ->and($monitoringConfig)->toContain("env('MONITORING_SNMP_TRAP_PORT', 1162)")
        ->and($environmentExample)->toContain(
            'MONITORING_SNMP_TRAP_PORT=1162',
            '# PHP worker unprivileged',
            'standard UDP/162 edge port',
        );
});

it('withholds an external dead-man heartbeat unless the central runtime is genuinely ready', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $servicePath = $root.'/app/Domain/Monitoring/Services/ExternalMonitoringHeartbeatService.php';
    $modelPath = $root.'/app/Domain/Monitoring/Models/MonitoringExternalHeartbeatState.php';
    $commandPath = $root.'/app/Console/Commands/MonitoringSendExternalHeartbeat.php';

    expect(file_exists($servicePath))->toBeTrue()
        ->and(file_exists($modelPath))->toBeTrue()
        ->and(file_exists($commandPath))->toBeTrue();

    $service = (string) file_get_contents($servicePath);
    $command = (string) file_get_contents($commandPath);
    $egress = (string) file_get_contents($root.'/app/Domain/Monitoring/Services/EgressPolicy.php');
    $config = (string) file_get_contents($root.'/config/monitoring.php');
    $schedule = (string) file_get_contents($root.'/routes/console.php');
    $presenter = (string) file_get_contents($root.'/app/Domain/Monitoring/Services/MonitoringRuntimeHealthService.php');
    $page = (string) file_get_contents($root.'/resources/js/pages/security-devices/monitoring.tsx');
    $runbook = (string) file_get_contents($root.'/docs/runbooks/monitoring/runtime-and-regional-outage.md');

    expect($service)
        ->toContain(
            'workerStates()',
            "['snmp_traps', 'syslog', 'flow']",
            'authoriseExternalHttps(',
            'MonitoringExternalHeartbeatState::query()->updateOrCreate(',
            "'worker_unavailable'",
            "'listener_unavailable'",
            "'transport_failure'",
        )
        ->not->toContain('Log::', "'endpoint_url' =>", "'response_body' =>");
    expect($egress)
        ->toContain('public function authoriseExternalHttps(')
        ->toContain('target scheme must be HTTPS')
        ->toContain('resolved address is not public')
        ->and($command)->toContain('monitoring:send-external-heartbeat')
        ->and($schedule)->toContain("command('monitoring:send-external-heartbeat')", 'everyMinute()', 'onOneServer()', 'withoutOverlapping()')
        ->and($config)->toContain(
            'MONITORING_EXTERNAL_HEARTBEAT_ENABLED',
            'MONITORING_EXTERNAL_HEARTBEAT_URL',
            'MONITORING_EXTERNAL_HEARTBEAT_ALLOWED_HOSTS',
        )
        ->and($presenter)->toContain("'external_heartbeat' =>")
        ->and($page)->toContain('Independent outage watchdog', 'workspace.runtime.external_heartbeat')
        ->and($runbook)->toContain(
            'independently hosted dead-man monitor',
            'withholds its heartbeat',
            'does not send Site, Device, queue, credential, or customer data',
        );
});

it('requires independently signed release-bound watchdog outage evidence', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $verifierPath = $root.'/app/Support/Monitoring/ExternalWatchdogEvidenceVerifier.php';
    $commandPath = $root.'/scripts/monitoring/verify-external-watchdog-evidence.php';
    $authorityPath = $root.'/app/Support/Monitoring/CentralRuntimeReleaseAuthorityVerifier.php';
    $runbookPath = $root.'/docs/runbooks/monitoring/runtime-and-regional-outage.md';

    expect(file_exists($verifierPath))->toBeTrue()
        ->and(file_exists($commandPath))->toBeTrue()
        ->and(file_exists($authorityPath))->toBeTrue();

    $verifier = (string) file_get_contents($verifierPath);
    $command = (string) file_get_contents($commandPath);
    $authority = (string) file_get_contents($authorityPath);
    $runbook = (string) file_get_contents($runbookPath);

    expect($authority)->toContain(
        "AUTHORITY_PATH = '/etc/oblivion/monitoring-central-runtime-release-authority.json'",
        "'watchdog_attestation_public_key_sha256'",
        'StrictJsonObjectDecoder',
        'MAXIMUM_AUTHORITY_SECONDS = 86_400',
    )->and($verifier)->toContain(
        'sodium_crypto_sign_verify_detached',
        "'scheduler_outage'",
        "'worker_outage'",
        "'listener_outage'",
        "'regional_outage'",
        'MAXIMUM_ALARM_SECONDS = 360',
        'MAXIMUM_RECOVERY_SECONDS = 1_800',
        '$observationReferences',
        "isset(\$observationReferences[(string) \$evidence['provider_receipt_sha256']])",
        "hash('sha256', \$rawProviderReceipt)",
        '$outageStarted >= $alarmRaised',
        '$recoveryStarted >= $deliveryRestored',
        "'samples'",
        '$samples < 15',
        "'supervised_programs'",
        "'central_runtime_evidence_sha256'",
        "'signed_watchdog_evidence_sha256'",
        "'detached_signature_sha256'",
        "'external_watchdog_release_evidence' => true",
    )->and($command)->toContain(
        'LoadSoakReleaseCheckoutVerifier',
        "new LoadSoakReleaseCheckoutVerifier('/usr/bin/git')",
        "'central-runtime-evidence'",
        "'provider-receipt'",
        "'output-directory'",
        "'output_directory_protection'",
        "'public-key'",
        "'signature'",
        'identitiesRemainPinned([$authorityBefore, $authorityAfter])',
        'identitiesRemainPinned([$authorityBefore, $authorityAfter, $authorityFinal])',
        '$inputsRemainPinned($inputs)',
        "'evidence_changed'",
        "'collision_safe_exclusive_create'",
        "'worm_receipt_verified' => false",
        "@fopen(\$path, 'x+b')",
        '@chmod($path, 0600)',
        "function_exists('fsync')",
        '$artifactPublished = false',
        '$checksumPublished = false',
        '$publishedRemainsExact = static function',
        'hash_equals($expected, $contents)',
        '$publishedRemainsExact($artifactPath, $encoded)',
        '$publishedRemainsExact($checksumPath, $checksumEncoded)',
        "'artifact_sha256'",
        "'checksum_file'",
        "'external_watchdog_release_evidence' => false",
    )->not->toContain(
        'vendor/autoload.php',
        'git fetch',
        'git pull',
        'git reset',
        'git clean',
    )->and($runbook)->toContain(
        'four sequential events in this exact order',
        '`scheduler_outage`',
        '`worker_outage`',
        '`listener_outage`',
        '`regional_outage`',
        'unique opaque observation reference',
        'differs from the captured central-runtime JSON and every',
        'outage observation reference',
        'retained provider receipt bytes',
        'exact raw signed-watchdog and detached-signature SHA-256 values',
        'verify-external-watchdog-evidence.php',
        '--output-directory=',
        'exact mode `0700`',
        'mode-`0600` value-free',
        'matching `.sha256` sidecar',
        '`worm_receipt_verified=false`',
        'reopens both',
        'Do not conduct these outage drills against',
    );
});
