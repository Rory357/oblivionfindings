<?php

use App\Domain\Monitoring\Support\ConfigurationHistoryReleaseAuthority;
use Carbon\CarbonImmutable;

/** @return array<string, mixed> */
function configurationHistoryAuthorityRecord(CarbonImmutable $now): array
{
    return [
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'browser_attestation_public_key_base64' => base64_encode(str_repeat('b', 32)),
        'evidence_acl_reference' => 'ACL-'.str_repeat('1', 32),
        'evidence_class' => 'monitoring_configuration_history_release_authority_v1',
        'hmac_key_sha256' => hash('sha256', str_repeat('h', 32)),
        'production_attestation_public_key_base64' => base64_encode(str_repeat('p', 32)),
        'release_revision' => str_repeat('c', 40),
        'restored_environment_reference_sha256' => str_repeat('d', 64),
        'schema_version' => 1,
        'valid_from_utc' => $now->subMinutes(5)->format('Y-m-d\TH:i:s\Z'),
        'valid_until_utc' => $now->addHour()->format('Y-m-d\TH:i:s\Z'),
    ];
}

/** @return array<string, mixed> */
function configurationHistoryAuthorityMetadata(array $overrides = []): array
{
    return array_replace([
        'is_regular_file' => true,
        'is_symlink' => false,
        'mode' => 0100600,
        'owner_uid' => 0,
        'stable_identity' => true,
    ], $overrides);
}

it('accepts one exact protected A10 release authority with independent signers', function (): void {
    $now = CarbonImmutable::parse('2026-08-10T00:00:00Z');
    $record = configurationHistoryAuthorityRecord($now);
    $authority = (new ConfigurationHistoryReleaseAuthority)->verifyRecord(
        json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        configurationHistoryAuthorityMetadata(),
        $now,
    );

    expect($authority)->toMatchArray([
        'authority_reference' => $record['authority_reference'],
        'evidence_acl_reference' => $record['evidence_acl_reference'],
        'hmac_key_sha256' => $record['hmac_key_sha256'],
        'release_revision' => $record['release_revision'],
        'restored_environment_reference_sha256' => $record['restored_environment_reference_sha256'],
    ])->and($authority['production_public_key'])->toBe(str_repeat('p', 32))
        ->and($authority['browser_public_key'])->toBe(str_repeat('b', 32));
});

it('rejects caller-controlled equivalent signers stale authority and unprotected metadata', function (string $failure): void {
    $now = CarbonImmutable::parse('2026-08-10T00:00:00Z');
    $record = configurationHistoryAuthorityRecord($now);
    $metadata = configurationHistoryAuthorityMetadata();

    match ($failure) {
        'same signer alternate base64' => $record['browser_attestation_public_key_base64'] = rtrim(
            $record['production_attestation_public_key_base64'],
            '=',
        ),
        'expired' => $record['valid_until_utc'] = $now->subSecond()->format('Y-m-d\TH:i:s\Z'),
        'overlong' => $record['valid_until_utc'] = $now->addHours(25)->format('Y-m-d\TH:i:s\Z'),
        'extra key' => $record['caller_key'] = 'forbidden',
        'not root owned' => $metadata['owner_uid'] = 1000,
        'group writable' => $metadata['mode'] = 0100620,
        'symlink' => $metadata['is_symlink'] = true,
    };

    expect(fn () => (new ConfigurationHistoryReleaseAuthority)->verifyRecord(
        json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        $metadata,
        $now,
    ))->toThrow(RuntimeException::class, 'Configuration history release authority is invalid.');
})->with([
    'same signer alternate base64',
    'expired',
    'overlong',
    'extra key',
    'not root owned',
    'group writable',
    'symlink',
]);
