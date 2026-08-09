<?php

use App\Support\Monitoring\S10ProcessEnvironment;
use App\Support\Monitoring\S10ReleaseAuthorityVerifier;

/** @return array<string, mixed> */
function validS10ReleaseAuthority(): array
{
    return [
        'schema_version' => 1,
        'evidence_class' => 'security_devices_s10_release_authority_v1',
        'authority_reference' => 'AUTHORITY-'.str_repeat('a', 32),
        'release_revision' => str_repeat('b', 40),
        'environment_reference_sha256' => str_repeat('c', 64),
        'not_before' => '2026-08-09T00:00:00Z',
        'not_after' => '2026-08-10T00:00:00Z',
    ];
}

/** @return array<string, mixed> */
function protectedS10ReleaseAuthorityMetadata(): array
{
    return [
        'is_regular_file' => true,
        'is_symlink' => false,
        'mode' => 0100644,
        'owner_uid' => 0,
        'stable_identity' => true,
    ];
}

it('accepts one exact protected time-bounded S10 deployed-release authority', function (): void {
    $authority = validS10ReleaseAuthority();
    $raw = json_encode($authority, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $result = (new S10ReleaseAuthorityVerifier)->verifyRecord(
        $raw,
        protectedS10ReleaseAuthorityMetadata(),
        new DateTimeImmutable('2026-08-09T12:00:00Z'),
    );

    expect($result)->toBe([
        'valid' => true,
        'authority_reference' => $authority['authority_reference'],
        'authority_sha256' => hash('sha256', $raw),
        'environment_reference_sha256' => $authority['environment_reference_sha256'],
        'release_revision' => $authority['release_revision'],
    ]);
});

it('rejects malformed stale or caller-extended S10 authority records', function (string $failure): void {
    $authority = validS10ReleaseAuthority();
    if ($failure === 'wrong revision shape') {
        $authority['release_revision'] = str_repeat('b', 39);
    } elseif ($failure === 'wrong environment shape') {
        $authority['environment_reference_sha256'] = str_repeat('c', 63);
    } elseif ($failure === 'expired') {
        $authority['not_after'] = '2026-08-09T11:59:59Z';
    } elseif ($failure === 'overlong validity') {
        $authority['not_after'] = '2026-08-10T00:00:01Z';
    } elseif ($failure === 'extra field') {
        $authority['caller_authority'] = true;
    }

    $raw = json_encode($authority, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if ($failure === 'duplicate field') {
        $raw = preg_replace(
            '/\A\{"schema_version":1,/',
            '{"schema_version":1,"schema_version":1,',
            $raw,
        );
    }

    $result = (new S10ReleaseAuthorityVerifier)->verifyRecord(
        (string) $raw,
        protectedS10ReleaseAuthorityMetadata(),
        new DateTimeImmutable('2026-08-09T12:00:00Z'),
    );

    expect($result)->toBe([
        'valid' => false,
        'authority_reference' => null,
        'authority_sha256' => null,
        'environment_reference_sha256' => null,
        'release_revision' => null,
    ]);
})->with([
    'wrong revision shape',
    'wrong environment shape',
    'expired',
    'overlong validity',
    'extra field',
    'duplicate field',
]);

it('rejects S10 authority input without protected file metadata', function (string $failure): void {
    $metadata = protectedS10ReleaseAuthorityMetadata();
    match ($failure) {
        'not root owned' => $metadata['owner_uid'] = 1000,
        'group writable' => $metadata['mode'] = 0100664,
        'other writable' => $metadata['mode'] = 0100646,
        'symlink' => $metadata['is_symlink'] = true,
        'not regular' => $metadata['is_regular_file'] = false,
        'replaced while open' => $metadata['stable_identity'] = false,
    };

    $result = (new S10ReleaseAuthorityVerifier)->verifyRecord(
        json_encode(validS10ReleaseAuthority(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        $metadata,
        new DateTimeImmutable('2026-08-09T12:00:00Z'),
    );

    expect($result['valid'])->toBeFalse();
})->with([
    'not root owned',
    'group writable',
    'other writable',
    'symlink',
    'not regular',
    'replaced while open',
]);

it('rejects a release or environment identity change between either sustained child gate', function (string $changed): void {
    $authority = validS10ReleaseAuthority();
    $raw = json_encode($authority, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $verifier = new S10ReleaseAuthorityVerifier;
    $identity = $verifier->verifyRecord(
        $raw,
        protectedS10ReleaseAuthorityMetadata(),
        new DateTimeImmutable('2026-08-09T12:00:00Z'),
    );
    $snapshots = [$identity, $identity, $identity, $identity];

    expect($verifier->identitiesRemainPinned($snapshots))->toBeTrue();

    $snapshots[2][$changed] = match ($changed) {
        'release_revision' => str_repeat('d', 40),
        'environment_reference_sha256' => str_repeat('e', 64),
    };

    expect($verifier->identitiesRemainPinned($snapshots))->toBeFalse();
})->with(['release_revision', 'environment_reference_sha256']);

it('removes hostile child startup and repository selectors without dropping application secrets', function (): void {
    $hostile = [
        'APP_KEY' => 'preserved-application-secret',
        'DB_PASSWORD' => 'preserved-database-secret',
        'PATH' => 'C:\\hostile-bin',
        'GIT_DIR' => 'C:\\hostile-repository',
        'GIT_INDEX_FILE' => 'C:\\hostile-index',
        'GIT_CONFIG_COUNT' => '2',
        'GIT_CONFIG_KEY_0' => 'core.worktree',
        'GIT_CONFIG_VALUE_0' => 'C:\\hostile-worktree',
        'GIT_CONFIG_KEY_1' => 'core.fsmonitor',
        'GIT_CONFIG_VALUE_1' => 'hostile-monitor',
        'BASH_ENV' => 'C:\\hostile-bash-startup',
        'BASH_FUNC_readlink%%' => '() { printf C:\\hostile-release; }',
        'PHPRC' => 'C:\\hostile-php.ini',
        'PHP_INI_SCAN_DIR' => 'C:\\hostile-php-scan',
    ];

    $overrides = S10ProcessEnvironment::processOverrides('/usr/bin/php8.4', $hostile);
    $sanitized = S10ProcessEnvironment::sanitized($hostile, '/usr/bin/php8.4');

    expect($overrides)->toMatchArray([
        'PATH' => '/usr/bin:/bin',
        'OBLIVION_S10_PHP_BINARY' => '/usr/bin/php8.4',
        'GIT_DIR' => false,
        'GIT_INDEX_FILE' => false,
        'GIT_CONFIG_COUNT' => false,
        'GIT_CONFIG_KEY_0' => false,
        'GIT_CONFIG_VALUE_0' => false,
        'GIT_CONFIG_KEY_1' => false,
        'GIT_CONFIG_VALUE_1' => false,
        'BASH_ENV' => false,
        'BASH_FUNC_readlink%%' => false,
        'PHPRC' => false,
        'PHP_INI_SCAN_DIR' => false,
        'GIT_OPTIONAL_LOCKS' => '0',
    ])->and($sanitized)->toMatchArray([
        'APP_KEY' => 'preserved-application-secret',
        'DB_PASSWORD' => 'preserved-database-secret',
        'PATH' => '/usr/bin:/bin',
        'OBLIVION_S10_PHP_BINARY' => '/usr/bin/php8.4',
        'GIT_OPTIONAL_LOCKS' => '0',
    ])->and($sanitized)->not->toHaveKeys([
        'GIT_DIR',
        'GIT_INDEX_FILE',
        'GIT_CONFIG_COUNT',
        'GIT_CONFIG_KEY_0',
        'GIT_CONFIG_VALUE_0',
        'GIT_CONFIG_KEY_1',
        'GIT_CONFIG_VALUE_1',
        'BASH_ENV',
        'BASH_FUNC_readlink%%',
        'PHPRC',
        'PHP_INI_SCAN_DIR',
    ]);
});
