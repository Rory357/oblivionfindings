<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Runtime\OnvifCameraNetwork;
use Oblivion\Collector\Runtime\UnifiAccessControlRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;

/*
 * S&D Phase H: governed door and Site changes in UniFi Access from the hub. Only the signed change,
 * only to the console's trusted key; the hub reads UniFi Access back straight after. A server
 * error or no answer is "uncertain" and read back, never resent.
 */

const CONTROL_PIN = 'sha256//KKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKKK=';
const CONTROL_TOKEN = 'TEST-ONLY-ACCESS-CONTROL-TOKEN';

final class ControlFixtureNetwork implements OnvifCameraNetwork
{
    /** @var list<array{method: string, url: string, body: ?string, pin: ?string}> */
    public array $requests = [];

    /** @param list<array{status: int, body: string}> $responses */
    public function __construct(public array $responses = []) {}

    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array
    {
        return null;
    }

    public function request(string $method, string $url, array $headers, ?string $body, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'pin' => $pin, 'auth' => $headers['Authorization'] ?? null];
        $next = array_shift($this->responses) ?? ['status' => 0, 'body' => ''];

        return ['status' => $next['status'], 'body' => $next['body'], 'truncated' => false, 'challenge' => ''];
    }
}

function controlOk(mixed $data = []): array
{
    return ['status' => 200, 'body' => json_encode(['code' => 'SUCCESS', 'msg' => 'success', 'data' => $data], JSON_THROW_ON_ERROR)];
}

/** @return array<string, mixed> */
function controlCommand(string $capability, array $overrides = []): array
{
    $door = in_array($capability, ['access.door.keep_unlocked', 'access.door.keep_unlocked_end'], true);
    $operation = [
        'access.door.keep_unlocked' => 'keep_unlocked',
        'access.door.keep_unlocked_end' => 'keep_unlocked_end',
        'access.site.lockdown' => 'lockdown',
        'access.site.evacuate' => 'evacuate',
        'access.site.emergency_end' => 'emergency_end',
    ][$capability];
    $expected = [
        'keep_unlocked' => ['keep_unlocked' => true],
        'keep_unlocked_end' => ['keep_unlocked' => false],
        'lockdown' => ['emergency' => 'lockdown'],
        'evacuate' => ['emergency' => 'evacuation'],
        'emergency_end' => ['emergency' => 'normal'],
    ][$operation];

    return array_replace_recursive([
        'command_uuid' => '8c1d2e3f-4a5b-4c6d-9e7f-8a9b0c1d2e3f',
        'attempt_uuid' => '9d2e3f4a-5b6c-4d7e-8f9a-0b1c2d3e4f5a',
        'attempt_number' => 1,
        'site_id' => 9,
        'device_id' => '41',
        'capability' => $capability,
        'provider' => 'unifi_access',
        'adapter' => 'unifi_access_control_v1',
        'protocol' => 'command.unifi_access_control',
        'target' => '10.44.0.60',
        'expires_at' => '2026-07-23T12:02:00+00:00',
        'idempotency_hash' => hash('sha256', 'door-change-once'),
        'contract_hash' => hash('sha256', 'immutable-door-change-contract'),
        'parameters' => ['operation' => $operation, ...($operation === 'keep_unlocked' ? ['minutes' => 120] : [])],
        'expected_state' => $expected,
        'endpoint' => [
            'address' => '10.44.0.60',
            'port' => 12445,
            'trusted_pin' => CONTROL_PIN,
            'door_id' => $door ? 'door-front' : null,
            'connect_timeout_seconds' => 5,
            'response_timeout_seconds' => 15,
            'max_response_bytes' => 65_536,
        ],
        'credential_lease' => sealedCollectorCredentialLease(
            ['api_token' => CONTROL_TOKEN],
            ['device_id' => '41', 'protocol' => 'command.unifi_access_control', 'target' => '10.44.0.60'],
        ),
    ], $overrides);
}

function controlConfig(array $command, string $directory): CollectorConfig
{
    return (new SignedConfigLoader(
        new EnvelopeVerifier(collectorPublicKey()),
        new CheckpointFile($directory.'/checkpoint.json'),
        '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        9,
    ))->load(signedCollectorConfig([
        'version' => 3,
        'scope' => [
            'devices' => ['41' => ['10.44.0.60']],
            'protocols' => ['icmp', 'tcp', 'command.unifi_access_control'],
        ],
        'commands' => [$command],
    ]), collectorNow());
}

function controlRunner(CollectorConfig $config, ControlFixtureNetwork $network): UnifiAccessControlRunner
{
    return new UnifiAccessControlRunner(
        new ScopeGuard($config),
        new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
        $network,
        static fn (int $seconds): int => 0,
        static fn (): int => collectorNow()->getTimestamp(),
    );
}

it('keeps a door unlocked for the signed minutes, to the trusted key, and reads it back', function () {
    $directory = collectorTempDirectory('access-control-keep');
    try {
        $command = controlCommand('access.door.keep_unlocked');
        $network = new ControlFixtureNetwork([controlOk(), controlOk(['type' => 'custom', 'ended_time' => collectorNow()->getTimestamp() + 7200])]);
        $result = controlRunner(controlConfig($command, $directory), $network)->run($command, collectorNow());

        expect($result['execution_status'])->toBe('succeeded')
            ->and($result['safe_result'])->toBe(['operation' => 'keep_unlocked', 'provider_state' => 'accepted'])
            ->and($result['provider_request_reference'])->toBe('unifi-access-control:'.$command['command_uuid'])
            ->and($result['reconciliation']['outcome'])->toBe('matched')
            ->and($result['reconciliation']['observed_state'])->toBe(['keep_unlocked' => true])
            ->and($network->requests[0]['method'])->toBe('PUT')
            ->and($network->requests[0]['url'])->toBe('https://10.44.0.60:12445/api/v1/developer/doors/door-front/lock_rule')
            ->and($network->requests[0]['body'])->toBe('{"type":"custom","interval":120}')
            ->and($network->requests[0]['pin'])->toBe(CONTROL_PIN)
            ->and($network->requests[0]['auth'])->toBe('Bearer '.CONTROL_TOKEN)
            ->and($network->requests[1]['method'])->toBe('GET')
            ->and(json_encode($result))->not->toContain(CONTROL_TOKEN);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('sets the Site’s emergency mode and reads it back', function (string $capability, string $body, array $readBack, string $outcome) {
    $directory = collectorTempDirectory('access-control-emergency');
    try {
        $command = controlCommand($capability);
        $network = new ControlFixtureNetwork([controlOk(), controlOk($readBack)]);
        $result = controlRunner(controlConfig($command, $directory), $network)->run($command, collectorNow());

        expect($result['execution_status'])->toBe('succeeded')
            ->and($network->requests[0]['url'])->toBe('https://10.44.0.60:12445/api/v1/developer/doors/settings/emergency')
            ->and($network->requests[0]['body'])->toBe($body)
            ->and($result['reconciliation']['outcome'])->toBe($outcome);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'lockdown' => ['access.site.lockdown', '{"lockdown":true,"evacuation":false}', ['lockdown' => true, 'evacuation' => false], 'matched'],
    'evacuate' => ['access.site.evacuate', '{"lockdown":false,"evacuation":true}', ['lockdown' => false, 'evacuation' => true], 'matched'],
    'end' => ['access.site.emergency_end', '{"lockdown":false,"evacuation":false}', ['lockdown' => false, 'evacuation' => false], 'matched'],
    'evacuate but still locked down' => ['access.site.evacuate', '{"lockdown":false,"evacuation":true}', ['lockdown' => true, 'evacuation' => false], 'mismatch'],
]);

it('says uncertain on a server error or no answer, reads back, and never resends', function (array $first) {
    $directory = collectorTempDirectory('access-control-uncertain');
    try {
        $command = controlCommand('access.site.evacuate');
        $network = new ControlFixtureNetwork([$first, controlOk(['lockdown' => false, 'evacuation' => true])]);
        $result = controlRunner(controlConfig($command, $directory), $network)->run($command, collectorNow());

        expect($result['execution_status'])->toBe('uncertain')
            ->and($result['safe_failure_reason'])->toContain('Check the doors')
            ->and($result['reconciliation']['observed_state'])->toBe(['emergency' => 'evacuation'])
            ->and(array_column($network->requests, 'method'))->toBe(['PUT', 'GET']);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'server error' => [['status' => 502, 'body' => '']],
    'no answer' => [['status' => 0, 'body' => '']],
]);

it('says plainly when UniFi Access refuses, without reading back a change that never happened', function () {
    $directory = collectorTempDirectory('access-control-refused');
    try {
        $command = controlCommand('access.door.keep_unlocked_end');
        $network = new ControlFixtureNetwork([['status' => 401, 'body' => '{}']]);
        $result = controlRunner(controlConfig($command, $directory), $network)->run($command, collectorNow());

        expect($result['execution_status'])->toBe('failed')
            ->and($result['safe_failure_reason'])->toBe('UniFi Access rejected the saved login.')
            ->and($result['reconciliation'])->toBeNull()
            ->and($network->requests)->toHaveCount(1);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('refuses a change outside its signed shape (the hub refuses the whole work list)', function (array $overrides) {
    $directory = collectorTempDirectory('access-control-shape');
    try {
        $command = controlCommand('access.door.keep_unlocked', $overrides);
        expect(fn () => controlConfig($command, $directory))->toThrow(ConfigurationRejected::class);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'a day unlocked' => [['parameters' => ['minutes' => 1440]]],
    'unlock forever' => [['parameters' => ['operation' => 'keep_unlock_forever']]],
    'no trusted key' => [['endpoint' => ['trusted_pin' => null]]],
    'a public address' => [['target' => '8.8.8.8', 'endpoint' => ['address' => '8.8.8.8']]],
    'no door' => [['endpoint' => ['door_id' => null]]],
    'wrong expected state' => [['expected_state' => ['keep_unlocked' => false]]],
]);
