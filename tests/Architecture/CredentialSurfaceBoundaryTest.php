<?php

use App\Domain\SecurityDevices\Credentials\Jobs\ReconcileCredentialLeases;

it('keeps reusable credential material out of queue notification export and browser source surfaces', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $queuePayload = serialize(new ReconcileCredentialLeases);
    expect($queuePayload)->not->toContain(
        'lease_id', 'secret_manager_reference', 'password', 'passphrase', 'material',
    );

    $notificationAndExportSource = '';
    $roots = [
        $root.'/app/Notifications',
        $root.'/app/Domain/SecurityDevices',
    ];
    foreach ($roots as $surfaceRoot) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $surfaceRoot,
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php'
                || ! preg_match('/(?:Notification|Export)\.php$/', $file->getFilename())) {
                continue;
            }
            $notificationAndExportSource .= file_get_contents($file->getPathname())."\n";
        }
    }
    expect($notificationAndExportSource)->not->toContain(
        'CredentialLease', 'CredentialReference', 'secret_manager_reference', 'sealed_material',
    );

    $presenter = file_get_contents($root.'/app/Domain/SecurityDevices/Presenters/SettingsAuditPresenter.php');
    expect($presenter)->toContain("'reference_key' => \$reference->reference_key")
        ->not->toContain(
            "'secret_manager_reference' =>",
            "'lease_id' =>",
            "'material' =>",
        );

    $runbook = file_get_contents(
        $root.'/docs/runbooks/security-devices/credential-compromise-containment-and-rotation.md',
    );
    expect($runbook)->toContain('Never paste passwords', 'verify-credential-containment')
        ->not->toContain('BEGIN PRIVATE KEY', 'Bearer ey', 'password=');

    $bootstrap = file_get_contents($root.'/bootstrap/app.php');
    $lease = file_get_contents($root.'/app/Domain/Monitoring/Data/CredentialLease.php');
    $runner = file_get_contents($root.'/collector/src/Runtime/ProbeRunner.php');
    expect($bootstrap)->toContain("'secret_manager_reference'", "'lease_id'", "'private_key'")
        ->and($lease)->toContain('#[\\SensitiveParameter] array $material')
        ->and($runner)->toContain('#[\\SensitiveParameter] array $material');
});
