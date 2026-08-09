<?php

namespace App\Domain\Monitoring\Support;

use App\Infrastructure\Monitoring\InfluxDbTimeSeriesStore;
use App\Infrastructure\Monitoring\LaravelSnapshotStore;
use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;
use App\Support\Monitoring\StrictJsonObjectDecoder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ConfigurationHistoryEvidenceContract
{
    private const int MAXIMUM_FILE_BYTES = 65_536;

    private const array RESTORE_ZERO_CHECKS = [
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

    /** @var array<string, mixed>|null */
    private ?array $authoritySnapshot = null;

    /**
     * The overrides are test seams only. Production callers resolve this
     * contract without arguments and must use the fixed installed authority
     * plus the exact clean checkout gate.
     *
     * @param  array<string, mixed>|null  $verifiedReleaseAuthority
     */
    public function __construct(
        private readonly ?array $verifiedReleaseAuthority = null,
        private readonly ?bool $verifiedCheckout = null,
    ) {}

    public function restoredRuntimeIsIsolated(
        string $snapshotStoreClass,
        string $timeSeriesStoreClass,
        ?string $repositoryRoot = null,
    ): bool {
        return $this->runtimeErrors(
            $snapshotStoreClass,
            $timeSeriesStoreClass,
            $repositoryRoot ?? base_path(),
        ) === [];
    }

    /** @return list<string> */
    public function runtimeErrors(
        string $snapshotStoreClass,
        string $timeSeriesStoreClass,
        string $repositoryRoot,
        ?bool $testRuntime = null,
    ): array {
        $errors = [];
        $testRuntime ??= app()->runningUnitTests()
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')
            || class_exists('PHPUnit\\Framework\\TestCase', false);
        if ($testRuntime) {
            $errors[] = 'test_runtime_ineligible';
        }
        if (! app()->environment('restore-verification') || config('app.debug') !== false) {
            $errors[] = 'restore_runtime_required';
        }
        if (config('database.default') !== 'mysql'
            || ! $this->configuredValueMatches(
                'MONITORING_RESTORE_MYSQL_DSN',
                config('database.connections.mysql.url'),
            )
            || ! $this->processValuesMatch('MONITORING_RESTORE_MYSQL_DSN', 'DB_URL')) {
            $errors[] = 'restore_mysql_scope_mismatch';
        }
        if ($timeSeriesStoreClass !== InfluxDbTimeSeriesStore::class
            || config('monitoring.storage.timeseries.driver') !== 'influxdb'
            || ! $this->configuredValueMatches(
                'MONITORING_RESTORE_INFLUX_URL',
                config('monitoring.storage.timeseries.url'),
            )
            || ! $this->processValuesMatch('MONITORING_RESTORE_INFLUX_URL', 'MONITORING_TIMESERIES_URL')
            || ! $this->configuredValueMatches(
                'MONITORING_RESTORE_INFLUX_TOKEN',
                config('monitoring.storage.timeseries.token'),
            )
            || ! $this->configuredValueMatches(
                'MONITORING_RESTORE_INFLUX_ORG',
                config('monitoring.storage.timeseries.organisation'),
            )
            || ! $this->configuredValueMatches(
                'MONITORING_RESTORE_INFLUX_BUCKET',
                config('monitoring.storage.timeseries.bucket'),
            )) {
            $errors[] = 'restore_influx_scope_mismatch';
        }
        if ($snapshotStoreClass !== LaravelSnapshotStore::class
            || ! $this->restoredObjectStoreMatches($repositoryRoot)) {
            $errors[] = 'restore_object_scope_mismatch';
        }
        if ($this->evidenceDirectory($repositoryRoot) === null) {
            $errors[] = 'private_evidence_directory_required';
        }
        try {
            $authority = $this->releaseAuthority();
            if ($this->hmacKey($authority) === null) {
                $errors[] = 'evidence_hmac_key_mismatch';
            }
            if (! $this->checkoutVerified($repositoryRoot, (string) $authority['release_revision'])) {
                $errors[] = 'release_checkout_unverified';
            }
        } catch (Throwable) {
            $errors[] = 'protected_release_authority_required';
        }

        return array_values(array_unique($errors));
    }

    /** @return array<string, mixed> */
    public function loadProductionManifest(string $path, string $repositoryRoot): array
    {
        $authority = $this->releaseAuthority();

        return $this->validateProductionManifest(
            $this->loadExternalJson($path, $repositoryRoot),
            publicKey: $authority['production_public_key'],
            expectedRevision: $authority['release_revision'],
            expectedAclReference: $authority['evidence_acl_reference'],
            expectedRestoredEnvironmentReference: $authority['restored_environment_reference_sha256'],
        );
    }

    /** @return array<string, mixed> */
    public function loadBrowserEvidence(string $path, string $repositoryRoot): array
    {
        $authority = $this->releaseAuthority();

        return $this->validateBrowserEvidence(
            $this->loadExternalJson($path, $repositoryRoot),
            publicKey: $authority['browser_public_key'],
            expectedRevision: $authority['release_revision'],
            expectedAclReference: $authority['evidence_acl_reference'],
            expectedRestoredEnvironmentReference: $authority['restored_environment_reference_sha256'],
        );
    }

    /** @return array{document: array<string, mixed>, sha256: string} */
    public function loadRestoreEvidence(string $path, string $repositoryRoot): array
    {
        $authority = $this->releaseAuthority();
        $resolved = $this->externalFile($path, $repositoryRoot);
        $encoded = file_get_contents($resolved);
        if (! is_string($encoded)) {
            $this->refuse();
        }
        $sha256 = hash('sha256', $encoded);
        $checksumPath = $this->externalFile($path.'.sha256', $repositoryRoot);
        $checksum = file_get_contents($checksumPath);
        $expectedChecksum = $sha256.'  '.basename($resolved)."\n";
        if (! is_string($checksum) || ! hash_equals($expectedChecksum, $checksum)) {
            $this->refuse();
        }

        return [
            'document' => $this->validateRestoreEvidence(
                $this->decode($encoded),
                expectedRevision: $authority['release_revision'],
                expectedRestoredEnvironmentReference: $authority['restored_environment_reference_sha256'],
            ),
            'sha256' => $sha256,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function validateProductionManifest(
        array $manifest,
        ?CarbonImmutable $now = null,
        ?string $publicKey = null,
        ?string $expectedRevision = null,
        ?string $expectedAclReference = null,
        ?string $expectedRestoredEnvironmentReference = null,
    ): array {
        $this->exactKeys($manifest, [
            'schema_version',
            'evidence_class',
            'source_environment',
            'fixture',
            'synthetic',
            'real_host',
            'authoritative',
            'release_revision',
            'target_reference',
            'collection_run_reference',
            'evidence_acl_reference',
            'observation_started_at_utc',
            'observation_completed_at_utc',
            'mysql',
            'commitments',
            'restore',
            'review',
            'attestation',
        ]);
        $this->verifyAttestation(
            $manifest,
            'oblivion-a10-production-manifest-v2',
            $publicKey ?? (string) $this->releaseAuthority()['production_public_key'],
        );

        if (($manifest['schema_version'] ?? null) !== 2
            || ($manifest['evidence_class'] ?? null) !== 'production-real-host-configuration-history'
            || ($manifest['source_environment'] ?? null) !== 'production'
            || ($manifest['fixture'] ?? null) !== false
            || ($manifest['synthetic'] ?? null) !== false
            || ($manifest['real_host'] ?? null) !== true
            || ($manifest['authoritative'] ?? null) !== true) {
            $this->refuse();
        }

        $this->sha256($manifest['release_revision'] ?? null, 40);
        $expectedRevision ??= $this->currentReleaseRevision(base_path());
        if (! is_string($expectedRevision)
            || ! hash_equals($expectedRevision, (string) $manifest['release_revision'])) {
            $this->refuse();
        }
        $this->reference($manifest['target_reference'] ?? null, 'TARGET');
        $this->reference($manifest['collection_run_reference'] ?? null, 'RUN');
        $this->reference($manifest['evidence_acl_reference'] ?? null, 'ACL');
        $expectedAclReference ??= (string) $this->releaseAuthority()['evidence_acl_reference'];
        if (! hash_equals($expectedAclReference, (string) $manifest['evidence_acl_reference'])) {
            $this->refuse();
        }

        $started = $this->utc($manifest['observation_started_at_utc'] ?? null);
        $completed = $this->utc($manifest['observation_completed_at_utc'] ?? null);
        $now ??= CarbonImmutable::now('UTC');
        if (! $started->lt($completed)
            || $completed->gt($now)
            || $started->diffInHours($completed, true) > 720) {
            $this->refuse();
        }

        $mysql = $this->map($manifest['mysql'] ?? null);
        $this->exactKeys($mysql, [
            'baseline_snapshot_id',
            'baseline_snapshot_uuid',
            'changed_snapshot_id',
            'changed_snapshot_uuid',
            'capacity_series_id',
            'capacity_pointer_event_id',
        ]);
        foreach (['baseline_snapshot_id', 'changed_snapshot_id', 'capacity_series_id', 'capacity_pointer_event_id'] as $key) {
            if (! is_int($mysql[$key]) || $mysql[$key] < 1) {
                $this->refuse();
            }
        }
        foreach (['baseline_snapshot_uuid', 'changed_snapshot_uuid'] as $key) {
            if (! is_string($mysql[$key]) || ! Str::isUuid($mysql[$key])) {
                $this->refuse();
            }
        }
        if ($mysql['baseline_snapshot_id'] === $mysql['changed_snapshot_id']
            || hash_equals($mysql['baseline_snapshot_uuid'], $mysql['changed_snapshot_uuid'])) {
            $this->refuse();
        }

        $commitments = $this->map($manifest['commitments'] ?? null);
        $this->exactKeys($commitments, [
            'baseline_content_hmac_sha256',
            'changed_content_hmac_sha256',
            'baseline_configuration_hmac_sha256',
            'changed_configuration_hmac_sha256',
            'baseline_storage_path_hmac_sha256',
            'changed_storage_path_hmac_sha256',
            'diff_summary_hmac_sha256',
            'baseline_firmware_hmac_sha256',
            'changed_firmware_hmac_sha256',
            'capacity_external_key_hmac_sha256',
            'target_identity_hmac_sha256',
        ]);
        foreach ($commitments as $commitment) {
            $this->sha256($commitment);
        }
        if (hash_equals($commitments['baseline_content_hmac_sha256'], $commitments['changed_content_hmac_sha256'])
            || hash_equals($commitments['baseline_configuration_hmac_sha256'], $commitments['changed_configuration_hmac_sha256'])) {
            $this->refuse();
        }

        $restore = $this->map($manifest['restore'] ?? null);
        $this->exactKeys($restore, [
            'backup_generation_reference',
            'recovery_point_at_utc',
            'evidence_sha256',
            'restored_environment_reference_sha256',
        ]);
        $this->reference($restore['backup_generation_reference'] ?? null, 'BKP');
        $this->sha256($restore['evidence_sha256'] ?? null);
        $this->sha256($restore['restored_environment_reference_sha256'] ?? null);
        $expectedRestoredEnvironmentReference ??= (string) $this->releaseAuthority()['restored_environment_reference_sha256'];
        if (! hash_equals(
            $expectedRestoredEnvironmentReference,
            (string) $restore['restored_environment_reference_sha256'],
        )) {
            $this->refuse();
        }
        $recoveryPoint = $this->utc($restore['recovery_point_at_utc'] ?? null);
        if ($recoveryPoint->lt($completed) || $recoveryPoint->gt($now)) {
            $this->refuse();
        }

        $review = $this->map($manifest['review'] ?? null);
        $this->exactKeys($review, [
            'approved_change_reference',
            'operator_reference',
            'reviewer_reference',
            'decision',
        ]);
        $this->reference($review['approved_change_reference'] ?? null, 'CHG');
        $this->reference($review['operator_reference'] ?? null, 'OP');
        $this->reference($review['reviewer_reference'] ?? null, 'RV');
        if (($review['decision'] ?? null) !== 'approved') {
            $this->refuse();
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function validateBrowserEvidence(
        array $evidence,
        ?CarbonImmutable $now = null,
        ?string $publicKey = null,
        ?string $expectedRevision = null,
        ?string $expectedAclReference = null,
        ?string $expectedRestoredEnvironmentReference = null,
    ): array {
        $this->exactKeys($evidence, [
            'schema_version',
            'evidence_class',
            'environment',
            'fixture',
            'synthetic',
            'release_revision',
            'restored_environment_reference_sha256',
            'backup_generation_reference',
            'evidence_reference',
            'evidence_acl_reference',
            'verified_at_utc',
            'route_contract',
            'mysql',
            'commitments',
            'viewports',
            'result',
            'attestation',
        ]);
        $this->verifyAttestation(
            $evidence,
            'oblivion-a10-browser-evidence-v2',
            $publicKey ?? (string) $this->releaseAuthority()['browser_public_key'],
        );

        if (($evidence['schema_version'] ?? null) !== 2
            || ($evidence['evidence_class'] ?? null) !== 'restored-production-browser-companion'
            || ($evidence['environment'] ?? null) !== 'restore-verification'
            || ($evidence['fixture'] ?? null) !== false
            || ($evidence['synthetic'] ?? null) !== false
            || ($evidence['route_contract'] ?? null) !== 'security-devices.network-it.configuration-firmware'
            || ($evidence['result'] ?? null) !== 'passed') {
            $this->refuse();
        }

        $this->sha256($evidence['release_revision'] ?? null, 40);
        $expectedRevision ??= $this->currentReleaseRevision(base_path());
        if (! is_string($expectedRevision)
            || ! hash_equals($expectedRevision, (string) $evidence['release_revision'])) {
            $this->refuse();
        }
        $this->sha256($evidence['restored_environment_reference_sha256'] ?? null);
        $expectedRestoredEnvironmentReference ??= (string) $this->releaseAuthority()['restored_environment_reference_sha256'];
        if (! hash_equals(
            $expectedRestoredEnvironmentReference,
            (string) $evidence['restored_environment_reference_sha256'],
        )) {
            $this->refuse();
        }
        $this->reference($evidence['backup_generation_reference'] ?? null, 'BKP');
        $this->reference($evidence['evidence_reference'] ?? null, 'BROWSER');
        $this->reference($evidence['evidence_acl_reference'] ?? null, 'ACL');
        $expectedAclReference ??= (string) $this->releaseAuthority()['evidence_acl_reference'];
        if (! hash_equals($expectedAclReference, (string) $evidence['evidence_acl_reference'])) {
            $this->refuse();
        }
        $verified = $this->utc($evidence['verified_at_utc'] ?? null);
        if ($verified->gt($now ?? CarbonImmutable::now('UTC'))) {
            $this->refuse();
        }

        $mysql = $this->map($evidence['mysql'] ?? null);
        $this->exactKeys($mysql, ['changed_snapshot_id', 'changed_snapshot_uuid', 'capacity_series_id']);
        foreach (['changed_snapshot_id', 'capacity_series_id'] as $key) {
            if (! is_int($mysql[$key]) || $mysql[$key] < 1) {
                $this->refuse();
            }
        }
        if (! is_string($mysql['changed_snapshot_uuid']) || ! Str::isUuid($mysql['changed_snapshot_uuid'])) {
            $this->refuse();
        }

        $commitments = $this->map($evidence['commitments'] ?? null);
        $this->exactKeys($commitments, [
            'changed_content_hmac_sha256',
            'diff_summary_hmac_sha256',
            'capacity_external_key_hmac_sha256',
            'changed_firmware_hmac_sha256',
        ]);
        foreach ($commitments as $commitment) {
            $this->sha256($commitment);
        }

        $viewports = $this->map($evidence['viewports'] ?? null);
        $this->exactKeys($viewports, ['1280x800', '1440x900']);
        foreach ($viewports as $viewport) {
            $viewport = $this->map($viewport);
            $this->exactKeys($viewport, [
                'status',
                'capture_sha256',
                'network_trace_sha256',
                'evidence_reference',
            ]);
            if (($viewport['status'] ?? null) !== 'passed') {
                $this->refuse();
            }
            $this->sha256($viewport['capture_sha256'] ?? null);
            $this->sha256($viewport['network_trace_sha256'] ?? null);
            $this->reference($viewport['evidence_reference'] ?? null, 'CAPTURE');
        }

        return $evidence;
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    public function validateRestoreEvidence(
        array $evidence,
        ?CarbonImmutable $now = null,
        ?string $expectedRevision = null,
        ?string $expectedRestoredEnvironmentReference = null,
    ): array {
        $this->exactKeys($evidence, [
            'schema_version',
            'evidence_class',
            'environment',
            'fixture',
            'synthetic',
            'status',
            'restore_release_evidence',
            'release_revision',
            'restored_environment_reference_sha256',
            'restore_authority_reference',
            'restore_authority_sha256',
            'checkout_clean_verified',
            'checksum_algorithm',
            'publication',
            ...self::RESTORE_ZERO_CHECKS,
            'checked_at',
            'backup_generation',
            'backup_manifest_sha256',
            'recovery_point_utc',
            'recovery_started_at_utc',
            'verification_started_at_utc',
            'verification_completed_at_utc',
            'rpo_minutes',
            'rto_minutes',
            'maximum_rpo_minutes',
            'maximum_rto_minutes',
            'recovery_objectives_met',
        ]);
        if (($evidence['schema_version'] ?? null) !== 3
            || ($evidence['evidence_class'] ?? null) !== 'isolated-restore-reconciliation-v3'
            || ($evidence['environment'] ?? null) !== 'restore-verification'
            || ($evidence['fixture'] ?? null) !== false
            || ($evidence['synthetic'] ?? null) !== false
            || ($evidence['status'] ?? null) !== 'verified'
            || ($evidence['restore_release_evidence'] ?? null) !== true
            || ($evidence['checkout_clean_verified'] ?? null) !== true
            || ($evidence['checksum_algorithm'] ?? null) !== 'sha256'
            || ($evidence['publication'] ?? null) !== 'collision_safe_exclusive_create') {
            $this->refuse();
        }
        $this->sha256($evidence['release_revision'] ?? null, 40);
        $expectedRevision ??= $this->currentReleaseRevision(base_path());
        if (! is_string($expectedRevision)
            || ! hash_equals($expectedRevision, (string) $evidence['release_revision'])) {
            $this->refuse();
        }
        $this->sha256($evidence['restored_environment_reference_sha256'] ?? null);
        $expectedRestoredEnvironmentReference ??= (string) $this->releaseAuthority()['restored_environment_reference_sha256'];
        if (! hash_equals(
            $expectedRestoredEnvironmentReference,
            (string) $evidence['restored_environment_reference_sha256'],
        )) {
            $this->refuse();
        }
        $this->reference($evidence['restore_authority_reference'] ?? null, 'AUTHORITY');
        $this->sha256($evidence['restore_authority_sha256'] ?? null);
        $this->sha256($evidence['backup_manifest_sha256'] ?? null);
        foreach (self::RESTORE_ZERO_CHECKS as $check) {
            if (($evidence[$check] ?? null) !== 0) {
                $this->refuse();
            }
        }
        $this->reference($evidence['backup_generation'] ?? null, 'BKP');
        $checked = $this->utcFlexible($evidence['checked_at'] ?? null);
        $recoveryPoint = $this->utcFlexible($evidence['recovery_point_utc'] ?? null);
        $recoveryStarted = $this->utcFlexible($evidence['recovery_started_at_utc'] ?? null);
        $verificationStarted = $this->utcFlexible($evidence['verification_started_at_utc'] ?? null);
        $verificationCompleted = $this->utcFlexible($evidence['verification_completed_at_utc'] ?? null);
        if ($recoveryPoint->gt($recoveryStarted)
            || $recoveryStarted->gt($verificationStarted)
            || $verificationStarted->gt($verificationCompleted)
            || $checked->lt($verificationStarted)
            || $checked->gt($verificationCompleted)
            || $verificationCompleted->gt($now ?? CarbonImmutable::now('UTC'))
            || ($evidence['recovery_objectives_met'] ?? null) !== true) {
            $this->refuse();
        }
        foreach (['rpo_minutes', 'rto_minutes', 'maximum_rpo_minutes', 'maximum_rto_minutes'] as $key) {
            if (! is_int($evidence[$key]) && ! is_float($evidence[$key])) {
                $this->refuse();
            }
            if (! is_finite((float) $evidence[$key]) || (float) $evidence[$key] < 0) {
                $this->refuse();
            }
        }
        if ((float) $evidence['maximum_rpo_minutes'] <= 0
            || (float) $evidence['maximum_rto_minutes'] <= 0) {
            $this->refuse();
        }
        if ((float) $evidence['rpo_minutes'] > (float) $evidence['maximum_rpo_minutes']
            || (float) $evidence['rto_minutes'] > (float) $evidence['maximum_rto_minutes']) {
            $this->refuse();
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $browser
     * @param  array{document: array<string, mixed>, sha256: string}  $restore
     */
    public function assertLinked(array $manifest, array $browser, array $restore): void
    {
        $linked = [
            [$manifest['release_revision'] ?? null, $browser['release_revision'] ?? null],
            [$manifest['release_revision'] ?? null, data_get($restore, 'document.release_revision')],
            [data_get($manifest, 'restore.restored_environment_reference_sha256'), $browser['restored_environment_reference_sha256'] ?? null],
            [data_get($manifest, 'restore.restored_environment_reference_sha256'), data_get($restore, 'document.restored_environment_reference_sha256')],
            [data_get($manifest, 'restore.backup_generation_reference'), $browser['backup_generation_reference'] ?? null],
            [data_get($manifest, 'restore.backup_generation_reference'), data_get($restore, 'document.backup_generation')],
            [data_get($manifest, 'restore.evidence_sha256'), $restore['sha256'] ?? null],
            [$manifest['evidence_acl_reference'] ?? null, $browser['evidence_acl_reference'] ?? null],
            [data_get($manifest, 'mysql.changed_snapshot_id'), data_get($browser, 'mysql.changed_snapshot_id')],
            [data_get($manifest, 'mysql.changed_snapshot_uuid'), data_get($browser, 'mysql.changed_snapshot_uuid')],
            [data_get($manifest, 'mysql.capacity_series_id'), data_get($browser, 'mysql.capacity_series_id')],
            [data_get($manifest, 'commitments.changed_content_hmac_sha256'), data_get($browser, 'commitments.changed_content_hmac_sha256')],
            [data_get($manifest, 'commitments.diff_summary_hmac_sha256'), data_get($browser, 'commitments.diff_summary_hmac_sha256')],
            [data_get($manifest, 'commitments.capacity_external_key_hmac_sha256'), data_get($browser, 'commitments.capacity_external_key_hmac_sha256')],
            [data_get($manifest, 'commitments.changed_firmware_hmac_sha256'), data_get($browser, 'commitments.changed_firmware_hmac_sha256')],
        ];
        foreach ($linked as [$expected, $actual]) {
            if ((! is_string($expected) && ! is_int($expected))
                || (! is_string($actual) && ! is_int($actual))
                || ! hash_equals((string) $expected, (string) $actual)) {
                $this->refuse();
            }
        }

        $completed = $this->utc($manifest['observation_completed_at_utc'] ?? null);
        $recoveryPoint = $this->utc(data_get($manifest, 'restore.recovery_point_at_utc'));
        $restoredRecoveryPoint = $this->utcFlexible(data_get($restore, 'document.recovery_point_utc'));
        $restoreVerificationCompleted = $this->utcFlexible(
            data_get($restore, 'document.verification_completed_at_utc'),
        );
        $verified = $this->utc($browser['verified_at_utc'] ?? null);
        if (! $recoveryPoint->equalTo($restoredRecoveryPoint)
            || $verified->lt($restoreVerificationCompleted)
            || $recoveryPoint->lt($completed)) {
            $this->refuse();
        }
    }

    public function commitment(string $value, ?string $key = null): string
    {
        $key ??= $this->hmacKey();
        if (! is_string($key) || strlen($key) < 32) {
            $this->refuse();
        }

        return hash_hmac('sha256', $value, $key);
    }

    public function currentReleaseRevision(string $repositoryRoot): ?string
    {
        try {
            $authority = $this->releaseAuthority();
            $revision = (string) ($authority['release_revision'] ?? '');
        } catch (Throwable) {
            return null;
        }

        return $this->checkoutVerified($repositoryRoot, $revision) ? $revision : null;
    }

    /** @return array<string, mixed> */
    private function loadExternalJson(string $path, string $repositoryRoot): array
    {
        $resolved = $this->externalFile($path, $repositoryRoot);
        $encoded = file_get_contents($resolved);
        if (! is_string($encoded)) {
            $this->refuse();
        }

        return $this->decode($encoded);
    }

    private function externalFile(string $path, string $repositoryRoot): string
    {
        if (! $this->isAbsolutePath($path) || is_link($path)) {
            $this->refuse();
        }
        $resolved = realpath($path);
        $root = realpath($repositoryRoot);
        $evidenceRoot = $this->evidenceDirectory($repositoryRoot);
        if (! is_string($resolved) || ! is_string($root) || ! is_string($evidenceRoot)
            || ! is_file($resolved) || ! is_readable($resolved)
            || $this->within($resolved, $root) || ! $this->within($resolved, $evidenceRoot)) {
            $this->refuse();
        }
        $size = filesize($resolved);
        if (! is_int($size) || $size < 2 || $size > self::MAXIMUM_FILE_BYTES) {
            $this->refuse();
        }
        if (! $this->privatePermissions($resolved, false)) {
            $this->refuse();
        }

        return $resolved;
    }

    private function evidenceDirectory(string $repositoryRoot): ?string
    {
        $configured = getenv('MONITORING_A10_EVIDENCE_DIRECTORY');
        if (! is_string($configured) || ! $this->isAbsolutePath($configured) || is_link($configured)) {
            return null;
        }
        $resolved = realpath($configured);
        $root = realpath($repositoryRoot);
        if (! is_string($resolved) || ! is_string($root) || ! is_dir($resolved)
            || $this->within($resolved, $root)) {
            return null;
        }
        if (! $this->privatePermissions($resolved, true)) {
            return null;
        }

        return $resolved;
    }

    private function restoredObjectStoreMatches(string $repositoryRoot): bool
    {
        if (config('monitoring.storage.snapshots.disk') !== 'monitoring-restore'
            || config('filesystems.disks.monitoring-restore.visibility') !== 'private'
            || config('filesystems.disks.monitoring-restore.serve') !== false
            || config('filesystems.disks.monitoring-restore.throw') !== true
            || ! $this->configuredValueMatches(
                'MONITORING_RESTORE_FILESYSTEM_DRIVER',
                config('filesystems.disks.monitoring-restore.driver'),
            )) {
            return false;
        }

        $driver = (string) config('filesystems.disks.monitoring-restore.driver');
        if ($driver === 'local') {
            $configuredRoot = config('filesystems.disks.monitoring-restore.root');
            $processRoot = getenv('MONITORING_RESTORE_FILESYSTEM_ROOT');
            $resolvedRoot = is_string($configuredRoot) ? realpath($configuredRoot) : false;
            $resolvedProcess = is_string($processRoot) ? realpath($processRoot) : false;
            $repository = realpath($repositoryRoot);

            return is_string($resolvedRoot)
                && is_string($resolvedProcess)
                && is_string($repository)
                && hash_equals($resolvedProcess, $resolvedRoot)
                && ! $this->within($resolvedRoot, $repository)
                && $this->privatePermissions($resolvedRoot, true);
        }

        return in_array($driver, ['s3', 'minio'], true)
            && $this->configuredValueMatches(
                'MONITORING_RESTORE_OBJECT_BUCKET',
                config('filesystems.disks.monitoring-restore.bucket'),
            )
            && $this->configuredValueMatches(
                'MONITORING_RESTORE_OBJECT_ENDPOINT',
                config('filesystems.disks.monitoring-restore.endpoint'),
            )
            && $this->configuredValueMatches(
                'MONITORING_RESTORE_FILESYSTEM_ROOT',
                config('filesystems.disks.monitoring-restore.root'),
            );
    }

    private function configuredValueMatches(string $environmentKey, mixed $configured): bool
    {
        $expected = getenv($environmentKey);

        return is_string($expected)
            && $expected !== ''
            && is_string($configured)
            && hash_equals($expected, $configured);
    }

    private function processValuesMatch(string $left, string $right): bool
    {
        $leftValue = getenv($left);
        $rightValue = getenv($right);

        return is_string($leftValue)
            && $leftValue !== ''
            && is_string($rightValue)
            && hash_equals($leftValue, $rightValue);
    }

    private function privatePermissions(string $path, bool $requireProtected): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $permissions = fileperms($path);

            return $permissions !== false && ($permissions & 0077) === 0;
        }

        $allowed = getenv('MONITORING_A10_WINDOWS_ACL_ALLOWED_IDENTITIES');
        if (! is_string($allowed) || $allowed === '') {
            return false;
        }
        $allowed = array_values(array_unique(array_filter(array_map(
            fn (string $identity): string => strtolower(trim($identity)),
            explode('|', $allowed),
        ))));
        if ($allowed === []) {
            return false;
        }

        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Import-Module (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1') -Force -ErrorAction Stop
$path = [Environment]::GetEnvironmentVariable('OBLIVION_A10_ACL_PATH', 'Process')
$acl = Get-Acl -LiteralPath $path
[ordered]@{
    protected = $acl.AreAccessRulesProtected
    rules = @($acl.Access | ForEach-Object {
        [ordered]@{
            identity = $_.IdentityReference.Value
            type = $_.AccessControlType.ToString()
        }
    })
} | ConvertTo-Json -Compress -Depth 4
POWERSHELL;
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $previousAclPath = getenv('OBLIVION_A10_ACL_PATH');
        putenv('OBLIVION_A10_ACL_PATH='.$path);
        $process = @proc_open(
            ['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $script],
            $descriptor,
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (! is_resource($process)) {
            $previousAclPath === false
                ? putenv('OBLIVION_A10_ACL_PATH')
                : putenv('OBLIVION_A10_ACL_PATH='.$previousAclPath);

            return false;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $previousAclPath === false
            ? putenv('OBLIVION_A10_ACL_PATH')
            : putenv('OBLIVION_A10_ACL_PATH='.$previousAclPath);
        if ($exit !== 0 || ! is_string($stdout) || trim($stdout) === '' || trim((string) $stderr) !== '') {
            return false;
        }
        try {
            $acl = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (! is_array($acl)
            || ($requireProtected && ($acl['protected'] ?? null) !== true)
            || ! is_array($acl['rules'] ?? null)) {
            return false;
        }

        $allowCount = 0;
        foreach ($acl['rules'] as $rule) {
            if (! is_array($rule)
                || ! is_string($rule['identity'] ?? null)
                || ! is_string($rule['type'] ?? null)) {
                return false;
            }
            if (strtolower($rule['type']) !== 'allow') {
                continue;
            }
            $allowCount++;
            if (! in_array(strtolower($rule['identity']), $allowed, true)) {
                return false;
            }
        }

        return $allowCount > 0;
    }

    private function verifyAttestation(array $document, string $context, string $publicKey): void
    {
        $attestation = $this->map($document['attestation'] ?? null);
        $this->exactKeys($attestation, ['key_reference', 'signature_base64']);
        $this->reference($attestation['key_reference'] ?? null, 'ATTEST');
        $expectedReference = 'ATTEST-'.substr(hash('sha256', $publicKey), 0, 32);
        $signature = is_string($attestation['signature_base64'] ?? null)
            ? base64_decode($attestation['signature_base64'], true)
            : false;
        $unsigned = $document;
        unset($unsigned['attestation']);
        if (! hash_equals($expectedReference, (string) $attestation['key_reference'])
            || ! is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached(
                $signature,
                $context."\n".$this->canonicalJson($unsigned),
                $publicKey,
            )) {
            $this->refuse();
        }
    }

    /** @param array<string, mixed>|null $authority */
    private function hmacKey(?array $authority = null): ?string
    {
        $encoded = getenv('MONITORING_A10_EVIDENCE_HMAC_KEY');
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
        $authority ??= $this->releaseAuthority();

        return is_string($decoded)
            && strlen($decoded) >= 32
            && hash_equals((string) ($authority['hmac_key_sha256'] ?? ''), hash('sha256', $decoded))
                ? $decoded
                : null;
    }

    /** @return array<string, mixed> */
    private function releaseAuthority(): array
    {
        $authority = $this->verifiedReleaseAuthority
            ?? (new ConfigurationHistoryReleaseAuthority)->loadInstalled();
        if (! $this->verifiedAuthorityShape($authority)) {
            $this->refuse();
        }

        if ($this->authoritySnapshot === null) {
            $this->authoritySnapshot = $authority;
        } elseif (! hash_equals(
            (string) $this->authoritySnapshot['authority_sha256'],
            (string) $authority['authority_sha256'],
        )) {
            $this->refuse();
        }

        return $authority;
    }

    /** @param array<string, mixed> $authority */
    private function verifiedAuthorityShape(array $authority): bool
    {
        if (! $this->exactKeysMatch($authority, [
            'authority_reference',
            'authority_sha256',
            'browser_public_key',
            'evidence_acl_reference',
            'hmac_key_sha256',
            'production_public_key',
            'release_revision',
            'restored_environment_reference_sha256',
        ])) {
            return false;
        }

        return is_string($authority['production_public_key'])
            && strlen($authority['production_public_key']) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && is_string($authority['browser_public_key'])
            && strlen($authority['browser_public_key']) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && ! hash_equals($authority['production_public_key'], $authority['browser_public_key'])
            && $this->matches($authority['authority_reference'], '/\AAUTHORITY-[a-f0-9]{32}\z/')
            && $this->matches($authority['evidence_acl_reference'], '/\AACL-[a-f0-9]{32}\z/')
            && $this->shaValue($authority['authority_sha256'])
            && $this->shaValue($authority['hmac_key_sha256'])
            && $this->shaValue($authority['release_revision'], 40)
            && $this->shaValue($authority['restored_environment_reference_sha256']);
    }

    private function checkoutVerified(string $repositoryRoot, string $revision): bool
    {
        return $this->verifiedCheckout
            ?? (new LoadSoakReleaseCheckoutVerifier)->verify($repositoryRoot, $revision);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeysMatch(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function shaValue(mixed $value, int $length = 64): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/', $value) === 1;
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    /** @return array<string, mixed> */
    private function decode(string $encoded): array
    {
        try {
            $decoded = (new StrictJsonObjectDecoder)->decode($encoded, 32);
        } catch (Throwable) {
            $this->refuse();
        }

        return $decoded;
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map($this->canonicalValue(...), $value);
            } else {
                ksort($value, SORT_STRING);
                $value = array_map($this->canonicalValue(...), $value);
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonicalValue(...), $value);
    }

    /** @param list<string> $expected */
    private function exactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->refuse();
        }
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->refuse();
        }

        return $value;
    }

    private function sha256(mixed $value, int $length = 64): void
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{'.$length.'}$/D', $value) !== 1) {
            $this->refuse();
        }
    }

    private function reference(mixed $value, string $prefix): void
    {
        if (! is_string($value)
            || preg_match('/^'.preg_quote($prefix, '/').'-[a-f0-9]{32}$/D', $value) !== 1) {
            $this->refuse();
        }
    }

    private function utc(mixed $value): CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            $this->refuse();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, 'UTC');
        } catch (Throwable) {
            $this->refuse();
        }
        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            $this->refuse();
        }

        return $parsed;
    }

    private function utcFlexible(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            $this->refuse();
        }
        try {
            $parsed = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            $this->refuse();
        }
        if (! $parsed instanceof CarbonImmutable || ! str_contains($value, 'T')) {
            $this->refuse();
        }

        return $parsed;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function within(string $candidate, string $root): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }

        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function refuse(): never
    {
        throw new InvalidArgumentException(
            'Configuration history evidence contract is incomplete or unsafe.',
        );
    }
}
