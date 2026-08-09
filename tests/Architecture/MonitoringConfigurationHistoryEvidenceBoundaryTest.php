<?php

it('keeps A10 production configuration history proof attested isolated value free and fail closed', function (): void {
    $root = dirname(__DIR__, 2);
    $command = (string) file_get_contents($root.'/app/Console/Commands/MonitoringConfigurationHistoryEvidence.php');
    $contract = (string) file_get_contents($root.'/app/Domain/Monitoring/Support/ConfigurationHistoryEvidenceContract.php');
    $service = (string) file_get_contents($root.'/app/Domain/Monitoring/Services/ConfigurationHistoryEvidenceService.php');
    $runbook = (string) file_get_contents($root.'/docs/runbooks/monitoring/configuration-history-release-acceptance.md');

    expect($command)->toContain(
        'monitoring:configuration-history-evidence',
        '{--manifest=',
        '{--restore-evidence=',
        '{--browser-evidence=',
        '$contract->restoredRuntimeIsIsolated(',
        'All three external evidence paths are required. No monitoring store was read.',
        'No target or configuration value was emitted.',
        'loadRestoreEvidence',
        'return $report[\'all_verified\'] ? self::SUCCESS : self::FAILURE;',
    )->and(strpos($command, '$contract->restoredRuntimeIsIsolated('))
        ->toBeLessThan(strpos($command, 'loadProductionManifest'));

    expect($contract)->toContain(
        'use App\\Support\\Monitoring\\StrictJsonObjectDecoder;',
        '(new StrictJsonObjectDecoder)->decode($encoded, 32)',
        "app()->environment('restore-verification')",
        "class_exists('PHPUnit\\\\Framework\\\\TestCase', false)",
        'InfluxDbTimeSeriesStore::class',
        'LaravelSnapshotStore::class',
        "'MONITORING_RESTORE_MYSQL_DSN'",
        "'MONITORING_RESTORE_INFLUX_URL'",
        "'MONITORING_RESTORE_INFLUX_TOKEN'",
        "'MONITORING_RESTORE_INFLUX_ORG'",
        "'MONITORING_RESTORE_INFLUX_BUCKET'",
        "'MONITORING_RESTORE_FILESYSTEM_ROOT'",
        "'MONITORING_A10_EVIDENCE_DIRECTORY'",
        "'MONITORING_A10_EVIDENCE_HMAC_KEY'",
        "'MONITORING_A10_PRODUCTION_ATTESTATION_PUBLIC_KEY'",
        "'MONITORING_A10_BROWSER_ATTESTATION_PUBLIC_KEY'",
        "'OBLIVION_RELEASE_REVISION'",
        'sodium_crypto_sign_verify_detached(',
        "'oblivion-a10-production-manifest-v2'",
        "'oblivion-a10-browser-evidence-v2'",
        "'changed_firmware_hmac_sha256'",
        "'evidence_sha256'",
        "'recovery_objectives_met'",
        '($permissions & 0077) === 0',
        "'MONITORING_A10_WINDOWS_ACL_ALLOWED_IDENTITIES'",
        '$ErrorActionPreference = \'Stop\'',
        'Microsoft.PowerShell.Security.psd1',
        'Get-Acl -LiteralPath $path',
        '$acl.AreAccessRulesProtected',
    )->not->toContain(
        "'target_reference_sha256'",
        "'baseline_firmware_sha256'",
        "'changed_firmware_sha256'",
        "'capacity_external_key_sha256'",
    );

    expect($service)->toContain(
        '! $this->contract->restoredRuntimeIsIsolated(',
        'SnapshotStore::RESTORE_HEALTH_PATH',
        'SnapshotStore::RESTORE_HEALTH_CONTENT',
        "'monitoring-restore'",
        "'mysql.baseline_snapshot_uuid'",
        "'mysql.changed_snapshot_uuid'",
        "'commitments.target_identity_hmac_sha256'",
        '$this->structuralDiff($baselinePayload, $changedPayload, $maximum)',
        '$diff === $expected',
        "'commitments.changed_firmware_hmac_sha256'",
        "DB::table('monitoring_metric_series_pointer_events')",
        '$this->timeSeries->healthy()',
        '$hasFrom && $hasTo',
        "'verified_restore_artifact'",
        "'restored_browser_companion_linkage'",
    )->not->toContain(
        "data_get(\$manifest, 'hashes.",
        'hash(\'sha256\', $baselineFirmware)',
        'hash(\'sha256\', (string) $series->external_key)',
    );

    expect($runbook)->toContain(
        'bounded A10 acceptance gate',
        'must never be labelled as production proof',
        'exact process-scoped restore endpoints',
        'Ed25519 public keys from distinct',
        'keyed HMAC',
        'verified restore reconciliation artifact',
        'Duplicate JSON object keys are rejected recursively',
        'private evidence directory',
        'recomputes the structural diff',
        'exact changed firmware commitment',
        '`1440 x 900`',
        '`1280 x 800`',
        '--restore-evidence=',
        'This gate does not by itself close A05, V09, V10, deployment, or the overall release.',
    );
});
