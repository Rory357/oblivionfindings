<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Runtime\RemoteDiscoveryRunner;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;

/** @param array<string, mixed> $overrides */
function signedRemoteDiscoveryConfig(array $overrides = []): string
{
    $payload = [
        'version' => 2,
        'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        'site_id' => 9,
        'sequence' => 5,
        'issued_at' => '2026-07-23T11:55:00+00:00',
        'expires_at' => '2026-07-23T13:00:00+00:00',
        'revoked' => false,
        'scope' => [
            'cidrs' => ['10.44.0.0/24'],
            'devices' => [],
            'protocols' => ['icmp', 'tcp', 'tls'],
            'rate_limits' => ['max_checks_per_run' => 1, 'packets_per_second' => 20],
        ],
        'checks' => [],
        'discovery_runs' => [[
            'id' => '018f0000-0000-7000-8000-000000000901',
            'site_id' => 9,
            'cidrs' => ['10.44.0.0/24'],
            'protocols' => ['icmp', 'tcp', 'tls'],
            'exclusions' => ['10.44.0.99'],
            'port_bounds' => ['tcp' => [22], 'tls' => [443]],
            'packets_per_second' => 20,
            'targets' => [
                ['target' => '10.44.0.10', 'source' => 'cidr'],
                ['target' => '10.44.0.11', 'source' => 'cidr'],
            ],
        ]],
    ];
    $payload = array_replace_recursive($payload, $overrides);
    foreach (['checks', 'discovery_runs'] as $exact) {
        if (array_key_exists($exact, $overrides)) {
            $payload[$exact] = $overrides[$exact];
        }
    }
    if (isset($overrides['scope']) && is_array($overrides['scope'])) {
        foreach ($overrides['scope'] as $key => $value) {
            $payload['scope'][$key] = $value;
        }
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return json_encode([
        'payload' => base64_encode($json),
        'signature' => base64_encode(sodium_crypto_sign_detached($json, collectorSecretKey())),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

it('accepts signed version 2 discovery work without requiring an existing Device check', function () {
    $directory = collectorTempDirectory('remote-discovery-config');
    try {
        $config = (new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        ))->load(signedRemoteDiscoveryConfig(), collectorNow());

        expect($config->version)->toBe(2)
            ->and($config->checks)->toBe([])
            ->and($config->discoveryRuns)->toHaveCount(1)
            ->and($config->discoveryRuns[0]['targets'])->toHaveCount(2);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('executes only exact signed discovery targets and returns bounded candidate evidence', function () {
    $directory = collectorTempDirectory('remote-discovery-runner');
    try {
        $config = (new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        ))->load(signedRemoteDiscoveryConfig(), collectorNow());
        $probed = [];
        $runner = new RemoteDiscoveryRunner(
            new ScopeGuard($config),
            function (array $run, array $target, array $addresses) use (&$probed): array {
                $probed[] = $target['target'];

                return [
                    'outcome' => 'found',
                    'failure_code' => null,
                    'identity' => [
                        'mac_addresses' => [],
                        'certificate_fingerprint' => null,
                        'hostname' => null,
                        'addresses' => $addresses,
                        'fingerprint' => 'network:fixture',
                    ],
                ];
            },
        );

        $results = $runner->run(
            $config->discoveryRuns[0],
            $config->collectorId,
            [],
            collectorNow(),
        );

        expect($probed)->toBe(['10.44.0.10', '10.44.0.11'])
            ->and($results)->toHaveCount(2)
            ->and($results[0]['payload'])->toMatchArray([
                'item_type' => 'discovery_result',
                'run_id' => '018f0000-0000-7000-8000-000000000901',
                'target' => '10.44.0.10',
                'outcome' => 'found',
            ])
            ->and($results[0]['payload']['identity']['addresses'])->toBe(['10.44.0.10'])
            ->and(json_encode($results, JSON_THROW_ON_ERROR))->not->toContain(
                'credential', 'password', 'secret', 'command', 'shell',
            );

        $skipped = $runner->run(
            $config->discoveryRuns[0],
            $config->collectorId,
            [$results[0]['item_id'], $results[1]['item_id']],
            collectorNow(),
        );
        expect($skipped)->toBe([])
            ->and($probed)->toHaveCount(2);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('rejects discovery targets and returned evidence outside the signed network scope', function () {
    $directory = collectorTempDirectory('remote-discovery-denial');
    try {
        $loader = new SignedConfigLoader(
            new EnvelopeVerifier(collectorPublicKey()),
            new CheckpointFile($directory.'/checkpoint.json'),
            '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
            9,
        );
        $badRun = [[
            'id' => '018f0000-0000-7000-8000-000000000902',
            'site_id' => 9,
            'cidrs' => ['10.44.0.0/24'],
            'protocols' => ['icmp'],
            'exclusions' => [],
            'port_bounds' => [],
            'packets_per_second' => 20,
            'targets' => [['target' => '10.55.0.10', 'source' => 'cidr']],
        ]];
        expect(fn () => $loader->load(
            signedRemoteDiscoveryConfig(['discovery_runs' => $badRun]),
            collectorNow(),
        ))->toThrow(ConfigurationRejected::class, 'network scope');

        $config = $loader->load(signedRemoteDiscoveryConfig(['sequence' => 6]), collectorNow());
        $runner = new RemoteDiscoveryRunner(
            new ScopeGuard($config),
            fn (): array => [
                'outcome' => 'found',
                'failure_code' => null,
                'identity' => [
                    'addresses' => ['203.0.113.10'],
                    'serial_number' => 'forged-immutable-identity',
                ],
            ],
        );
        expect(fn () => $runner->run(
            $config->discoveryRuns[0],
            $config->collectorId,
            [],
            collectorNow(),
        ))->toThrow(RuntimeException::class, 'identity');
    } finally {
        removeCollectorDirectory($directory);
    }
});
