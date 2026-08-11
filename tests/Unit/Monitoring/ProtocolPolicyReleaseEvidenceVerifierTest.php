<?php

use App\Support\Monitoring\ProtocolPolicyReleaseAuthorityVerifier;
use App\Support\Monitoring\ProtocolPolicyReleaseEvidenceVerifier;
use App\Support\Monitoring\S10ReleaseAuthorityVerifier;

/** @return array<string, mixed> */
function protocolPolicyReleaseAuthorityRecord(string $publicKey): array
{
    return [
        'schema_version' => 1,
        'evidence_class' => 'monitoring_protocol_policy_release_authority_v1',
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'release_revision' => str_repeat('b', 40),
        'environment_reference_sha256' => str_repeat('c', 64),
        'attestation_public_key_sha256' => hash('sha256', $publicKey),
        'not_before' => '2026-08-10T00:00:00Z',
        'not_after' => '2026-08-10T02:00:00Z',
    ];
}

/** @return array<string, int|string> */
function protocolPolicyReleaseAuthority(string $publicKey): array
{
    return (new ProtocolPolicyReleaseAuthorityVerifier)->verifyRecord(
        json_encode(protocolPolicyReleaseAuthorityRecord($publicKey), JSON_THROW_ON_ERROR),
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

/** @return list<string> */
function protocolPolicyReleaseProtocols(): array
{
    return [
        'dns', 'flow', 'http', 'https', 'icmp', 'provider_milesight', 'provider_unifi',
        'snmp_traps', 'snmp_v3', 'ssh_read_only', 'syslog', 'tcp', 'tls', 'winrm_read_only',
    ];
}

/** @return list<string> */
function protocolPolicyReleasePolicies(): array
{
    return [
        'baselines', 'confirmation', 'coverage', 'dependencies', 'hysteresis',
        'maintenance', 'profiles', 'rollups', 'stale_unknown',
    ];
}

/** @return list<string> */
function protocolPolicyReleaseDrills(): array
{
    return ['baselines', 'confirmation', 'dependencies', 'hysteresis', 'maintenance', 'stale_unknown'];
}

/** @return array<string, bool|string|null> */
function protocolPolicyS10Authority(): array
{
    $record = [
        'schema_version' => 1,
        'evidence_class' => 'security_devices_s10_release_authority_v1',
        'authority_reference' => 'AUTHORITY-'.str_repeat('e', 32),
        'release_revision' => str_repeat('b', 40),
        'environment_reference_sha256' => str_repeat('c', 64),
        'runtime_environment_sha256' => str_repeat('a', 64),
        'not_before' => '2026-08-10T00:00:00Z',
        'not_after' => '2026-08-10T02:00:00Z',
    ];

    return (new S10ReleaseAuthorityVerifier)->verifyRecord(
        json_encode($record, JSON_THROW_ON_ERROR),
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
function protocolPolicyS10Artifact(): array
{
    $authority = protocolPolicyS10Authority();

    return [
        'schema_version' => 1,
        'artifact_id' => str_repeat('d', 32),
        'evidence_class' => 'security_devices_s10_release_evidence_v1',
        'created_at' => '2026-08-10T00:20:01.123456Z',
        'authority_reference' => $authority['authority_reference'],
        'authority_sha256' => $authority['authority_sha256'],
        'release_revision' => $authority['release_revision'],
        'environment_reference_sha256' => $authority['environment_reference_sha256'],
        'runtime_environment_sha256' => $authority['runtime_environment_sha256'],
        'provider_api_contracts' => ['unifi', 'milesight'],
        'queclink_transport' => 'native_tcp',
        'protocol_policy_evidence' => [
            'sha256' => str_repeat('1', 64),
            'samples' => 15,
            'observation_seconds' => 840,
            'window_minutes' => 60,
            'started_at' => '2026-08-10T00:05:00Z',
            'completed_at' => '2026-08-10T00:19:00Z',
        ],
        'queclink_native_listener_evidence' => [
            'sha256' => str_repeat('2', 64),
            'samples' => 5,
            'observation_seconds' => 240,
            'max_frame_age_seconds' => 900,
            'canonical_paired_trackers' => 2,
            'fresh_trackers_observed' => 2,
            'started_at' => '2026-08-10T00:15:00Z',
            'completed_at' => '2026-08-10T00:19:00Z',
        ],
        'release_provenance_verified' => true,
        's10_release_evidence' => true,
        'verification_artifact_contains_targets_credentials_or_payloads' => false,
        'output_storage_semantics' => 'collision_safe_exclusive_create',
        'worm_receipt_verified' => false,
    ];
}

/** @param array<string, int|string> $authority @return array<string, mixed> */
function protocolPolicyReleaseEvidence(array $authority, string $rawS10): array
{
    $protocolRows = [];
    foreach (protocolPolicyReleaseProtocols() as $index => $name) {
        $protocolRows[] = [
            'name' => $name,
            'state' => 'verified',
            'observed_at' => '2026-08-10T00:'.str_pad((string) (5 + ($index % 10)), 2, '0', STR_PAD_LEFT).':00Z',
            'target_side_reference_sha256' => hash('sha256', 'target-'.$name),
            'runtime_reference_sha256' => hash('sha256', 'runtime-'.$name),
        ];
    }
    $policyRows = [];
    foreach (protocolPolicyReleasePolicies() as $name) {
        $policyRows[] = [
            'name' => $name,
            'verified_at' => '2026-08-10T00:15:00Z',
            'evidence_reference_sha256' => hash('sha256', 'policy-'.$name),
        ];
    }
    $drills = [];
    foreach (protocolPolicyReleaseDrills() as $index => $name) {
        $minute = 1 + ($index % 2);
        $drills[] = [
            'name' => $name,
            'started_at' => '2026-08-10T00:0'.$minute.':00Z',
            'during_at' => '2026-08-10T00:0'.($minute + 1).':00Z',
            'recovered_at' => '2026-08-10T00:0'.($minute + 2).':00Z',
            'before_reference_sha256' => hash('sha256', 'before-'.$name),
            'during_reference_sha256' => hash('sha256', 'during-'.$name),
            'after_reference_sha256' => hash('sha256', 'after-'.$name),
            'notification_storm_count' => 0,
            'ticket_storm_count' => 0,
        ];
    }

    return [
        'schema_version' => 1,
        'evidence_class' => 'monitoring_protocol_policy_release_evidence_v1',
        'authority_reference' => $authority['authority_reference'],
        'authority_sha256' => $authority['authority_sha256'],
        'environment_reference_sha256' => $authority['environment_reference_sha256'],
        'release_revision' => $authority['release_revision'],
        'evidence_reference' => 'PROTOCOL-'.str_repeat('3', 32),
        's10_release_evidence_sha256' => hash('sha256', $rawS10),
        'exercise_started_at' => '2026-08-10T00:00:00Z',
        'exercise_completed_at' => '2026-08-10T00:21:00Z',
        'protocol_attestations' => $protocolRows,
        'policy_attestations' => $policyRows,
        'transition_drills' => $drills,
        'supervision_reference_sha256' => str_repeat('4', 64),
        'provider_audit_reference_sha256' => str_repeat('5', 64),
        'target_side_logs_reference_sha256' => str_repeat('6', 64),
        'operator_signoff_reference_sha256' => str_repeat('7', 64),
        'no_targets_credentials_payloads_retained' => true,
    ];
}

/** @param array<string, int|string> $authority @param array<string, mixed> $s10 @param array<string, mixed> $evidence */
function verifyProtocolPolicyRelease(
    array $authority,
    array $s10,
    array $evidence,
    string $publicKey,
    string $secretKey,
): array {
    $rawS10 = json_encode($s10, JSON_THROW_ON_ERROR);
    $rawEvidence = json_encode($evidence, JSON_THROW_ON_ERROR);

    return (new ProtocolPolicyReleaseEvidenceVerifier)->verify(
        $rawS10,
        $rawEvidence,
        base64_encode(sodium_crypto_sign_detached($rawEvidence, $secretKey)),
        base64_encode($publicKey),
        $authority,
        protocolPolicyS10Authority(),
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    );
}

it('validates one protected independently signed protocol-policy authority', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = protocolPolicyReleaseAuthority($publicKey);

    expect($authority)->toMatchArray([
        'release_revision' => str_repeat('b', 40),
        'environment_reference_sha256' => str_repeat('c', 64),
        'attestation_public_key_sha256' => hash('sha256', $publicKey),
    ])->and((new ProtocolPolicyReleaseAuthorityVerifier)->identitiesRemainPinned([$authority, $authority]))->toBeTrue()
        ->and((new ProtocolPolicyReleaseAuthorityVerifier)->identitiesRemainPinned([$authority, $authority, $authority]))->toBeTrue()
        ->and((new ProtocolPolicyReleaseAuthorityVerifier)->identitiesRemainPinned([$authority]))->toBeFalse();
});

it('rejects malformed expired duplicate or unprotected protocol-policy authorities', function (Closure $mutate): void {
    $keyPair = sodium_crypto_sign_keypair();
    $record = protocolPolicyReleaseAuthorityRecord(sodium_crypto_sign_publickey($keyPair));
    $metadata = [
        'is_regular_file' => true,
        'is_symlink' => false,
        'mode' => 0100644,
        'owner_uid' => 0,
        'stable_identity' => true,
    ];
    $raw = '';
    $mutate($record, $metadata, $raw);
    if ($raw === '') {
        $raw = json_encode($record, JSON_THROW_ON_ERROR);
    }

    expect(fn () => (new ProtocolPolicyReleaseAuthorityVerifier)->verifyRecord(
        $raw,
        $metadata,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'Protocol-policy release authority is invalid.');
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

it('verifies the complete independently signed A07 and A08 release companion', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = protocolPolicyReleaseAuthority($publicKey);
    $s10 = protocolPolicyS10Artifact();
    $evidence = protocolPolicyReleaseEvidence($authority, json_encode($s10, JSON_THROW_ON_ERROR));
    $report = verifyProtocolPolicyRelease(
        $authority,
        $s10,
        $evidence,
        $publicKey,
        sodium_crypto_sign_secretkey($keyPair),
    );

    expect($report)->toMatchArray([
        'status' => 'verified',
        'signed_review_sha256' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
        'detached_signature_sha256' => hash('sha256', base64_encode(sodium_crypto_sign_detached(
            json_encode($evidence, JSON_THROW_ON_ERROR),
            sodium_crypto_sign_secretkey($keyPair),
        ))),
        'protocols_verified' => 14,
        'policies_verified' => 9,
        'transition_drills_verified' => 6,
        'sustained_samples_verified' => 15,
        'a07_release_evidence' => true,
        'a08_release_evidence' => true,
    ]);
});

it('binds the retained companion to the exact re-signed review and detached signature', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $authority = protocolPolicyReleaseAuthority($publicKey);
    $s10 = protocolPolicyS10Artifact();
    $rawS10 = json_encode($s10, JSON_THROW_ON_ERROR);
    $review = protocolPolicyReleaseEvidence($authority, $rawS10);
    $substitutedReview = $review;
    $substitutedReview['operator_signoff_reference_sha256'] = hash('sha256', 'substituted-operator-signoff');
    $verifier = new ProtocolPolicyReleaseEvidenceVerifier;
    $verify = static function (array $evidence) use ($verifier, $rawS10, $publicKey, $secretKey, $authority): array {
        $rawEvidence = json_encode($evidence, JSON_THROW_ON_ERROR);
        $signature = base64_encode(sodium_crypto_sign_detached($rawEvidence, $secretKey));

        return $verifier->verify(
            $rawS10,
            $rawEvidence,
            $signature,
            base64_encode($publicKey),
            $authority,
            protocolPolicyS10Authority(),
            new DateTimeImmutable('2026-08-10T01:00:00Z'),
        );
    };

    $original = $verify($review);
    $substituted = $verify($substitutedReview);

    expect($original['evidence_reference'])->toBe($substituted['evidence_reference'])
        ->and($original['signed_review_sha256'])->not->toBe($substituted['signed_review_sha256'])
        ->and($original['detached_signature_sha256'])->not->toBe($substituted['detached_signature_sha256']);
});

it('rejects a locally substituted protocol-policy signer', function (): void {
    $approvedPair = sodium_crypto_sign_keypair();
    $authority = protocolPolicyReleaseAuthority(sodium_crypto_sign_publickey($approvedPair));
    $localPair = sodium_crypto_sign_keypair();
    $s10 = protocolPolicyS10Artifact();
    $evidence = protocolPolicyReleaseEvidence($authority, json_encode($s10, JSON_THROW_ON_ERROR));

    expect(fn () => verifyProtocolPolicyRelease(
        $authority,
        $s10,
        $evidence,
        sodium_crypto_sign_publickey($localPair),
        sodium_crypto_sign_secretkey($localPair),
    ))->toThrow(RuntimeException::class, 'Protocol-policy release evidence is invalid.');
});

it('rejects incomplete mixed or weak protocol-policy release companions', function (Closure $mutate): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = protocolPolicyReleaseAuthority($publicKey);
    $s10 = protocolPolicyS10Artifact();
    $evidence = protocolPolicyReleaseEvidence($authority, json_encode($s10, JSON_THROW_ON_ERROR));
    $mutate($s10, $evidence);
    $evidence['s10_release_evidence_sha256'] = hash('sha256', json_encode($s10, JSON_THROW_ON_ERROR));

    expect(fn () => verifyProtocolPolicyRelease(
        $authority,
        $s10,
        $evidence,
        $publicKey,
        sodium_crypto_sign_secretkey($keyPair),
    ))->toThrow(RuntimeException::class, 'Protocol-policy release evidence is invalid.');
})->with([
    'missing protocol' => function (array &$s10, array &$evidence): void {
        array_pop($evidence['protocol_attestations']);
    },
    'duplicate protocol' => function (array &$s10, array &$evidence): void {
        $evidence['protocol_attestations'][1] = $evidence['protocol_attestations'][0];
    },
    'missing policy' => function (array &$s10, array &$evidence): void {
        array_shift($evidence['policy_attestations']);
    },
    'missing transition drill' => function (array &$s10, array &$evidence): void {
        array_pop($evidence['transition_drills']);
    },
    'notification storm' => function (array &$s10, array &$evidence): void {
        $evidence['transition_drills'][0]['notification_storm_count'] = 1;
    },
    'short sustained child' => function (array &$s10): void {
        $s10['protocol_policy_evidence']['samples'] = 5;
        $s10['protocol_policy_evidence']['observation_seconds'] = 240;
    },
    'different environment' => function (array &$s10): void {
        $s10['environment_reference_sha256'] = str_repeat('8', 64);
    },
    'S10 artifact authority is substituted' => function (array &$s10): void {
        $s10['authority_reference'] = 'AUTHORITY-'.str_repeat('0', 32);
    },
    'target evidence outside the accepted window' => function (array &$s10, array &$evidence): void {
        $evidence['protocol_attestations'][0]['observed_at'] = '2026-08-09T23:04:59Z';
    },
    'artifact created before sustained completion' => function (array &$s10): void {
        $s10['created_at'] = '2026-08-10T00:18:59.000000Z';
    },
    'incomplete S10 Queclink roster' => function (array &$s10): void {
        $s10['queclink_native_listener_evidence']['fresh_trackers_observed'] = 1;
    },
    'transition reuses its before reference during the changed state' => function (array &$s10, array &$evidence): void {
        $evidence['transition_drills'][0]['during_reference_sha256'] = $evidence['transition_drills'][0]['before_reference_sha256'];
    },
    'two transitions reuse one evidence reference' => function (array &$s10, array &$evidence): void {
        $evidence['transition_drills'][1]['after_reference_sha256'] = $evidence['transition_drills'][0]['after_reference_sha256'];
    },
    'top level evidence classes reuse one reference' => function (array &$s10, array &$evidence): void {
        $evidence['operator_signoff_reference_sha256'] = $evidence['supervision_reference_sha256'];
    },
    'protocol runtime evidence is relabelled as target side proof' => function (array &$s10, array &$evidence): void {
        $evidence['protocol_attestations'][0]['target_side_reference_sha256'] =
            $evidence['protocol_attestations'][0]['runtime_reference_sha256'];
    },
    'transition begins and enters its changed state at the same instant' => function (array &$s10, array &$evidence): void {
        $evidence['transition_drills'][0]['during_at'] = $evidence['transition_drills'][0]['started_at'];
    },
    'transition changed state and recovery collapse to one instant' => function (array &$s10, array &$evidence): void {
        $evidence['transition_drills'][0]['recovered_at'] = $evidence['transition_drills'][0]['during_at'];
    },
]);

it('rejects duplicate protocol-policy manifest keys before signature-backed validation', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = protocolPolicyReleaseAuthority($publicKey);
    $s10 = protocolPolicyS10Artifact();
    $rawS10 = json_encode($s10, JSON_THROW_ON_ERROR);
    $evidence = protocolPolicyReleaseEvidence($authority, $rawS10);
    $rawEvidence = json_encode($evidence, JSON_THROW_ON_ERROR);
    $duplicate = preg_replace('/\A\{/', '{"schema_version":1,', $rawEvidence, 1);
    $signature = sodium_crypto_sign_detached((string) $duplicate, sodium_crypto_sign_secretkey($keyPair));

    expect(fn () => (new ProtocolPolicyReleaseEvidenceVerifier)->verify(
        $rawS10,
        (string) $duplicate,
        base64_encode($signature),
        base64_encode($publicKey),
        $authority,
        protocolPolicyS10Authority(),
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'Protocol-policy release evidence is invalid.');
});
