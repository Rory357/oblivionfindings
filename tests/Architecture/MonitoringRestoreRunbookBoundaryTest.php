<?php

it('keeps the executable restore verifier aligned with the value-free reconciliation contract', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $service = file_get_contents($root.'/app/Domain/Monitoring/Services/MonitoringRestoreReconciliationService.php');
    $snapshotService = file_get_contents($root.'/app/Domain/Monitoring/Services/ConfigurationSnapshotService.php');
    $snapshotStore = file_get_contents($root.'/app/Domain/Monitoring/Contracts/SnapshotStore.php');
    $probe = file_get_contents($root.'/app/Infrastructure/Monitoring/NativeRestoreDependencyProbe.php');
    $vault = file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Services/HashicorpVaultLeaseIssuer.php');
    $command = file_get_contents($root.'/app/Console/Commands/MonitoringRestoreReport.php');
    $filesystems = file_get_contents($root.'/config/filesystems.php');
    $script = file_get_contents($root.'/scripts/monitoring/verify-restore.ps1');
    $runbook = file_get_contents($root.'/docs/runbooks/monitoring/storage-restore.md');
    $authority = file_get_contents($root.'/app/Support/Monitoring/RestoreReleaseAuthorityVerifier.php');
    $authorityScript = file_get_contents($root.'/scripts/monitoring/verify-restore-authority.php');
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
        ->toContain('read(self::SNAPSHOT_HEALTH_PATH)')
        ->toContain('hash_equals(SnapshotStore::RESTORE_HEALTH_CONTENT, $contents)')
        ->toContain('$this->secretManager->healthy()')
        ->not->toContain('writePoints(', '->put(', '->delete(')
        ->and($snapshotStore)
        ->toContain("RESTORE_HEALTH_PATH = 'monitoring/configuration-snapshots/.restore-health-check'")
        ->toContain("RESTORE_HEALTH_CONTENT = 'oblivion-monitoring-snapshot-store-v1'")
        ->and($snapshotService)
        ->toContain('$this->ensureRestoreHealthSentinel();')
        ->toContain('hash_equals(SnapshotStore::RESTORE_HEALTH_CONTENT, $contents)')
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
        ->toContain('APP_CONFIG_CACHE\', $isolatedConfigCache')
        ->toContain('[IO.Path]::GetTempPath()')
        ->toContain('monitoring:reconcile-restore --assert-process-config --config-only')
        ->toContain('migrate --pretend --force')
        ->toContain('monitoring:reconcile-restore --json --assert-process-config')
        ->toContain("Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_MYSQL_DSN'")
        ->toContain("Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_REDIS_URL'")
        ->toContain("Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_INFLUX_URL'")
        ->toContain("Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_VAULT_URL'")
        ->toContain("Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_FILESYSTEM_DRIVER'")
        ->toContain("Get-RequiredProcessEnvironmentValue -Name 'MONITORING_RESTORE_FILESYSTEM_ROOT'")
        ->toContain("'MONITORING_RESTORE_OBJECT_BUCKET'")
        ->toContain("'MONITORING_RESTORE_OBJECT_ENDPOINT'")
        ->not->toContain('[string] $MySqlDsn', '[string] $RedisUrl', '[string] $InfluxUrl', '[string] $VaultUrl')
        ->toContain("[ValidatePattern('^BKP-[a-f0-9]{32}$')]")
        ->toContain('[Parameter(Mandatory)] [DateTimeOffset] $RecoveryPointUtc')
        ->toContain('[Parameter(Mandatory)] [DateTimeOffset] $RecoveryStartedAtUtc')
        ->toContain('[string] $BackupManifestSha256')
        ->toContain('[string] $RestoredEnvironmentReferenceSha256')
        ->toContain('[Parameter(Mandatory)] [string] $OutputDirectory')
        ->toContain("[ValidatePattern('^[a-f0-9]{64}$')]")
        ->toContain('$RecoveryPointUtc.Offset -ne [TimeSpan]::Zero')
        ->toContain('$RecoveryPointUtc -gt $RecoveryStartedAtUtc')
        ->toContain('$RecoveryStartedAtUtc -gt $verificationStartedAtUtc')
        ->toContain('$rpoMinutes = ($RecoveryStartedAtUtc - $RecoveryPointUtc).TotalMinutes')
        ->toContain('$rtoMinutes = ($verificationCompletedAtUtc - $RecoveryStartedAtUtc).TotalMinutes')
        ->toContain('$evidence[\'backup_generation\'] = $releaseAuthority.backup_generation')
        ->toContain('$evidence[\'backup_manifest_sha256\'] = $releaseAuthority.backup_manifest_sha256')
        ->toContain('$evidence[\'recovery_objectives_met\'] = $recoveryObjectivesMet')
        ->toContain('$evidence[\'schema_version\'] = 3')
        ->toContain('$evidence[\'evidence_class\'] = \'isolated-restore-reconciliation-v3\'')
        ->toContain('$evidence[\'release_revision\'] = $releaseRevision')
        ->toContain('$evidence[\'restored_environment_reference_sha256\'] = $releaseAuthority.restored_environment_reference_sha256')
        ->toContain('$evidence[\'restore_authority_reference\'] = $releaseAuthority.authority_reference')
        ->toContain('$evidence[\'restore_authority_sha256\'] = $releaseAuthority.authority_sha256')
        ->toContain('$evidence[\'restore_release_evidence\'] = $releasePassed')
        ->toContain('$evidence[\'checkout_clean_verified\'] = $true')
        ->toContain('if (-not $recoveryObjectivesMet)')
        ->toContain('$value -isnot [long]')
        ->toContain('$value -lt 0')
        ->toContain('$report.$_ -ne 0')
        ->not->toContain('[int] $report.$_')
        ->toContain("ToString('yyyyMMddTHHmmssfffffffZ', [Globalization.CultureInfo]::InvariantCulture)")
        ->toContain('$evidenceNonce = [Guid]::NewGuid()')
        ->toContain('("reconciliation-{0}-{1}.json" -f $evidenceTimestamp, $evidenceNonce)')
        ->toContain('[IO.FileMode]::CreateNew')
        ->toContain('[IO.FileShare]::None')
        ->toContain('$evidenceStream.Flush($true)')
        ->toContain('$checksumStream.Flush($true)')
        ->toContain('$checksumPath = "$outputPath.sha256"')
        ->toContain('[Security.Cryptography.SHA256]::HashData')
        ->toContain('[IO.File]::ReadAllBytes($outputPath)')
        ->toContain('$evidenceCreated -and -not $evidenceCommitted')
        ->toContain('$checksumCreated -and -not $evidenceCommitted')
        ->toContain('[IO.Path]::GetRelativePath($resolvedApplication, $resolvedOutputDirectory)')
        ->toContain('OutputDirectory must be outside the application checkout.')
        ->toContain('Assert-PrivateEvidenceDirectory -Path $resolvedOutputDirectory')
        ->toContain('OutputDirectory must be mode 0700 and owned by the application service account.')
        ->not->toContain("ToString('yyyyMMddTHHmmssZ')", 'Set-Content -LiteralPath $outputPath', 'New-Item -ItemType Directory')
        ->toContain('Get-ChildItem Env:')
        ->toContain('$_.Name -like \'GIT_*\'')
        ->toContain("Get-ProtectedLinuxBinary -Path '/usr/bin/git' -Label 'Git'")
        ->toContain("Get-ProtectedLinuxBinary -Path '/usr/bin/php8.4' -Label 'PHP'")
        ->toContain("[Environment]::SetEnvironmentVariable('PATH', '/usr/bin:/bin', 'Process')")
        ->toContain("[Environment]::SetEnvironmentVariable('PHPRC', \$null, 'Process')")
        ->toContain("[Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', \$null, 'Process')")
        ->toContain("[Environment]::SetEnvironmentVariable('LD_PRELOAD', \$null, 'Process')")
        ->toContain("[Environment]::SetEnvironmentVariable('LD_LIBRARY_PATH', \$null, 'Process')")
        ->toContain('& $phpPath artisan monitoring:reconcile-restore --assert-process-config --config-only')
        ->toContain('& $phpPath artisan migrate --pretend --force')
        ->toContain('& $phpPath artisan monitoring:reconcile-restore --json --assert-process-config')
        ->not->toContain('(Get-Command git', '& php artisan')
        ->toContain('core.fsmonitor=false')
        ->toContain('core.untrackedCache=false')
        ->toContain("'refs/remotes/origin/main'")
        ->toContain("'status', '--porcelain=v1', '--untracked-files=all'")
        ->toContain('$completedReleaseRevision = Get-ReleaseRevision')
        ->toContain('$releaseAuthority = Get-RestoreReleaseAuthority')
        ->toContain('$completedReleaseAuthority = Get-RestoreReleaseAuthority')
        ->toContain('Restore inputs do not match the protected release authority.')
        ->toContain('The protected restore release authority changed during verification.')
        ->toContain("MONITORING_CREDENTIAL_DRIVER', 'vault'")
        ->toContain("MONITORING_VAULT_URL', \$VaultUrl")
        ->toContain('Refusing non-private restore host')
        ->and($authority)
        ->toContain("AUTHORITY_PATH = '/etc/oblivion/monitoring-restore-release-authority.json'")
        ->toContain('new StrictJsonObjectDecoder')
        ->toContain("(\$metadata['owner_uid'] ?? null) === 0")
        ->toContain("(\$metadata['mode'] & 0022) === 0")
        ->toContain("'backup_manifest_sha256'")
        ->and($authorityScript)
        ->toContain('RestoreReleaseAuthorityVerifier')
        ->not->toContain('vendor/autoload.php')
        ->and($command)
        ->toContain('{--assert-process-config')
        ->toContain('{--config-only')
        ->toContain("configuredValueMatches('DB_URL'")
        ->toContain("configuredValueMatches('REDIS_URL'")
        ->toContain("configuredValueMatches('MONITORING_TIMESERIES_URL'")
        ->toContain("configuredValueMatches('MONITORING_SNAPSHOT_DISK'")
        ->toContain("config('monitoring.storage.snapshots.disk') === 'monitoring-restore'")
        ->toContain("config('filesystems.disks.monitoring-restore.visibility') === 'private'")
        ->toContain("configuredValueMatches(\n                'MONITORING_RESTORE_FILESYSTEM_ROOT'")
        ->toContain("'MONITORING_RESTORE_OBJECT_ACCESS_KEY_ID'")
        ->toContain("'MONITORING_RESTORE_OBJECT_SECRET_ACCESS_KEY'")
        ->toContain("'MONITORING_RESTORE_OBJECT_REGION'")
        ->toContain("'MONITORING_RESTORE_OBJECT_BUCKET'")
        ->toContain("'MONITORING_RESTORE_OBJECT_ENDPOINT'")
        ->toContain("'MONITORING_RESTORE_OBJECT_PATH_STYLE'")
        ->toContain("configuredValueMatches('MONITORING_CREDENTIAL_DRIVER'")
        ->toContain("configuredValueMatches('MONITORING_VAULT_URL'")
        ->toContain('No restored store was read.')
        ->and($script)->toContain("ObjectDisk -cne 'monitoring-restore'")
        ->and($runbook)
        ->toContain('unique nonexistent config-cache path')
        ->toContain('exact restored local root or S3 bucket, endpoint, region, access identity and path-style mode')
        ->toContain('successful process-configuration preflight')
        ->toContain('written and read back successfully')
        ->toContain('Never create or repair the sentinel during the read-only verifier.')
        ->toContain('an older non-zero count is a recovery blocker')
        ->toContain('The script fails closed when either calculated objective exceeds its approved maximum')
        ->toContain('exact clean `HEAD == origin/main` release revision before and after reconciliation')
        ->toContain('fixed root-owned `/usr/bin/git` and `/usr/bin/php8.4` binaries')
        ->toContain('clears caller-supplied PHP configuration and dynamic-loader variables')
        ->toContain('pre-existing private output directory outside the application checkout')
        ->toContain('collision-safe JSON plus `.sha256` pair')
        ->toContain('side-effect-free exact-reference API')
        ->toContain('never repeat their values as parameters to a child `pwsh` process')
        ->toContain('Never print DSNs, tokens, snapshot paths, secret-manager references, lease identifiers, or payloads.');
});
