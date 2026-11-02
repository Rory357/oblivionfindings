<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Runtime\ProbeRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;

/*
 * Phase A, item A7 (docs/security-devices/GOAL.md): hubs ship the SNMP module and can read a device with
 * SNMP v2c (a community string) as well as v3. A runtime without the module says "unknown", never
 * "healthy", and no saved login ever appears in a result.
 */

function snmpCheckRun(array $material): array
{
    $directory = collectorTempDirectory('snmp-versions');
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
            'credential_lease' => sealedCollectorCredentialLease($material),
        ]]]), collectorNow());

        $runner = new ProbeRunner(new ScopeGuard($config), new CredentialLeaseDecryptor(collectorIdentitySecretKey()));

        return $runner->run($config->checks[0], new DateTimeImmutable('2026-07-23T12:01:00+00:00'));
    } finally {
        removeCollectorDirectory($directory);
    }
}

it('reads SNMP v2c with a community string, or says the module is missing; never healthy for an unreachable device', function (): void {
    $result = snmpCheckRun(['community' => 'collector-community-sentinel']);

    $expected = function_exists('snmp2_get') ? 'snmp_query_failed' : 'snmp_runtime_unavailable';
    expect($result['protocol'])->toBe('snmp')
        ->and($result['reason_code'] ?? null)->toBe($expected)
        ->and($result['state'] ?? null)->not->toBe('healthy')
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('collector-community-sentinel');
});

it('keeps SNMP v3 for a v3 login', function (): void {
    $result = snmpCheckRun([
        'username' => 'collector-v3-user-sentinel',
        'auth_protocol' => 'SHA',
        'auth_passphrase' => 'collector-v3-auth-sentinel',
        'privacy_protocol' => 'AES',
        'privacy_passphrase' => 'collector-v3-priv-sentinel',
    ]);

    $expected = function_exists('snmp3_get') ? 'snmp_query_failed' : 'snmp_runtime_unavailable';
    expect($result['reason_code'] ?? null)->toBe($expected)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('sentinel');
});

it('has the SNMP module in a release hub runtime', function (): void {
    // CI builds the collector with the same PHP extensions the hub runtime ships (site-hub-release.yml).
    if (getenv('OBLIVION_REQUIRE_SNMP') !== '1') {
        $this->markTestSkipped('Set OBLIVION_REQUIRE_SNMP=1 where the hub runtime is built.');
    }

    expect(extension_loaded('snmp'))->toBeTrue()
        ->and(function_exists('snmp2_get'))->toBeTrue()
        ->and(function_exists('snmp3_get'))->toBeTrue();
});
