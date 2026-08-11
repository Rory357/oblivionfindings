<?php

use App\Support\Monitoring\RestoreReleaseAuthorityVerifier;

function restoreReleaseAuthorityRecord(array $overrides = []): array
{
    return [
        'schema_version' => 1,
        'evidence_class' => 'monitoring_restore_release_authority_v1',
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'release_revision' => str_repeat('b', 40),
        'restored_environment_reference_sha256' => str_repeat('c', 64),
        'restored_runtime_commitment_sha256' => str_repeat('f', 64),
        'backup_generation' => 'BKP-'.str_repeat('e', 32),
        'backup_manifest_sha256' => str_repeat('d', 64),
        'recovery_point_utc' => '2026-08-10T01:00:00Z',
        'recovery_started_at_utc' => '2026-08-10T01:10:00Z',
        'maximum_rpo_minutes' => 15,
        'maximum_rto_minutes' => 60,
        'valid_from_utc' => '2026-08-10T00:55:00Z',
        'valid_until_utc' => '2026-08-10T02:00:00Z',
        ...$overrides,
    ];
}

function restoreReleaseAuthorityMetadata(array $overrides = []): array
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

it('accepts one exact protected restore release authority', function (): void {
    $raw = json_encode(restoreReleaseAuthorityRecord(), JSON_THROW_ON_ERROR);
    $authority = (new RestoreReleaseAuthorityVerifier)->verifyRecord(
        $raw,
        restoreReleaseAuthorityMetadata(),
        new DateTimeImmutable('2026-08-10T01:15:00Z'),
    );

    expect($authority)->toMatchArray([
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'authority_sha256' => hash('sha256', $raw),
        'backup_generation' => 'BKP-'.str_repeat('e', 32),
        'backup_manifest_sha256' => str_repeat('d', 64),
        'release_revision' => str_repeat('b', 40),
        'restored_environment_reference_sha256' => str_repeat('c', 64),
        'restored_runtime_commitment_sha256' => str_repeat('f', 64),
    ]);
});

it('rejects unprotected stale ambiguous or unapproved restore authority data', function (Closure $mutate): void {
    $record = restoreReleaseAuthorityRecord();
    $metadata = restoreReleaseAuthorityMetadata();
    $mutate($record, $metadata);

    expect(fn () => (new RestoreReleaseAuthorityVerifier)->verifyRecord(
        json_encode($record, JSON_THROW_ON_ERROR),
        $metadata,
        new DateTimeImmutable('2026-08-10T01:15:00Z'),
    ))->toThrow(RuntimeException::class, 'Restore release authority is invalid.');
})->with([
    'group writable' => function (array &$record, array &$metadata): void {
        $metadata['mode'] = 0100664;
    },
    'not root owned' => function (array &$record, array &$metadata): void {
        $metadata['owner_uid'] = 1000;
    },
    'expired' => function (array &$record): void {
        $record['valid_until_utc'] = '2026-08-10T01:14:59Z';
    },
    'overlong authority' => function (array &$record): void {
        $record['valid_until_utc'] = '2026-08-11T01:00:01Z';
    },
    'rpo exceeds authority' => function (array &$record): void {
        $record['maximum_rpo_minutes'] = 9;
    },
    'non UTC recovery start' => function (array &$record): void {
        $record['recovery_started_at_utc'] = '2026-08-10T01:10:00+00:00';
    },
    'malformed restored runtime commitment' => function (array &$record): void {
        $record['restored_runtime_commitment_sha256'] = 'not-a-commitment';
    },
    'extra field' => function (array &$record): void {
        $record['endpoint'] = 'prohibited';
    },
]);

it('rejects recursively duplicated authority keys before validation', function (): void {
    $record = restoreReleaseAuthorityRecord();
    $raw = json_encode($record, JSON_THROW_ON_ERROR);
    $duplicate = preg_replace(
        '/\A\{/',
        '{"backup_generation":"'.$record['backup_generation'].'",',
        $raw,
        1,
    );

    expect(fn () => (new RestoreReleaseAuthorityVerifier)->verifyRecord(
        (string) $duplicate,
        restoreReleaseAuthorityMetadata(),
        new DateTimeImmutable('2026-08-10T01:15:00Z'),
    ))->toThrow(RuntimeException::class, 'Restore release authority is invalid.');
});
