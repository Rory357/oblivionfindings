<?php

use App\Domain\Monitoring\Support\ConfigurationHistoryEvidenceContract;
use Carbon\CarbonImmutable;

function configurationHistoryKeyPair(string $name): array
{
    static $pairs = [];

    return $pairs[$name] ??= (function (): array {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => sodium_crypto_sign_publickey($pair),
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
    })();
}

/** @return array<string, mixed> */
function verifiedConfigurationHistoryAuthorityForContractTest(): array
{
    return [
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'authority_sha256' => str_repeat('b', 64),
        'browser_public_key' => configurationHistoryKeyPair('browser')['public'],
        'evidence_acl_reference' => 'ACL-'.str_repeat('4', 32),
        'hmac_key_sha256' => hash('sha256', str_repeat('h', 32)),
        'production_public_key' => configurationHistoryKeyPair('production')['public'],
        'release_revision' => str_repeat('1', 40),
        'restored_environment_reference_sha256' => str_repeat('a', 64),
    ];
}

function canonicalConfigurationHistoryValue(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map(canonicalConfigurationHistoryValue(...), $value);
}

function signConfigurationHistoryDocument(array $document, string $context, string $signer): array
{
    $keys = configurationHistoryKeyPair($signer);
    unset($document['attestation']);
    $canonical = json_encode(
        canonicalConfigurationHistoryValue($document),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    $document['attestation'] = [
        'key_reference' => 'ATTEST-'.substr(hash('sha256', $keys['public']), 0, 32),
        'signature_base64' => base64_encode(sodium_crypto_sign_detached(
            $context."\n".$canonical,
            $keys['secret'],
        )),
    ];

    return $document;
}

function validConfigurationHistoryManifest(): array
{
    return signConfigurationHistoryDocument([
        'schema_version' => 2,
        'evidence_class' => 'production-real-host-configuration-history',
        'source_environment' => 'production',
        'fixture' => false,
        'synthetic' => false,
        'real_host' => true,
        'authoritative' => true,
        'release_revision' => str_repeat('1', 40),
        'target_reference' => 'TARGET-'.str_repeat('2', 32),
        'collection_run_reference' => 'RUN-'.str_repeat('3', 32),
        'evidence_acl_reference' => 'ACL-'.str_repeat('4', 32),
        'observation_started_at_utc' => '2026-08-01T00:00:00Z',
        'observation_completed_at_utc' => '2026-08-02T00:00:00Z',
        'mysql' => [
            'baseline_snapshot_id' => 101,
            'baseline_snapshot_uuid' => '018f47a8-674f-7d2c-9f1c-9d5f82f7d121',
            'changed_snapshot_id' => 102,
            'changed_snapshot_uuid' => '018f47a8-674f-7d2c-9f1c-9d5f82f7d122',
            'capacity_series_id' => 201,
            'capacity_pointer_event_id' => 301,
        ],
        'commitments' => [
            'baseline_content_hmac_sha256' => str_repeat('4', 64),
            'changed_content_hmac_sha256' => str_repeat('5', 64),
            'baseline_configuration_hmac_sha256' => str_repeat('6', 64),
            'changed_configuration_hmac_sha256' => str_repeat('7', 64),
            'baseline_storage_path_hmac_sha256' => str_repeat('8', 64),
            'changed_storage_path_hmac_sha256' => str_repeat('9', 64),
            'diff_summary_hmac_sha256' => str_repeat('a', 64),
            'baseline_firmware_hmac_sha256' => str_repeat('b', 64),
            'changed_firmware_hmac_sha256' => str_repeat('c', 64),
            'capacity_external_key_hmac_sha256' => str_repeat('d', 64),
            'target_identity_hmac_sha256' => str_repeat('e', 64),
        ],
        'restore' => [
            'backup_generation_reference' => 'BKP-'.str_repeat('e', 32),
            'recovery_point_at_utc' => '2026-08-02T01:00:00Z',
            'evidence_sha256' => str_repeat('f', 64),
            'restored_environment_reference_sha256' => str_repeat('3', 64),
        ],
        'review' => [
            'approved_change_reference' => 'CHG-'.str_repeat('1', 32),
            'operator_reference' => 'OP-'.str_repeat('2', 32),
            'reviewer_reference' => 'RV-'.str_repeat('3', 32),
            'decision' => 'approved',
        ],
    ], 'oblivion-a10-production-manifest-v2', 'production');
}

function validConfigurationHistoryBrowserEvidence(): array
{
    return signConfigurationHistoryDocument([
        'schema_version' => 2,
        'evidence_class' => 'restored-production-browser-companion',
        'environment' => 'restore-verification',
        'fixture' => false,
        'synthetic' => false,
        'release_revision' => str_repeat('1', 40),
        'restored_environment_reference_sha256' => str_repeat('3', 64),
        'backup_generation_reference' => 'BKP-'.str_repeat('e', 32),
        'evidence_reference' => 'BROWSER-'.str_repeat('f', 32),
        'evidence_acl_reference' => 'ACL-'.str_repeat('4', 32),
        'verified_at_utc' => '2026-08-02T02:00:00Z',
        'route_contract' => 'security-devices.network-it.configuration-firmware',
        'mysql' => [
            'changed_snapshot_id' => 102,
            'changed_snapshot_uuid' => '018f47a8-674f-7d2c-9f1c-9d5f82f7d122',
            'capacity_series_id' => 201,
        ],
        'commitments' => [
            'changed_content_hmac_sha256' => str_repeat('5', 64),
            'diff_summary_hmac_sha256' => str_repeat('a', 64),
            'capacity_external_key_hmac_sha256' => str_repeat('d', 64),
            'changed_firmware_hmac_sha256' => str_repeat('c', 64),
        ],
        'viewports' => [
            '1280x800' => [
                'status' => 'passed',
                'capture_sha256' => str_repeat('1', 64),
                'network_trace_sha256' => str_repeat('2', 64),
                'evidence_reference' => 'CAPTURE-'.str_repeat('3', 32),
            ],
            '1440x900' => [
                'status' => 'passed',
                'capture_sha256' => str_repeat('4', 64),
                'network_trace_sha256' => str_repeat('5', 64),
                'evidence_reference' => 'CAPTURE-'.str_repeat('6', 32),
            ],
        ],
        'result' => 'passed',
    ], 'oblivion-a10-browser-evidence-v2', 'browser');
}

function validConfigurationHistoryRestoreEvidence(): array
{
    $zeroChecks = [
        'outbox_gap', 'inbox_checkpoint_gap', 'orphan_series', 'timeseries_pointer_gap',
        'snapshot_hash_mismatch', 'topology_pointer_gap', 'collector_sequence_regression',
        'stale_unpublished_delivery', 'published_projection_gap', 'provider_cursor_scope_gap',
        'provider_cursor_stall', 'credential_reference_recovery_gap',
        'credential_lease_recovery_gap', 'redis_unavailable', 'timeseries_unavailable',
        'snapshot_store_unavailable', 'secret_manager_unavailable',
    ];

    return [
        'schema_version' => 3,
        'evidence_class' => 'isolated-restore-reconciliation-v3',
        'environment' => 'restore-verification',
        'fixture' => false,
        'synthetic' => false,
        'status' => 'verified',
        'restore_release_evidence' => true,
        'release_revision' => str_repeat('1', 40),
        'restored_environment_reference_sha256' => str_repeat('3', 64),
        'restore_authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'restore_authority_sha256' => str_repeat('b', 64),
        'checkout_clean_verified' => true,
        'checksum_algorithm' => 'sha256',
        'publication' => 'collision_safe_exclusive_create',
        ...array_fill_keys($zeroChecks, 0),
        'checked_at' => '2026-08-02T01:20:00+00:00',
        'backup_generation' => 'BKP-'.str_repeat('e', 32),
        'backup_manifest_sha256' => str_repeat('c', 64),
        'recovery_point_utc' => '2026-08-02T01:00:00+00:00',
        'recovery_started_at_utc' => '2026-08-02T01:05:00+00:00',
        'verification_started_at_utc' => '2026-08-02T01:10:00+00:00',
        'verification_completed_at_utc' => '2026-08-02T01:30:00+00:00',
        'rpo_minutes' => 5.0,
        'rto_minutes' => 25.0,
        'maximum_rpo_minutes' => 60,
        'maximum_rto_minutes' => 60,
        'recovery_objectives_met' => true,
    ];
}

function validateConfigurationManifest(array $manifest): array
{
    return (new ConfigurationHistoryEvidenceContract)->validateProductionManifest(
        $manifest,
        CarbonImmutable::parse('2026-08-03T00:00:00Z'),
        configurationHistoryKeyPair('production')['public'],
        str_repeat('1', 40),
        'ACL-'.str_repeat('4', 32),
        str_repeat('3', 64),
    );
}

function validateConfigurationBrowser(array $browser): array
{
    return (new ConfigurationHistoryEvidenceContract)->validateBrowserEvidence(
        $browser,
        CarbonImmutable::parse('2026-08-03T00:00:00Z'),
        configurationHistoryKeyPair('browser')['public'],
        str_repeat('1', 40),
        'ACL-'.str_repeat('4', 32),
        str_repeat('3', 64),
    );
}

it('accepts only independently signed production browser and verified restore contracts', function (): void {
    $contract = new ConfigurationHistoryEvidenceContract;
    $manifest = validateConfigurationManifest(validConfigurationHistoryManifest());
    $browser = validateConfigurationBrowser(validConfigurationHistoryBrowserEvidence());
    $restore = $contract->validateRestoreEvidence(
        validConfigurationHistoryRestoreEvidence(),
        CarbonImmutable::parse('2026-08-03T00:00:00Z'),
        str_repeat('1', 40),
        str_repeat('3', 64),
    );

    expect(fn () => $contract->assertLinked($manifest, $browser, [
        'document' => $restore,
        'sha256' => str_repeat('f', 64),
    ]))->not->toThrow(Throwable::class);
});

it('rejects tampered local fixture partial revision and low-entropy production claims', function (Closure $mutate): void {
    $manifest = validConfigurationHistoryManifest();
    $mutate($manifest);

    expect(fn () => validateConfigurationManifest($manifest))->toThrow(
        InvalidArgumentException::class,
        'Configuration history evidence contract is incomplete or unsafe.',
    );
})->with([
    'local environment' => fn (array &$value) => $value['source_environment'] = 'local',
    'fixture' => fn (array &$value) => $value['fixture'] = true,
    'synthetic' => fn (array &$value) => $value['synthetic'] = true,
    'target value smuggled in an extra field' => fn (array &$value) => $value['hostname'] = 'private.invalid',
    'wrong checkout revision' => fn (array &$value) => $value['release_revision'] = str_repeat('0', 40),
    'raw low entropy target hash field' => fn (array &$value) => $value['target_reference_sha256'] = hash('sha256', '10.0.0.1'),
    'changed signed field' => fn (array &$value) => $value['mysql']['changed_snapshot_id'] = 999,
]);

it('rejects a browser companion signed by the production signer or lacking firmware linkage', function (): void {
    $browser = validConfigurationHistoryBrowserEvidence();
    $browser = signConfigurationHistoryDocument(
        $browser,
        'oblivion-a10-browser-evidence-v2',
        'production',
    );

    expect(fn () => validateConfigurationBrowser($browser))->toThrow(InvalidArgumentException::class);

    $browser = validConfigurationHistoryBrowserEvidence();
    unset($browser['commitments']['changed_firmware_hmac_sha256']);
    $browser = signConfigurationHistoryDocument($browser, 'oblivion-a10-browser-evidence-v2', 'browser');
    expect(fn () => validateConfigurationBrowser($browser))->toThrow(InvalidArgumentException::class);
});

it('fails closed when exact restore browser firmware or immutable row linkage changes', function (Closure $mutate): void {
    $contract = new ConfigurationHistoryEvidenceContract;
    $manifest = validateConfigurationManifest(validConfigurationHistoryManifest());
    $browser = validateConfigurationBrowser(validConfigurationHistoryBrowserEvidence());
    $restore = [
        'document' => $contract->validateRestoreEvidence(
            validConfigurationHistoryRestoreEvidence(),
            CarbonImmutable::parse('2026-08-03T00:00:00Z'),
            str_repeat('1', 40),
            str_repeat('3', 64),
        ),
        'sha256' => str_repeat('f', 64),
    ];
    $mutate($browser, $restore);

    expect(fn () => $contract->assertLinked($manifest, $browser, $restore))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'restore artifact' => function (array &$browser, array &$restore): void {
        $restore['sha256'] = str_repeat('0', 64);
    },
    'backup generation' => function (array &$browser, array &$restore): void {
        $restore['document']['backup_generation'] = 'BKP-'.str_repeat('0', 32);
    },
    'restore release revision' => function (array &$browser, array &$restore): void {
        $restore['document']['release_revision'] = str_repeat('0', 40);
    },
    'restored browser environment' => function (array &$browser): void {
        $browser['restored_environment_reference_sha256'] = str_repeat('0', 64);
    },
    'snapshot uuid' => function (array &$browser): void {
        $browser['mysql']['changed_snapshot_uuid'] = '018f47a8-674f-7d2c-9f1c-9d5f82f7d199';
    },
    'firmware commitment' => function (array &$browser): void {
        $browser['commitments']['changed_firmware_hmac_sha256'] = str_repeat('0', 64);
    },
    'browser acceptance before restore verification completed' => function (array &$browser): void {
        $browser['verified_at_utc'] = '2026-08-02T01:20:00Z';
    },
]);

it('rejects a restore artifact with any continuity or recovery-objective failure', function (Closure $mutate): void {
    $restore = validConfigurationHistoryRestoreEvidence();
    $mutate($restore);

    expect(fn () => (new ConfigurationHistoryEvidenceContract)->validateRestoreEvidence(
        $restore,
        CarbonImmutable::parse('2026-08-03T00:00:00Z'),
        str_repeat('1', 40),
        str_repeat('3', 64),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'continuity gap' => fn (array &$value) => $value['timeseries_pointer_gap'] = 1,
    'failed objective' => fn (array &$value) => $value['recovery_objectives_met'] = false,
    'failed release classification' => fn (array &$value) => $value['restore_release_evidence'] = false,
    'dirty checkout classification' => fn (array &$value) => $value['checkout_clean_verified'] = false,
    'wrong release revision' => fn (array &$value) => $value['release_revision'] = str_repeat('0', 40),
    'wrong restored environment' => fn (array &$value) => $value['restored_environment_reference_sha256'] = str_repeat('0', 64),
    'exceeded rto' => fn (array &$value) => $value['rto_minutes'] = 61.0,
    'unverified generation' => fn (array &$value) => $value['backup_generation'] = 'ad-hoc-copy',
]);

it('refuses external evidence stored inside the repository', function (): void {
    $contract = new ConfigurationHistoryEvidenceContract(
        verifiedConfigurationHistoryAuthorityForContractTest(),
        true,
    );

    expect(fn () => $contract->loadProductionManifest(__FILE__, dirname(__DIR__, 3)))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects duplicate object keys at every depth before validating A10 evidence', function (string $encoded): void {
    $decode = new ReflectionMethod(ConfigurationHistoryEvidenceContract::class, 'decode');

    expect(fn () => $decode->invoke(new ConfigurationHistoryEvidenceContract, $encoded))
        ->toThrow(
            InvalidArgumentException::class,
            'Configuration history evidence contract is incomplete or unsafe.',
        );
})->with([
    'duplicate root classification' => '{"schema_version":2,"schema_version":1}',
    'escaped duplicate nested commitment' => '{"commitments":{"firmware":"first","firm\\u0077are":"second"}}',
]);
