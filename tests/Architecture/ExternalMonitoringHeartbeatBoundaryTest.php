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
    expect($reporter)
        ->toContain("['flow', 'snmp_traps', 'syslog']", 'public function alive(')
        ->and($supervisor)->toContain(
            'monitoring:listen-snmp-traps',
            'monitoring:listen-syslog',
            'monitoring:listen-flow',
            'autorestart=true',
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
