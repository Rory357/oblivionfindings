<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Exceptions\ScopeViolation;
use Oblivion\Collector\Runtime\ProbeRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;

it('contains no application framework or database client dependency', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($composer['require']))
        ->not->toContain('laravel/framework', 'doctrine/dbal', 'ext-pdo', 'ext-mysqli')
        ->and(json_encode($composer, JSON_THROW_ON_ERROR))->not->toContain('database', 'dsn');
});

it('loads only a valid signed configuration for the pinned collector and Site', function () {
    $directory = collectorTempDirectory('signed-config');
    try {
        $loader = new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        );

        $config = $loader->load(signedCollectorConfig(), collectorNow());

        expect($config->sequence)->toBe(4)
            ->and($config->siteId)->toBe(9)
            ->and($config->checks)->toHaveCount(1);

        $tampered = json_decode(signedCollectorConfig(), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_decode(base64_decode($tampered['payload'], true), true, flags: JSON_THROW_ON_ERROR);
        $payload['site_id'] = 10;
        $tampered['payload'] = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));

        expect(fn () => $loader->load(json_encode($tampered, JSON_THROW_ON_ERROR), collectorNow()))
            ->toThrow(ConfigurationRejected::class, 'signature');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('rejects wrong identity expiry rollback revocation and unapproved check scope', function (array $override, string $message) {
    $directory = collectorTempDirectory('rejected-config');
    try {
        $loader = new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        );

        expect(fn () => $loader->load(signedCollectorConfig($override), collectorNow()))
            ->toThrow(ConfigurationRejected::class, $message);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'collector' => [['collector_id' => '6ffb7786-57a4-41a1-8db6-22a623875f73'], 'collector'],
    'Site' => [['site_id' => 10], 'Site'],
    'expired' => [['expires_at' => '2026-07-23T11:59:59+00:00'], 'expired'],
    'revoked' => [['revoked' => true], 'revoked'],
    'network' => [['checks' => [[
        'id' => 'wrong-network', 'device_id' => 'edge-1', 'protocol' => 'icmp', 'target' => '10.45.0.10',
    ]]], 'network'],
    'device' => [['checks' => [[
        'id' => 'wrong-device', 'device_id' => 'server-1', 'protocol' => 'icmp', 'target' => '10.44.0.10',
    ]]], 'Device'],
    'protocol' => [['checks' => [[
        'id' => 'forbidden', 'device_id' => 'edge-1', 'protocol' => 'command', 'target' => '10.44.0.10',
    ]]], 'protocol'],
    'rate limit' => [['scope' => ['rate_limits' => [
        'max_checks_per_run' => 0,
        'packets_per_second' => 0,
    ]]], 'rate limit'],
]);

it('persists the accepted sequence and rejects a signed rollback after restart', function () {
    $directory = collectorTempDirectory('rollback');
    try {
        $checkpoint = new CheckpointFile($directory.'/checkpoint.json');
        $loader = new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            $checkpoint,
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        );
        $loader->load(signedCollectorConfig(['sequence' => 4]), collectorNow());

        $restarted = new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        );

        expect(fn () => $restarted->load(signedCollectorConfig(['sequence' => 3]), collectorNow()))
            ->toThrow(ConfigurationRejected::class, 'sequence');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('revalidates target scope immediately before every probe and rejects expired leases', function () {
    $directory = collectorTempDirectory('probe-scope');
    try {
        $config = (new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        ))->load(signedCollectorConfig(['checks' => [[
            'id' => 'leased-snmp',
            'device_id' => 'edge-1',
            'protocol' => 'snmp',
            'target' => '10.44.0.10',
            'credential_lease' => sealedCollectorCredentialLease([
                'username' => 'collector-runtime-user-sentinel',
            ]),
        ]]]), collectorNow());
        $guard = new ScopeGuard($config);
        $decryptor = new CredentialLeaseDecryptor(collectorIdentitySecretKey());
        $runner = new ProbeRunner($guard, $decryptor);

        expect(fn () => $guard->assertTarget('edge-1', 'icmp', '10.45.0.10', collectorNow()))
            ->toThrow(ScopeViolation::class, 'network')
            ->and(fn () => $guard->assertTarget(
                'edge-1',
                'snmp',
                '10.44.0.10',
                new DateTimeImmutable('2026-07-23T13:00:01+00:00'),
            ))->toThrow(ScopeViolation::class, 'expired')
            ->and(fn () => $runner->run($config->checks[0], new DateTimeImmutable('2026-07-23T12:31:00+00:00')))
            ->toThrow(ScopeViolation::class, 'lease');

        $result = $runner->run($config->checks[0], new DateTimeImmutable('2026-07-23T12:01:00+00:00'));
        expect($result['protocol'])->toBe('snmp')
            ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain(
                'collector-runtime-user-sentinel', 'credential_lease', 'material',
            );

        $wrongPair = sodium_crypto_sign_seed_keypair(str_repeat("\x6c", SODIUM_CRYPTO_SIGN_SEEDBYTES));
        expect(fn () => (new CredentialLeaseDecryptor(sodium_crypto_sign_secretkey($wrongPair)))->open(
            $config->checks[0],
            new DateTimeImmutable('2026-07-23T12:01:00+00:00'),
        ))->toThrow(ScopeViolation::class, 'not bound to this collector');

        $plaintext = $config->checks[0];
        $plaintext['credential_lease'] = array_replace($plaintext['credential_lease'], [
            'material' => ['username' => 'forbidden-plaintext-sentinel'],
        ]);
        expect(fn () => (new ScopeGuard(new CollectorConfig(
            version: $config->version,
            collectorId: $config->collectorId,
            siteId: $config->siteId,
            sequence: $config->sequence,
            issuedAt: $config->issuedAt,
            expiresAt: $config->expiresAt,
            revoked: $config->revoked,
            scope: $config->scope,
            checks: [$plaintext],
        )))->assertCheck($plaintext, collectorNow()))
            ->toThrow(ScopeViolation::class, 'Plaintext');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('contains no command opcode shell field or arbitrary executable path', function () {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src'));
    $source = '';
    foreach ($paths as $path) {
        if ($path->isFile() && $path->getExtension() === 'php') {
            $source .= file_get_contents($path->getPathname())."\n";
        }
    }

    expect(strtolower($source))
        ->not->toContain('commanddispatch')
        ->and($source)->not->toMatch('/\b(?:shell_exec|exec|passthru|proc_open)\s*\(/i');
});
