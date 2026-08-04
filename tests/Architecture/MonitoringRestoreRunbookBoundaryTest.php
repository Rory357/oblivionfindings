<?php

it('keeps the executable restore verifier aligned with the value-free reconciliation contract', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $service = file_get_contents($root.'/app/Domain/Monitoring/Services/MonitoringRestoreReconciliationService.php');
    $probe = file_get_contents($root.'/app/Infrastructure/Monitoring/NativeRestoreDependencyProbe.php');
    $vault = file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Services/HashicorpVaultLeaseIssuer.php');
    $filesystems = file_get_contents($root.'/config/filesystems.php');
    $script = file_get_contents($root.'/scripts/monitoring/verify-restore.ps1');
    $runbook = file_get_contents($root.'/docs/runbooks/monitoring/storage-restore.md');
    $keys = [
        'outbox_gap',
        'inbox_checkpoint_gap',
        'orphan_series',
        'timeseries_pointer_gap',
        'snapshot_hash_mismatch',
        'topology_pointer_gap',
        'collector_sequence_regression',
        'stale_unpublished_delivery',
        'published_projection_gap',
        'provider_cursor_scope_gap',
        'provider_cursor_stall',
        'credential_reference_recovery_gap',
        'credential_lease_recovery_gap',
        'redis_unavailable',
        'timeseries_unavailable',
        'snapshot_store_unavailable',
        'secret_manager_unavailable',
    ];

    foreach ($keys as $key) {
        expect($service)->toContain("'{$key}' =>")
            ->and($script)->toContain("'{$key}'")
            ->and($runbook)->toContain("`{$key}=0`");
    }

    expect($probe)
        ->toContain("command('ping')")
        ->toContain('->healthy()')
        ->toContain('exists(self::SNAPSHOT_HEALTH_PATH)')
        ->toContain('$this->secretManager->healthy()')
        ->toContain('monitoring/configuration-snapshots/.restore-health-check')
        ->not->toContain('writePoints(', '->put(', '->delete(')
        ->and($service)
        ->toContain('$this->timeSeries->exists(')
        ->toContain("->whereNotNull('first_point_at')")
        ->toContain("->whereNotNull('last_point_at')")
        ->and($vault)
        ->toContain("head('/v1/sys/health'")
        ->toContain("'standbyok' => 'true'")
        ->toContain("'perfstandbyok' => 'true'")
        ->and($filesystems)
        ->toContain("'monitoring-restore' =>")
        ->toContain("'visibility' => 'private'")
        ->toContain("'serve' => false")
        ->toContain("'throw' => true")
        ->and($script)
        ->toContain("APP_ENV', 'restore-verification")
        ->toContain('migrate --pretend --force')
        ->toContain('monitoring:reconcile-restore --json')
        ->toContain("MONITORING_CREDENTIAL_DRIVER', 'vault'")
        ->toContain("MONITORING_VAULT_URL', \$VaultUrl")
        ->toContain('Refusing non-private restore host')
        ->and($runbook)
        ->toContain('an older non-zero count is a recovery blocker')
        ->toContain('side-effect-free exact-reference API')
        ->toContain('Never print DSNs, tokens, snapshot paths, secret-manager references, lease identifiers, or payloads.');
});
