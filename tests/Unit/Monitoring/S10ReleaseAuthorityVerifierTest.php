<?php

use App\Support\Monitoring\S10NativeProcessRunner;
use App\Support\Monitoring\S10PinnedChildSource;
use App\Support\Monitoring\S10ProcessEnvironment;
use App\Support\Monitoring\S10ProtectedRuntimeEnvironment;
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
        'runtime_environment_sha256' => hash('sha256', validS10ProtectedRuntimeEnvironmentRaw()),
        'not_before' => '2026-08-09T00:00:00Z',
        'not_after' => '2026-08-10T00:00:00Z',
    ];
}

/** @return array<string, string> */
function validS10ProtectedRuntimeEnvironment(): array
{
    return [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'approved-database.internal',
        'DB_DATABASE' => 'oblivionfindings',
        'DB_USERNAME' => 'oblivion-release-reader',
        'DB_PASSWORD' => 'protected-database-secret',
        'MONITORING_COLLECTOR_REPLAY_STORE' => 'redis',
    ];
}

function validS10ProtectedRuntimeEnvironmentRaw(): string
{
    return json_encode(validS10ProtectedRuntimeEnvironment(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
        'runtime_environment_sha256' => $authority['runtime_environment_sha256'],
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
    } elseif ($failure === 'wrong runtime environment hash') {
        $authority['runtime_environment_sha256'] = str_repeat('d', 63);
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
        'runtime_environment_sha256' => null,
    ]);
})->with([
    'wrong revision shape',
    'wrong environment shape',
    'expired',
    'overlong validity',
    'extra field',
    'duplicate field',
    'wrong runtime environment hash',
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

    expect($verifier->identitiesRemainPinned($snapshots))->toBeTrue()
        ->and($verifier->identitiesRemainPinned([...$snapshots, $identity]))->toBeTrue()
        ->and($verifier->identitiesRemainPinned(array_slice($snapshots, 0, 3)))->toBeFalse();

    $snapshots[2][$changed] = match ($changed) {
        'release_revision' => str_repeat('d', 40),
        'environment_reference_sha256' => str_repeat('e', 64),
    };

    expect($verifier->identitiesRemainPinned($snapshots))->toBeFalse();
})->with(['release_revision', 'environment_reference_sha256']);

it('removes hostile child startup and repository selectors without dropping explicitly supplied application secrets', function (): void {
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

it('loads only one authority-hashed root-private production runtime environment', function (): void {
    $raw = validS10ProtectedRuntimeEnvironmentRaw();
    $environment = (new S10ProtectedRuntimeEnvironment)->verifyRecord(
        $raw,
        [
            'is_regular_file' => true,
            'is_symlink' => false,
            'mode' => 0100600,
            'owner_uid' => 0,
            'stable_identity' => true,
        ],
        hash('sha256', $raw),
        '/usr/bin/php8.4',
    );

    expect($environment)->toMatchArray([
        ...validS10ProtectedRuntimeEnvironment(),
        'APP_CONFIG_CACHE' => '/run/oblivion-s10-release-no-config-cache.php',
        'PATH' => '/usr/bin:/bin',
        'OBLIVION_S10_PHP_BINARY' => '/usr/bin/php8.4',
        'GIT_OPTIONAL_LOCKS' => '0',
    ])->and($environment)->not->toHaveKeys(['BASH_ENV', 'PHPRC', 'HTTP_PROXY']);
});

it('rejects substituted permissive or non-production S10 runtime environments', function (string $failure): void {
    $runtime = validS10ProtectedRuntimeEnvironment();
    $metadata = [
        'is_regular_file' => true,
        'is_symlink' => false,
        'mode' => 0100600,
        'owner_uid' => 0,
        'stable_identity' => true,
    ];
    match ($failure) {
        'group readable' => $metadata['mode'] = 0100640,
        'not root owned' => $metadata['owner_uid'] = 1000,
        'local application' => $runtime['APP_ENV'] = 'local',
        'sqlite database' => $runtime['DB_CONNECTION'] = 'sqlite',
        'local replay store' => $runtime['MONITORING_COLLECTOR_REPLAY_STORE'] = 'array',
        'php injection' => $runtime['PHPRC'] = '/tmp/local.ini',
        'git substitution' => $runtime['GIT_DIR'] = '/tmp/local-repository',
        'proxy substitution' => $runtime['HTTPS_PROXY'] = 'http://127.0.0.1:9000',
        'config cache substitution' => $runtime['APP_CONFIG_CACHE'] = '/tmp/local-config.php',
        'wrong authority hash' => null,
    };
    $raw = json_encode($runtime, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $expectedHash = $failure === 'wrong authority hash' ? str_repeat('f', 64) : hash('sha256', $raw);

    expect(fn () => (new S10ProtectedRuntimeEnvironment)->verifyRecord(
        $raw,
        $metadata,
        $expectedHash,
        '/usr/bin/php8.4',
    ))->toThrow(RuntimeException::class, 'S10 protected runtime environment is invalid.');
})->with([
    'group readable',
    'not root owned',
    'local application',
    'sqlite database',
    'local replay store',
    'php injection',
    'git substitution',
    'proxy substitution',
    'config cache substitution',
    'wrong authority hash',
]);

it('does not execute ignored Composer bootstrap code before S10 release identity verification', function (): void {
    $root = dirname(__DIR__, 3);
    $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oblivion-s10-bootstrap-'.bin2hex(random_bytes(8));
    mkdir($temporary.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'monitoring', 0700, true);
    mkdir($temporary.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'Monitoring', 0700, true);
    mkdir($temporary.DIRECTORY_SEPARATOR.'vendor', 0700, true);
    mkdir($temporary.DIRECTORY_SEPARATOR.'output', 0700, true);

    copy(
        $root.'/scripts/monitoring/verify-s10-release-evidence.php',
        $temporary.'/scripts/monitoring/verify-s10-release-evidence.php',
    );
    foreach ([
        'StrictJsonObjectDecoder.php',
        'S10ProcessEnvironment.php',
        'S10ProtectedRuntimeEnvironment.php',
        'S10ReleaseAuthorityVerifier.php',
        'S10NativeProcessRunner.php',
        'S10PinnedChildSource.php',
    ] as $supportFile) {
        copy(
            $root.'/app/Support/Monitoring/'.$supportFile,
            $temporary.'/app/Support/Monitoring/'.$supportFile,
        );
    }
    file_put_contents($temporary.'/artisan', "<?php\n");
    file_put_contents(
        $temporary.'/vendor/autoload.php',
        "<?php throw new RuntimeException('ignored Composer autoload executed');\n",
    );

    try {
        $result = (new S10NativeProcessRunner)->run(
            [
                PHP_BINARY,
                $temporary.'/scripts/monitoring/verify-s10-release-evidence.php',
                '--output-directory='.$temporary.'/output',
            ],
            $temporary,
            [],
            10,
        );
        $output = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);

        expect($result['successful'])->toBeFalse()
            ->and($result['stderr'])->toBe('')
            ->and($output['status'])->toBe('failed')
            ->and($output['reason'])->toBeIn(['paths', 'runtime_binaries'])
            ->and($output['s10_release_evidence'])->toBeFalse();
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && ! is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path.DIRECTORY_SEPARATOR.$entry);
                    }
                }
                rmdir($path);

                return;
            }

            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        };
        $remove($temporary);
    }
});

it('runs one bounded shell-free S10 child and rejects oversized output', function (): void {
    $runner = new S10NativeProcessRunner;
    $success = $runner->run(
        [PHP_BINARY, '-r', 'fwrite(STDOUT, "native-ok");'],
        null,
        [],
        10,
        128,
    );
    $oversized = $runner->run(
        [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 129));'],
        null,
        [],
        10,
        128,
    );

    expect($success)->toBe([
        'successful' => true,
        'stdout' => 'native-ok',
        'stderr' => '',
    ])->and($oversized)->toBe([
        'successful' => false,
        'stdout' => '',
        'stderr' => '',
    ]);
});

it('reads only stable child bytes that exactly match the committed source', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'s10-child-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    $path = $directory.DIRECTORY_SEPARATOR.'child.sh';
    file_put_contents($path, "#!/usr/bin/env bash\nprintf 'pinned'\n");

    try {
        $source = (string) file_get_contents($path);
        $reader = new S10PinnedChildSource;

        expect($reader->read($path, $source))->toBe($source)
            ->and($reader->read($path, $source."# substituted\n"))->toBeNull();
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
});

it('supplies the pinned child source through standard input', function (): void {
    $result = (new S10NativeProcessRunner)->run(
        [PHP_BINARY, '-r', 'fwrite(STDOUT, stream_get_contents(STDIN));'],
        null,
        [],
        10,
        128,
        'pinned-child-source',
    );

    expect($result)->toBe([
        'successful' => true,
        'stdout' => 'pinned-child-source',
        'stderr' => '',
    ]);
});
