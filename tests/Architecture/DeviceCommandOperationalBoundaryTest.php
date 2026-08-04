<?php

it('keeps every required Device command recovery runbook executable and evidence-led', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $runbooks = [
        'failed-or-uncertain-device-command.md' => ['Never repeat', 'Export audit evidence'],
        'stuck-device-command-approval.md' => ['requester cannot self-approve', 'expires'],
        'device-command-break-glass-review.md' => ['Confirmed appropriate', 'Incident required'],
        'bulk-device-command-partial-failure.md' => ['never converted into blanket success', 'result ledger'],
        'device-command-audit-evidence-export.md' => ['audit_chain.linked', 'evidence_exported'],
    ];

    foreach ($runbooks as $filename => $required) {
        $path = $root.'/docs/runbooks/security-devices/'.$filename;
        expect($path)->toBeFile();
        $content = file_get_contents($path);
        expect($content)->toContain(...$required)
            ->not->toContain('queue:retry monitoring-commands', 'password=', 'Bearer ey');
    }

    $collector = file_get_contents($root.'/docs/runbooks/monitoring/collector-outage-and-revocation.md');
    $provider = file_get_contents($root.'/docs/runbooks/monitoring/provider-outage.md');
    $credential = file_get_contents(
        $root.'/docs/runbooks/security-devices/credential-compromise-containment-and-rotation.md',
    );
    expect($collector)->toContain('Recovery and ordered return')
        ->and($provider)->toContain('Recovery and replay')
        ->and($credential)->toContain('Containment and recovery procedure');
});

it('keeps individual command evidence permission checked and free of unsafe source fields', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $presenter = file_get_contents(
        $root.'/app/Domain/SecurityDevices/Management/Services/DeviceCommandEvidencePresenter.php',
    );
    $controller = file_get_contents(
        $root.'/app/Domain/SecurityDevices/Management/Http/Controllers/DeviceCommandEvidenceController.php',
    );

    expect($presenter)->toContain(
        'ManagementLevel::Observe',
        'abort_unless($decision->allowed, 404)',
        "'audit_chain'",
        "'redactions'",
    )->not->toContain(
        "'reason' => \$command->reason",
        "'comment' => \$approval->comment",
        "'signature' => \$command->signature",
        "'provider_request_reference' => \$attempt->provider_request_reference",
        "'break_glass_reason' =>",
    );
    expect($controller)->toContain(
        "'evidence_exported'",
        "'Cache-Control' => 'no-store, private'",
        'assertCanView',
    );
});

it('keeps legacy Queclink command history read only and removes the raw command retry shortcut', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $routes = file_get_contents($root.'/routes/security-devices.php');
    $controller = file_get_contents(
        $root.'/app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php',
    );
    $workspace = file_get_contents(
        $root.'/resources/js/pages/security-devices/integrations/queclink-hub.tsx',
    );

    expect($routes)->not->toContain(
        '/commands/{command}/retry',
        'security-devices.integrations.queclink.commands.retry',
        'retryCommand',
    );
    expect($controller)->not->toContain(
        'public function retryCommand',
        "'raw_command' => \$command->raw_command",
        "'event_type' => 'retry'",
    );
    expect($workspace)->toContain(
        'Legacy provider-console history · read only · content protected',
        'New or repeated',
        'actions must start from Device Management.',
        'Open Device Management',
    )->not->toContain('commands/${command.id}/retry');
});
