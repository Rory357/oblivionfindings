<?php

use App\Support\Monitoring\CentralRuntimeReleaseAuthorityVerifier;

function centralRuntimeAuthorityRecord(array $overrides = []): array
{
    return [
        'schema_version' => 1,
        'evidence_class' => 'monitoring_central_runtime_release_authority_v1',
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'release_revision' => str_repeat('b', 40),
        'environment_reference_sha256' => str_repeat('c', 64),
        'application_path_sha256' => str_repeat('d', 64),
        'health_url_sha256' => str_repeat('e', 64),
        'supervisor_configuration_sha256' => str_repeat('f', 64),
        'watchdog_attestation_public_key_sha256' => str_repeat('1', 64),
        'not_before' => '2026-08-10T00:00:00Z',
        'not_after' => '2026-08-10T02:00:00Z',
        ...$overrides,
    ];
}

function centralRuntimeAuthorityMetadata(array $overrides = []): array
{
    return [
        'is_regular_file' => true,
        'is_symlink' => false,
        'mode' => 0100644,
        'owner_uid' => 0,
        'stable_identity' => true,
        ...$overrides,
    ];
}

it('accepts one exact protected central runtime release authority', function (): void {
    $raw = json_encode(centralRuntimeAuthorityRecord(), JSON_THROW_ON_ERROR);
    $authority = (new CentralRuntimeReleaseAuthorityVerifier)->verifyRecord(
        $raw,
        centralRuntimeAuthorityMetadata(),
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    );

    expect($authority)->toMatchArray([
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'authority_sha256' => hash('sha256', $raw),
        'release_revision' => str_repeat('b', 40),
        'environment_reference_sha256' => str_repeat('c', 64),
        'application_path_sha256' => str_repeat('d', 64),
        'health_url_sha256' => str_repeat('e', 64),
        'supervisor_configuration_sha256' => str_repeat('f', 64),
        'watchdog_attestation_public_key_sha256' => str_repeat('1', 64),
        'not_before_epoch' => 1_786_320_000,
        'not_after_epoch' => 1_786_327_200,
    ]);
});

it('rejects unprotected stale ambiguous or unapproved central runtime authority data', function (Closure $mutate): void {
    $record = centralRuntimeAuthorityRecord();
    $metadata = centralRuntimeAuthorityMetadata();
    $mutate($record, $metadata);

    expect(fn () => (new CentralRuntimeReleaseAuthorityVerifier)->verifyRecord(
        json_encode($record, JSON_THROW_ON_ERROR),
        $metadata,
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'Central runtime release authority is invalid.');
})->with([
    'group writable' => function (array &$record, array &$metadata): void {
        $metadata['mode'] = 0100664;
    },
    'not root owned' => function (array &$record, array &$metadata): void {
        $metadata['owner_uid'] = 1000;
    },
    'expired' => function (array &$record): void {
        $record['not_after'] = '2026-08-10T00:59:59Z';
    },
    'overlong authority' => function (array &$record): void {
        $record['not_after'] = '2026-08-11T00:00:01Z';
    },
    'non UTC time' => function (array &$record): void {
        $record['not_before'] = '2026-08-10T00:00:00+00:00';
    },
    'wrong release length' => function (array &$record): void {
        $record['release_revision'] = str_repeat('b', 39);
    },
    'extra field' => function (array &$record): void {
        $record['host'] = 'prohibited';
    },
]);

it('rejects recursively duplicated authority keys before validation', function (): void {
    $record = centralRuntimeAuthorityRecord();
    $raw = json_encode($record, JSON_THROW_ON_ERROR);
    $duplicate = preg_replace('/\A\{/', '{"release_revision":"'.$record['release_revision'].'",', $raw, 1);

    expect(fn () => (new CentralRuntimeReleaseAuthorityVerifier)->verifyRecord(
        (string) $duplicate,
        centralRuntimeAuthorityMetadata(),
        new DateTimeImmutable('2026-08-10T01:00:00Z'),
    ))->toThrow(RuntimeException::class, 'Central runtime release authority is invalid.');
});
