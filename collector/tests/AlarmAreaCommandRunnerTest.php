<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Runtime\AlarmAreaCommandRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;

/*
 * S&D Phase E (R6): arming and disarming one alarm area from the hub. The hub sends only the area
 * and operation the server signed, then reads the area back so the screen shows the panel's answer.
 */

/** @return array<string, mixed> */
function alarmHubCommandFixture(array $overrides = [], string $driver = 'inception', string $operation = 'arm'): array
{
    return array_replace_recursive([
        'command_uuid' => '7b1c8d0e-3a2f-4d4a-8b5c-6c7d8e9f0a1b',
        'attempt_uuid' => '8c2d9e1f-4b3a-4e5b-9c6d-7d8e9f0a1b2c',
        'attempt_number' => 1,
        'site_id' => 9,
        'device_id' => '1',
        'capability' => 'alarm.'.$operation,
        'provider' => $driver,
        'adapter' => 'alarm_area_control_v1',
        'protocol' => 'command.alarm_rest',
        'target' => '10.44.0.20',
        'expires_at' => '2026-07-23T12:30:00+00:00',
        'idempotency_hash' => hash('sha256', 'alarm-once'),
        'contract_hash' => hash('sha256', 'immutable-alarm-contract'),
        'parameters' => ['area' => 'A1', 'operation' => $operation],
        'expected_state' => ['armed' => $operation === 'arm'],
        'endpoint' => [
            'driver' => $driver,
            'scheme' => 'https',
            'host' => 'panel.house.example',
            'port' => $driver === 'gallagher' ? 8904 : 443,
            'address' => '10.44.0.20',
            'connect_timeout_seconds' => 5,
            'response_timeout_seconds' => 10,
            'max_response_bytes' => 65_536,
        ],
        'credential_lease' => sealedCollectorCredentialLease(
            ['api_token' => 'HUB-ALARM-TEST-ONLY-TOKEN'],
            ['device_id' => '1', 'protocol' => 'command.alarm_rest', 'target' => '10.44.0.20'],
        ),
    ], $overrides);
}

function alarmHubConfig(array $command, string $directory): CollectorConfig
{
    return (new SignedConfigLoader(
        new EnvelopeVerifier(collectorPublicKey()),
        new CheckpointFile($directory.'/checkpoint.json'),
        '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        9,
    ))->load(signedCollectorConfig([
        'version' => 3,
        'scope' => [
            'devices' => ['1' => ['10.44.0.20']],
            'protocols' => ['icmp', 'tcp', 'command.alarm_rest'],
        ],
        'commands' => [$command],
    ]), collectorNow());
}

/**
 * @param  list<array{status: int, body: string, location: ?string}|Throwable>  $responses
 * @param  list<array<string, mixed>>  $requests
 */
function alarmHubRunner(CollectorConfig $config, array $responses, array &$requests): AlarmAreaCommandRunner
{
    return new AlarmAreaCommandRunner(
        new ScopeGuard($config),
        new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
        function (array $endpoint, string $method, string $path, array $headers, ?array $json) use (&$responses, &$requests): array {
            $requests[] = compact('method', 'path', 'headers', 'json');
            $next = array_shift($responses) ?? new RuntimeException('no answer');
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        },
        fn (int $seconds): int => 0,
    );
}

it('arms an Inception area, reads it back and never reports the token', function () {
    $directory = collectorTempDirectory('alarm-inception');
    try {
        $config = alarmHubConfig(alarmHubCommandFixture(), $directory);
        $requests = [];
        $result = alarmHubRunner($config, [
            ['status' => 200, 'body' => '{"Response":{"Result":"Success"}}', 'location' => null],
            ['status' => 200, 'body' => json_encode([['ID' => 'A2', 'PublicStateDescription' => 'Disarmed'], ['ID' => 'A1', 'PublicStateDescription' => 'Armed']]), 'location' => null],
        ], $requests)->run($config->commands[0], collectorNow());

        expect(array_column($requests, 'path'))->toBe(['/api/v1/control/area/A1/activity', '/api/v1/control/area'])
            ->and($requests[0]['method'])->toBe('POST')
            ->and($requests[0]['json'])->toBe(['Type' => 'ControlArea', 'AreaControlType' => 'Arm'])
            ->and($requests[0]['headers']['Authorization'])->toBe('APIToken HUB-ALARM-TEST-ONLY-TOKEN')
            ->and($result['item_type'])->toBe('command_result')
            ->and($result['execution_status'])->toBe('succeeded')
            ->and($result['safe_result'])->toBe(['area' => 'A1', 'provider_state' => 'accepted'])
            ->and($result['provider_request_reference'])->toBe('alarm:7b1c8d0e-3a2f-4d4a-8b5c-6c7d8e9f0a1b')
            ->and($result['reconciliation']['outcome'])->toBe('matched')
            ->and($result['reconciliation']['observed_state'])->toBe(['armed' => true])
            ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('HUB-ALARM-TEST-ONLY-TOKEN');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('disarms a Gallagher zone with its own header and paths', function () {
    $directory = collectorTempDirectory('alarm-gallagher');
    try {
        $config = alarmHubConfig(alarmHubCommandFixture([], 'gallagher', 'disarm'), $directory);
        $requests = [];
        $result = alarmHubRunner($config, [
            ['status' => 204, 'body' => '', 'location' => null],
            ['status' => 200, 'body' => json_encode(['results' => [['id' => 'A1', 'status' => 'Disarmed']]]), 'location' => null],
        ], $requests)->run($config->commands[0], collectorNow());

        expect(array_column($requests, 'path'))->toBe(['/api/alarm_zones/A1/disarm', '/api/alarm_zones'])
            ->and($requests[0]['headers']['Authorization'])->toBe('GGL-API-KEY HUB-ALARM-TEST-ONLY-TOKEN')
            ->and($result['execution_status'])->toBe('succeeded')
            ->and($result['reconciliation']['outcome'])->toBe('matched')
            ->and($result['reconciliation']['observed_state'])->toBe(['armed' => false]);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('says so when the panel shows a different state afterwards, or a sounding alarm', function (string $state, string $outcome) {
    $directory = collectorTempDirectory('alarm-mismatch');
    try {
        $config = alarmHubConfig(alarmHubCommandFixture(), $directory);
        $requests = [];
        $result = alarmHubRunner($config, [
            ['status' => 200, 'body' => '{}', 'location' => null],
            ['status' => 200, 'body' => json_encode(['data' => [['ID' => 'A1', 'PublicStateDescription' => $state]]]), 'location' => null],
        ], $requests)->run($config->commands[0], collectorNow());

        expect($result['reconciliation']['outcome'])->toBe($outcome);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'still disarmed' => ['Disarmed', 'mismatch'],
    'in alarm' => ['Alarm', 'uncertain'],
]);

it('reports a refused change as failed, with no read-back', function (int $status, string $failure) {
    $directory = collectorTempDirectory('alarm-refused');
    try {
        $config = alarmHubConfig(alarmHubCommandFixture(), $directory);
        $requests = [];
        $result = alarmHubRunner($config, [['status' => $status, 'body' => '', 'location' => null]], $requests)
            ->run($config->commands[0], collectorNow());

        expect($requests)->toHaveCount(1)
            ->and($result['execution_status'])->toBe('failed')
            ->and($result['safe_failure_reason'])->toBe($failure)
            ->and($result['reconciliation'])->toBeNull();
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'login rejected' => [401, 'The alarm panel rejected the saved login.'],
    'unknown area' => [404, 'The alarm panel doesn’t know that area.'],
    'wrong state' => [409, 'The alarm panel refused the change in its current state.'],
]);

it('treats no answer or a server error as uncertain and reads the area back without resending', function (array $first) {
    $directory = collectorTempDirectory('alarm-uncertain');
    try {
        $config = alarmHubConfig(alarmHubCommandFixture(), $directory);
        $requests = [];
        $first = $first === [] ? new RuntimeException('timed out') : $first;
        $result = alarmHubRunner($config, [
            $first,
            ['status' => 200, 'body' => json_encode([['ID' => 'A1', 'PublicStateDescription' => 'Armed']]), 'location' => null],
        ], $requests)->run($config->commands[0], collectorNow());

        expect(array_column($requests, 'method'))->toBe(['POST', 'GET'])
            ->and($result['execution_status'])->toBe('uncertain')
            ->and($result['safe_result'])->toBe([])
            ->and($result['reconciliation']['outcome'])->toBe('matched');
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'timed out' => [[]],
    'server error' => [['status' => 502, 'body' => '', 'location' => null]],
]);

it('never resends after a restart', function () {
    $directory = collectorTempDirectory('alarm-restart');
    try {
        $config = alarmHubConfig(alarmHubCommandFixture(), $directory);
        $requests = [];
        $result = alarmHubRunner($config, [], $requests)->interrupted($config->commands[0], collectorNow());

        expect($requests)->toBe([])
            ->and($result['execution_status'])->toBe('uncertain')
            ->and($result['reconciliation']['outcome'])->toBe('uncertain');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('refuses an alarm job outside its signed shape', function (array $overrides, string $driver = 'inception') {
    $directory = collectorTempDirectory('alarm-scope');
    try {
        expect(fn () => alarmHubConfig(alarmHubCommandFixture($overrides, $driver), $directory))->toThrow(ConfigurationRejected::class);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'area with a path' => [['parameters' => ['area' => '../users']]],
    'operation not matching the capability' => [['parameters' => ['operation' => 'disarm']]],
    'odd port' => [['endpoint' => ['port' => 22]]],
    'Gallagher port on Inception' => [['endpoint' => ['port' => 8904]]],
    'host with a login' => [['endpoint' => ['host' => 'user@panel']]],
    'plain http' => [['endpoint' => ['scheme' => 'http']]],
    'unbounded reply' => [['endpoint' => ['max_response_bytes' => 10_000_000]]],
    'another maker' => [['provider' => 'ajax', 'endpoint' => ['driver' => 'ajax']]],
    'address not the target' => [['endpoint' => ['address' => '10.44.0.99']]],
]);
