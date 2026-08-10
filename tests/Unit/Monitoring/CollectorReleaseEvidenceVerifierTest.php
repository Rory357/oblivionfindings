<?php

use App\Support\Monitoring\CollectorReleaseAuthorityVerifier;
use App\Support\Monitoring\CollectorReleaseEvidenceVerifier;

/** @return array<string, mixed> */
function collectorReleaseAuthorityRecord(string $publicKey): array
{
    return [
        'schema_version' => 1,
        'evidence_class' => 'monitoring_collector_release_authority_v1',
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'release_revision' => str_repeat('b', 40),
        'environment_reference_sha256' => str_repeat('c', 64),
        'remote_site_reference_sha256' => str_repeat('d', 64),
        'load_balancer_reference_sha256' => str_repeat('e', 64),
        'attestation_public_key_sha256' => hash('sha256', $publicKey),
        'not_before' => '2026-08-10T00:00:00Z',
        'not_after' => '2026-08-10T02:00:00Z',
    ];
}

/** @return array<string, int|string> */
function collectorReleaseAuthority(string $publicKey): array
{
    return (new CollectorReleaseAuthorityVerifier)->verifyRecord(
        json_encode(collectorReleaseAuthorityRecord($publicKey), JSON_THROW_ON_ERROR),
        [
            'is_regular_file' => true,
            'is_symlink' => false,
            'mode' => 0100644,
            'owner_uid' => 0,
            'stable_identity' => true,
        ],
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    );
}

/** @return array<string, mixed> */
function collectorReleaseTransport(
    string $state,
    string $collectorReference,
    string $signingReference,
    string $generationReference,
    string $from,
    string $until,
): array {
    $active = $state === 'active';

    return [
        'schema' => 'oblivion-collector-transport-evidence-v2',
        'state' => 'response_contract_matched',
        'collector_reference_sha256' => $collectorReference,
        'signing_key_reference_sha256' => $signingReference,
        'identity_generation_reference_sha256' => $generationReference,
        'expected_identity_state' => $state,
        'pinned_https_contract' => 'matched',
        'initial_response' => $active ? 'validation_rejected' : 'authentication_denied',
        'replay_attempt' => $active ? 'authentication_denied' : 'not_exercised',
        'samples' => 5,
        'observed_from_utc' => $from,
        'observed_until_utc' => $until,
    ];
}

/**
 * @param  array<string, int|string>  $authority
 * @return array{active: array<string, mixed>, revoked: array<string, mixed>, replacement: array<string, mixed>, evidence: array<string, mixed>}
 */
function collectorReleaseDataset(array $authority): array
{
    $collector = str_repeat('1', 64);
    $active = collectorReleaseTransport(
        'active',
        $collector,
        str_repeat('2', 64),
        str_repeat('3', 64),
        '2026-08-10T00:05:00Z',
        '2026-08-10T00:06:00Z',
    );
    $revoked = collectorReleaseTransport(
        'revoked',
        $collector,
        str_repeat('2', 64),
        str_repeat('3', 64),
        '2026-08-10T00:30:00Z',
        '2026-08-10T00:31:00Z',
    );
    $replacement = collectorReleaseTransport(
        'active',
        $collector,
        str_repeat('4', 64),
        str_repeat('5', 64),
        '2026-08-10T00:35:00Z',
        '2026-08-10T00:36:00Z',
    );
    $rawActive = json_encode($active, JSON_THROW_ON_ERROR);
    $rawRevoked = json_encode($revoked, JSON_THROW_ON_ERROR);
    $rawReplacement = json_encode($replacement, JSON_THROW_ON_ERROR);

    return [
        'active' => $active,
        'revoked' => $revoked,
        'replacement' => $replacement,
        'evidence' => [
            'schema_version' => 1,
            'evidence_class' => 'monitoring_collector_release_evidence_v1',
            'authority_reference' => $authority['authority_reference'],
            'authority_sha256' => $authority['authority_sha256'],
            'environment_reference_sha256' => $authority['environment_reference_sha256'],
            'release_revision' => $authority['release_revision'],
            'remote_site_reference_sha256' => $authority['remote_site_reference_sha256'],
            'load_balancer_reference_sha256' => $authority['load_balancer_reference_sha256'],
            'evidence_reference' => 'COLLECTOR-'.str_repeat('6', 32),
            'active_transport_sha256' => hash('sha256', $rawActive),
            'revoked_transport_sha256' => hash('sha256', $rawRevoked),
            'replacement_transport_sha256' => hash('sha256', $rawReplacement),
            'exercise_started_at' => '2026-08-10T00:04:00Z',
            'exercise_completed_at' => '2026-08-10T00:40:00Z',
            'deployment' => [
                'application_instances' => 2,
                'reviewed_instances' => 2,
                'reviewed_at' => '2026-08-10T00:04:30Z',
                'dedicated_ca_configuration_sha256' => str_repeat('7', 64),
                'proxy_configuration_sha256' => str_repeat('8', 64),
                'shared_redis_configuration_sha256' => str_repeat('9', 64),
                'cross_instance_replay_reference_sha256' => str_repeat('a', 64),
                'load_balancer_routing_reference_sha256' => str_repeat('b', 64),
                'nginx_validation_reference_sha256' => str_repeat('c', 64),
                'same_shared_redis_verified' => true,
                'mtls_header_replacement_verified' => true,
                'legacy_fingerprint_header_disabled' => true,
            ],
            'outage' => [
                'outage_started_at' => '2026-08-10T00:10:00Z',
                'stale_detected_at' => '2026-08-10T00:15:00Z',
                'recovery_completed_at' => '2026-08-10T00:25:00Z',
                'correlation_reference_sha256' => str_repeat('d', 64),
                'pinned_monitor_roster_sha256' => str_repeat('e', 64),
                'affected_monitors' => 3,
                'affected_devices' => 2,
                'post_boundary_observations' => 3,
                'backlog_items_before' => 7,
                'backlog_items_after' => 0,
                'acknowledged_source_sequence' => 41,
                'highest_source_sequence' => 41,
                'gap_count_after' => 0,
                'corrupted_frames_after' => 0,
                'configuration_sequence_after' => 8,
                'unrelated_site_observation_sha256' => str_repeat('f', 64),
                'roster_drift_negative_reference_sha256' => str_repeat('0', 64),
                'exactly_one_root_correlation' => true,
                'downstream_recovered' => true,
            ],
            'credentialed_protocol' => [
                'protocol' => 'snmpv3',
                'observed_at' => '2026-08-10T00:27:00Z',
                'lease_reference_sha256' => str_repeat('1', 64),
                'observation_reference_sha256' => str_repeat('2', 64),
                'plaintext_scan_reference_sha256' => str_repeat('3', 64),
                'lease_accepted' => true,
                'fresh_observation_verified' => true,
                'plaintext_scan_clean' => true,
            ],
            'revocation' => [
                'central_revocation_audit_reference_sha256' => str_repeat('4', 64),
                'revoked_at' => '2026-08-10T00:28:00Z',
                'replacement_issue_audit_reference_sha256' => str_repeat('5', 64),
                'replacement_issued_at' => '2026-08-10T00:32:00Z',
                'replacement_consume_audit_reference_sha256' => str_repeat('6', 64),
                'replacement_consumed_at' => '2026-08-10T00:33:00Z',
                'replacement_token_reuse_denial_reference_sha256' => str_repeat('7', 64),
                'replacement_token_reuse_denied_at' => '2026-08-10T00:37:00Z',
                'general_site_token_denial_reference_sha256' => str_repeat('8', 64),
                'general_site_token_denied_at' => '2026-08-10T00:38:00Z',
                'restored_service_reference_sha256' => str_repeat('9', 64),
                'service_restored_at' => '2026-08-10T00:39:00Z',
                'old_identity_forwarded_and_denied' => true,
                'replacement_heartbeat_current' => true,
                'replacement_zero_backlog' => true,
            ],
        ],
    ];
}

/** @param array<string, int|string> $authority @param array<string, mixed> $dataset */
function verifyCollectorReleaseDataset(
    array $authority,
    array $dataset,
    string $publicKey,
    string $secretKey,
): array {
    $rawActive = json_encode($dataset['active'], JSON_THROW_ON_ERROR);
    $rawRevoked = json_encode($dataset['revoked'], JSON_THROW_ON_ERROR);
    $rawReplacement = json_encode($dataset['replacement'], JSON_THROW_ON_ERROR);
    $rawEvidence = json_encode($dataset['evidence'], JSON_THROW_ON_ERROR);

    return (new CollectorReleaseEvidenceVerifier)->verify(
        $rawActive,
        $rawRevoked,
        $rawReplacement,
        $rawEvidence,
        base64_encode(sodium_crypto_sign_detached($rawEvidence, $secretKey)),
        base64_encode($publicKey),
        $authority,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    );
}

it('validates a protected collector authority and pins it across the release exercise', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = collectorReleaseAuthority($publicKey);

    expect($authority)->toMatchArray([
        'release_revision' => str_repeat('b', 40),
        'remote_site_reference_sha256' => str_repeat('d', 64),
        'load_balancer_reference_sha256' => str_repeat('e', 64),
        'attestation_public_key_sha256' => hash('sha256', $publicKey),
    ])->and((new CollectorReleaseAuthorityVerifier)->identitiesRemainPinned([$authority, $authority]))->toBeTrue();
});

it('rejects unprotected expired duplicate or changed collector authorities', function (Closure $mutate): void {
    $keyPair = sodium_crypto_sign_keypair();
    $record = collectorReleaseAuthorityRecord(sodium_crypto_sign_publickey($keyPair));
    $metadata = [
        'is_regular_file' => true,
        'is_symlink' => false,
        'mode' => 0100644,
        'owner_uid' => 0,
        'stable_identity' => true,
    ];
    $raw = json_encode($record, JSON_THROW_ON_ERROR);
    $mutate($record, $metadata, $raw);
    if ($raw === json_encode(collectorReleaseAuthorityRecord(sodium_crypto_sign_publickey($keyPair)), JSON_THROW_ON_ERROR)) {
        $raw = json_encode($record, JSON_THROW_ON_ERROR);
    }

    expect(fn () => (new CollectorReleaseAuthorityVerifier)->verifyRecord(
        $raw,
        $metadata,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'Collector release authority is invalid.');
})->with([
    'group writable' => function (array &$record, array &$metadata): void {
        $metadata['mode'] = 0100664;
    },
    'expired' => function (array &$record): void {
        $record['not_after'] = '2026-08-10T00:59:59Z';
    },
    'duplicate key' => function (array &$record, array &$metadata, string &$raw): void {
        $raw = preg_replace('/\A\{/', '{"schema_version":1,', json_encode($record, JSON_THROW_ON_ERROR), 1);
    },
]);

it('verifies one signed coherent collector deployment outage revocation and replacement exercise', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = collectorReleaseAuthority($publicKey);
    $report = verifyCollectorReleaseDataset(
        $authority,
        collectorReleaseDataset($authority),
        $publicKey,
        sodium_crypto_sign_secretkey($keyPair),
    );

    expect($report)->toMatchArray([
        'status' => 'verified',
        'transport_samples_verified' => 15,
        'application_instances_verified' => 2,
        'affected_monitors_verified' => 3,
        'credentialed_protocol' => 'snmpv3',
        'acknowledged_source_sequence' => 41,
        'configuration_sequence' => 8,
        'collector_release_evidence' => true,
    ]);
});

it('rejects a locally substituted collector release signer', function (): void {
    $approvedPair = sodium_crypto_sign_keypair();
    $authority = collectorReleaseAuthority(sodium_crypto_sign_publickey($approvedPair));
    $localPair = sodium_crypto_sign_keypair();

    expect(fn () => verifyCollectorReleaseDataset(
        $authority,
        collectorReleaseDataset($authority),
        sodium_crypto_sign_publickey($localPair),
        sodium_crypto_sign_secretkey($localPair),
    ))->toThrow(RuntimeException::class, 'Collector release evidence is invalid.');
});

it('rejects mixed weak or incomplete collector release evidence even when correctly signed', function (Closure $mutate): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = collectorReleaseAuthority($publicKey);
    $dataset = collectorReleaseDataset($authority);
    $mutate($dataset);
    $dataset['evidence']['active_transport_sha256'] = hash('sha256', json_encode($dataset['active'], JSON_THROW_ON_ERROR));
    $dataset['evidence']['revoked_transport_sha256'] = hash('sha256', json_encode($dataset['revoked'], JSON_THROW_ON_ERROR));
    $dataset['evidence']['replacement_transport_sha256'] = hash('sha256', json_encode($dataset['replacement'], JSON_THROW_ON_ERROR));

    expect(fn () => verifyCollectorReleaseDataset(
        $authority,
        $dataset,
        $publicKey,
        sodium_crypto_sign_secretkey($keyPair),
    ))->toThrow(RuntimeException::class, 'Collector release evidence is invalid.');
})->with([
    'revoked result belongs to another collector' => function (array &$dataset): void {
        $dataset['revoked']['collector_reference_sha256'] = str_repeat('f', 64);
    },
    'replacement reuses the revoked signing key' => function (array &$dataset): void {
        $dataset['replacement']['signing_key_reference_sha256'] = $dataset['active']['signing_key_reference_sha256'];
    },
    'transport has only four samples' => function (array &$dataset): void {
        $dataset['active']['samples'] = 4;
    },
    'single application instance' => function (array &$dataset): void {
        $dataset['evidence']['deployment']['application_instances'] = 1;
        $dataset['evidence']['deployment']['reviewed_instances'] = 1;
    },
    'partial affected roster return' => function (array &$dataset): void {
        $dataset['evidence']['outage']['post_boundary_observations'] = 2;
    },
    'no buffered evidence' => function (array &$dataset): void {
        $dataset['evidence']['outage']['backlog_items_before'] = 0;
    },
    'credential plaintext scan is not clean' => function (array &$dataset): void {
        $dataset['evidence']['credentialed_protocol']['plaintext_scan_clean'] = false;
    },
    'old identity was not proven forwarded and denied' => function (array &$dataset): void {
        $dataset['evidence']['revocation']['old_identity_forwarded_and_denied'] = false;
    },
    'replacement was issued before revoked transport completed' => function (array &$dataset): void {
        $dataset['evidence']['revocation']['replacement_issued_at'] = '2026-08-10T00:30:30Z';
    },
    'credential observation predates recovery' => function (array &$dataset): void {
        $dataset['evidence']['credentialed_protocol']['observed_at'] = '2026-08-10T00:24:59Z';
    },
    'outage chronology overlaps active transport' => function (array &$dataset): void {
        $dataset['evidence']['outage']['outage_started_at'] = '2026-08-10T00:05:30Z';
    },
]);

it('rejects duplicate collector evidence keys before signature-backed semantic validation', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = collectorReleaseAuthority($publicKey);
    $dataset = collectorReleaseDataset($authority);
    $rawActive = json_encode($dataset['active'], JSON_THROW_ON_ERROR);
    $rawRevoked = json_encode($dataset['revoked'], JSON_THROW_ON_ERROR);
    $rawReplacement = json_encode($dataset['replacement'], JSON_THROW_ON_ERROR);
    $rawEvidence = json_encode($dataset['evidence'], JSON_THROW_ON_ERROR);
    $duplicate = preg_replace('/\A\{/', '{"schema_version":1,', $rawEvidence, 1);
    $signature = sodium_crypto_sign_detached((string) $duplicate, sodium_crypto_sign_secretkey($keyPair));

    expect(fn () => (new CollectorReleaseEvidenceVerifier)->verify(
        $rawActive,
        $rawRevoked,
        $rawReplacement,
        (string) $duplicate,
        base64_encode($signature),
        base64_encode($publicKey),
        $authority,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'Collector release evidence is invalid.');
});
