<?php

use App\Support\Monitoring\CentralRuntimeReleaseAuthorityVerifier;
use App\Support\Monitoring\ExternalWatchdogEvidenceVerifier;

function watchdogAuthority(string $publicKey): array
{
    $record = [
        'schema_version' => 1,
        'evidence_class' => 'monitoring_central_runtime_release_authority_v1',
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'release_revision' => str_repeat('b', 40),
        'environment_reference_sha256' => str_repeat('c', 64),
        'application_path_sha256' => str_repeat('d', 64),
        'health_url_sha256' => str_repeat('e', 64),
        'supervisor_configuration_sha256' => str_repeat('f', 64),
        'watchdog_attestation_public_key_sha256' => hash('sha256', $publicKey),
        'not_before' => '2026-08-10T00:00:00Z',
        'not_after' => '2026-08-10T02:00:00Z',
    ];

    return (new CentralRuntimeReleaseAuthorityVerifier)->verifyRecord(
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

function watchdogCentralEvidence(array $authority, array $overrides = []): array
{
    return [
        'state' => 'verified',
        'evidence_class' => 'monitoring_central_runtime_release_evidence_v1',
        'authority_reference' => $authority['authority_reference'],
        'authority_sha256' => $authority['authority_sha256'],
        'environment_reference_sha256' => $authority['environment_reference_sha256'],
        'watchdog_attestation_public_key_sha256' => $authority['watchdog_attestation_public_key_sha256'],
        'release_revision' => $authority['release_revision'],
        'checkout_clean_verified' => true,
        'protected_authority_verified' => true,
        'samples' => 15,
        'observation_seconds' => 840,
        'verified_sites' => 3,
        'supervised_programs' => 11,
        'started_at' => '2026-08-10T00:00:00Z',
        'completed_at' => '2026-08-10T00:14:00Z',
        ...$overrides,
    ];
}

function watchdogEvents(): array
{
    return [
        [
            'kind' => 'scheduler_outage',
            'outage_started_at' => '2026-08-10T00:16:00Z',
            'alarm_raised_at' => '2026-08-10T00:20:00Z',
            'recovery_started_at' => '2026-08-10T00:21:00Z',
            'delivery_restored_at' => '2026-08-10T00:22:00Z',
            'alarm_recovered_at' => '2026-08-10T00:23:00Z',
            'observation_reference_sha256' => str_repeat('1', 64),
        ],
        [
            'kind' => 'worker_outage',
            'outage_started_at' => '2026-08-10T00:25:00Z',
            'alarm_raised_at' => '2026-08-10T00:29:00Z',
            'recovery_started_at' => '2026-08-10T00:30:00Z',
            'delivery_restored_at' => '2026-08-10T00:31:00Z',
            'alarm_recovered_at' => '2026-08-10T00:32:00Z',
            'observation_reference_sha256' => str_repeat('2', 64),
        ],
        [
            'kind' => 'listener_outage',
            'outage_started_at' => '2026-08-10T00:35:00Z',
            'alarm_raised_at' => '2026-08-10T00:39:00Z',
            'recovery_started_at' => '2026-08-10T00:40:00Z',
            'delivery_restored_at' => '2026-08-10T00:41:00Z',
            'alarm_recovered_at' => '2026-08-10T00:42:00Z',
            'observation_reference_sha256' => str_repeat('3', 64),
        ],
        [
            'kind' => 'regional_outage',
            'outage_started_at' => '2026-08-10T00:45:00Z',
            'alarm_raised_at' => '2026-08-10T00:49:00Z',
            'recovery_started_at' => '2026-08-10T00:50:00Z',
            'delivery_restored_at' => '2026-08-10T00:54:00Z',
            'alarm_recovered_at' => '2026-08-10T00:55:00Z',
            'observation_reference_sha256' => str_repeat('4', 64),
        ],
    ];
}

function watchdogProviderReceipt(): string
{
    return '{"receipt_reference":"PROVIDER-00000000000000000000000000000000"}';
}

function watchdogEvidence(array $authority, string $centralRaw, array $overrides = []): array
{
    return [
        'schema_version' => 1,
        'evidence_class' => 'monitoring_external_watchdog_release_evidence_v1',
        'authority_reference' => $authority['authority_reference'],
        'authority_sha256' => $authority['authority_sha256'],
        'environment_reference_sha256' => $authority['environment_reference_sha256'],
        'release_revision' => $authority['release_revision'],
        'central_runtime_evidence_sha256' => hash('sha256', $centralRaw),
        'watchdog_evidence_reference' => 'WATCHDOG-'.str_repeat('5', 32),
        'provider_receipt_sha256' => hash('sha256', watchdogProviderReceipt()),
        'exercise_started_at' => '2026-08-10T00:15:00Z',
        'exercise_completed_at' => '2026-08-10T00:56:00Z',
        'events' => watchdogEvents(),
        ...$overrides,
    ];
}

it('verifies one independently signed complete watchdog exercise against central runtime evidence', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = watchdogAuthority($publicKey);
    $centralRaw = json_encode(watchdogCentralEvidence($authority), JSON_THROW_ON_ERROR);
    $evidenceRaw = json_encode(watchdogEvidence($authority, $centralRaw), JSON_THROW_ON_ERROR);
    $signature = sodium_crypto_sign_detached($evidenceRaw, sodium_crypto_sign_secretkey($keyPair));

    $report = (new ExternalWatchdogEvidenceVerifier)->verify(
        $evidenceRaw,
        base64_encode($signature),
        base64_encode($publicKey),
        $centralRaw,
        watchdogProviderReceipt(),
        $authority,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    );

    expect($report)->toMatchArray([
        'status' => 'verified',
        'events_verified' => 4,
        'release_revision' => str_repeat('b', 40),
        'central_runtime_evidence_sha256' => hash('sha256', $centralRaw),
        'signed_watchdog_evidence_sha256' => hash('sha256', $evidenceRaw),
        'detached_signature_sha256' => hash('sha256', $signature),
        'external_watchdog_release_evidence' => true,
    ])->and((new CentralRuntimeReleaseAuthorityVerifier)->identitiesRemainPinned([
        $authority,
        $authority,
        $authority,
    ]))->toBeTrue()
        ->and((new CentralRuntimeReleaseAuthorityVerifier)->identitiesRemainPinned([$authority]))->toBeFalse();
});

it('binds publication to the exact signed watchdog review and detached signature bytes', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $authority = watchdogAuthority($publicKey);
    $centralRaw = json_encode(watchdogCentralEvidence($authority), JSON_THROW_ON_ERROR);
    $firstEvidence = watchdogEvidence($authority, $centralRaw);
    $secondEvidence = $firstEvidence;
    $secondEvidence['events'][0]['observation_reference_sha256'] = str_repeat('6', 64);
    $firstRaw = json_encode($firstEvidence, JSON_THROW_ON_ERROR);
    $secondRaw = json_encode($secondEvidence, JSON_THROW_ON_ERROR);
    $firstSignature = sodium_crypto_sign_detached($firstRaw, $secretKey);
    $secondSignature = sodium_crypto_sign_detached($secondRaw, $secretKey);
    $verifier = new ExternalWatchdogEvidenceVerifier;

    $firstReport = $verifier->verify(
        $firstRaw,
        base64_encode($firstSignature),
        base64_encode($publicKey),
        $centralRaw,
        watchdogProviderReceipt(),
        $authority,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    );
    $secondReport = $verifier->verify(
        $secondRaw,
        base64_encode($secondSignature),
        base64_encode($publicKey),
        $centralRaw,
        watchdogProviderReceipt(),
        $authority,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    );

    expect($secondEvidence['watchdog_evidence_reference'])->toBe($firstEvidence['watchdog_evidence_reference'])
        ->and($secondReport['signed_watchdog_evidence_sha256'])->not->toBe($firstReport['signed_watchdog_evidence_sha256'])
        ->and($secondReport['detached_signature_sha256'])->not->toBe($firstReport['detached_signature_sha256']);
});

it('rejects a locally substituted watchdog signer even when its signature is valid', function (): void {
    $approvedPair = sodium_crypto_sign_keypair();
    $approvedPublicKey = sodium_crypto_sign_publickey($approvedPair);
    $localPair = sodium_crypto_sign_keypair();
    $localPublicKey = sodium_crypto_sign_publickey($localPair);
    $authority = watchdogAuthority($approvedPublicKey);
    $centralRaw = json_encode(watchdogCentralEvidence($authority), JSON_THROW_ON_ERROR);
    $evidenceRaw = json_encode(watchdogEvidence($authority, $centralRaw), JSON_THROW_ON_ERROR);
    $signature = sodium_crypto_sign_detached($evidenceRaw, sodium_crypto_sign_secretkey($localPair));

    expect(fn () => (new ExternalWatchdogEvidenceVerifier)->verify(
        $evidenceRaw,
        base64_encode($signature),
        base64_encode($localPublicKey),
        $centralRaw,
        watchdogProviderReceipt(),
        $authority,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'External watchdog release evidence is invalid.');
});

it('rejects incomplete misordered or weak watchdog evidence even when correctly signed', function (Closure $mutate): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = watchdogAuthority($publicKey);
    $central = watchdogCentralEvidence($authority);
    $evidence = [];
    $providerReceipt = watchdogProviderReceipt();
    $mutate($central, $evidence, $authority, $providerReceipt);
    $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
    $evidence = $evidence === [] ? watchdogEvidence($authority, $centralRaw) : $evidence;
    if (! isset($evidence['central_runtime_evidence_sha256'])) {
        $evidence['central_runtime_evidence_sha256'] = hash('sha256', $centralRaw);
    }
    $evidenceRaw = json_encode($evidence, JSON_THROW_ON_ERROR);
    $signature = sodium_crypto_sign_detached($evidenceRaw, sodium_crypto_sign_secretkey($keyPair));

    expect(fn () => (new ExternalWatchdogEvidenceVerifier)->verify(
        $evidenceRaw,
        base64_encode($signature),
        base64_encode($publicKey),
        $centralRaw,
        $providerReceipt,
        $authority,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'External watchdog release evidence is invalid.');
})->with([
    'only three outage classes' => function (array &$central, array &$evidence, array $authority): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $events = watchdogEvents();
        array_pop($events);
        $evidence = watchdogEvidence($authority, $centralRaw, ['events' => $events]);
    },
    'alarm outside five minute policy plus grace' => function (array &$central, array &$evidence, array $authority): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $events = watchdogEvents();
        $events[0]['alarm_raised_at'] = '2026-08-10T00:22:01Z';
        $evidence = watchdogEvidence($authority, $centralRaw, ['events' => $events]);
    },
    'overlapping exercises' => function (array &$central, array &$evidence, array $authority): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $events = watchdogEvents();
        $events[1]['outage_started_at'] = '2026-08-10T00:22:30Z';
        $evidence = watchdogEvidence($authority, $centralRaw, ['events' => $events]);
    },
    'short central observation' => function (array &$central): void {
        $central['samples'] = 5;
        $central['observation_seconds'] = 240;
        $central['completed_at'] = '2026-08-10T00:04:00Z';
    },
    'dirty central checkout' => function (array &$central): void {
        $central['checkout_clean_verified'] = false;
    },
    'wrong authority linkage' => function (array &$central, array &$evidence, array $authority): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $evidence = watchdogEvidence($authority, $centralRaw, [
            'authority_reference' => 'AUTHORITY-'.str_repeat('0', 32),
        ]);
    },
    'two outage kinds reuse one observation commitment' => function (array &$central, array &$evidence, array $authority): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $events = watchdogEvents();
        $events[1]['observation_reference_sha256'] = $events[0]['observation_reference_sha256'];
        $evidence = watchdogEvidence($authority, $centralRaw, ['events' => $events]);
    },
    'provider receipt reuses the captured central runtime evidence commitment' => function (array &$central, array &$evidence, array $authority, string &$providerReceipt): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $providerReceipt = $centralRaw;
        $evidence = watchdogEvidence($authority, $centralRaw, [
            'provider_receipt_sha256' => hash('sha256', $centralRaw),
        ]);
    },
    'provider receipt reuses an outage observation commitment' => function (array &$central, array &$evidence, array $authority, string &$providerReceipt): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $events = watchdogEvents();
        $providerReceipt = 'shared-provider-and-outage-proof';
        $events[0]['observation_reference_sha256'] = hash('sha256', $providerReceipt);
        $evidence = watchdogEvidence($authority, $centralRaw, [
            'events' => $events,
            'provider_receipt_sha256' => hash('sha256', $providerReceipt),
        ]);
    },
    'provider receipt bytes do not match the signed commitment' => function (array &$central, array &$evidence, array $authority, string &$providerReceipt): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $evidence = watchdogEvidence($authority, $centralRaw);
        $providerReceipt = '{"receipt_reference":"PROVIDER-substituted"}';
    },
    'outage and alarm collapse to the same instant' => function (array &$central, array &$evidence, array $authority): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $events = watchdogEvents();
        $events[0]['alarm_raised_at'] = $events[0]['outage_started_at'];
        $evidence = watchdogEvidence($authority, $centralRaw, ['events' => $events]);
    },
    'recovery and restored delivery collapse to the same instant' => function (array &$central, array &$evidence, array $authority): void {
        $centralRaw = json_encode($central, JSON_THROW_ON_ERROR);
        $events = watchdogEvents();
        $events[0]['delivery_restored_at'] = $events[0]['recovery_started_at'];
        $evidence = watchdogEvidence($authority, $centralRaw, ['events' => $events]);
    },
]);

it('rejects duplicate evidence keys before signature-backed semantic validation', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $authority = watchdogAuthority($publicKey);
    $centralRaw = json_encode(watchdogCentralEvidence($authority), JSON_THROW_ON_ERROR);
    $evidenceRaw = json_encode(watchdogEvidence($authority, $centralRaw), JSON_THROW_ON_ERROR);
    $duplicate = preg_replace('/\A\{/', '{"schema_version":1,', $evidenceRaw, 1);
    $signature = sodium_crypto_sign_detached((string) $duplicate, sodium_crypto_sign_secretkey($keyPair));

    expect(fn () => (new ExternalWatchdogEvidenceVerifier)->verify(
        (string) $duplicate,
        base64_encode($signature),
        base64_encode($publicKey),
        $centralRaw,
        watchdogProviderReceipt(),
        $authority,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'External watchdog release evidence is invalid.');
});
