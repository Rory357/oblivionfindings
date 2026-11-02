<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Runtime\CameraHubNetwork;
use Oblivion\Collector\Runtime\CameraSnapshotRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;

/*
 * S&D Phase B: a camera picture taken on the hub. The hub reads the Protect console's certificate
 * first, and sends the API key only over a connection pinned to the trusted key.
 */

const CAMERA_HUB_PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
const CAMERA_HUB_OTHER_PIN = 'sha256//BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';

final class CameraHubFixtureNetwork implements CameraHubNetwork
{
    /** @var list<array{url: string, headers: array<string, string>, pin: ?string}> */
    public array $requests = [];

    /** @param list<array{status: int, body: string, truncated: bool}> $responses */
    public function __construct(public string $pin, public array $responses = [], public bool $publiclyTrusted = false) {}

    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array
    {
        return [
            'pin' => $this->pin,
            'fingerprint' => 'AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89:AB:CD:EF:01:23:45:67:89',
            'subject' => 'UDM-Pro',
            'valid_to' => 1_900_000_000,
            'publicly_trusted' => $this->publiclyTrusted,
        ];
    }

    public function get(string $url, array $headers, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'pin' => $pin];

        return array_shift($this->responses) ?? ['status' => 0, 'body' => '', 'truncated' => false];
    }
}

/** @return array<string, mixed> */
function cameraHubCommandFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'command_uuid' => '8c2a9d1e-4b3f-4e5a-9b6c-7d8e9f0a1b2c',
        'attempt_uuid' => '9d3b0e2f-5c4a-4f6b-8c7d-8e9f0a1b2c3d',
        'attempt_number' => 1,
        'site_id' => 9,
        'device_id' => '1',
        'capability' => 'camera.snapshot.capture',
        'provider' => 'unifi_protect',
        'adapter' => 'camera_snapshot_v1',
        'protocol' => 'command.camera_snapshot',
        'target' => '10.44.0.10',
        'expires_at' => '2026-07-23T12:30:00+00:00',
        'idempotency_hash' => hash('sha256', 'camera-picture-once'),
        'contract_hash' => hash('sha256', 'immutable-camera-picture-contract'),
        'parameters' => ['method' => 'protect_integration_v1'],
        'expected_state' => ['picture_captured' => true],
        'endpoint' => [
            'address' => '10.44.0.10',
            'port' => 443,
            'trusted_pin' => CAMERA_HUB_PIN,
            'camera_id' => null,
            'mac' => 'AABBCC001122',
            'connect_timeout_seconds' => 5,
            'response_timeout_seconds' => 10,
            'max_image_bytes' => 1_048_576,
        ],
        'credential_lease' => sealedCollectorCredentialLease(
            ['protect_api_key' => 'HUB-CAMERA-TEST-ONLY-API-KEY'],
            ['device_id' => '1', 'protocol' => 'command.camera_snapshot', 'target' => '10.44.0.10'],
        ),
    ], $overrides);
}

function cameraHubConfig(array $command, string $directory): CollectorConfig
{
    return (new SignedConfigLoader(
        new EnvelopeVerifier(collectorPublicKey()),
        new CheckpointFile($directory.'/checkpoint.json'),
        '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        9,
    ))->load(signedCollectorConfig([
        'version' => 3,
        'scope' => [
            'devices' => ['1' => ['10.44.0.10']],
            'protocols' => ['icmp', 'tcp', 'dns', 'http', 'https', 'tls', 'snmp', 'ssh', 'winrm', 'command.camera_snapshot'],
        ],
        'commands' => [$command],
    ]), collectorNow());
}

function cameraHubRunner(CollectorConfig $config, CameraHubFixtureNetwork $network): CameraSnapshotRunner
{
    return new CameraSnapshotRunner(new ScopeGuard($config), new CredentialLeaseDecryptor(collectorIdentitySecretKey()), $network);
}

it('sends no API key to a console whose certificate is not trusted, and reports the certificate', function () {
    $directory = collectorTempDirectory('camera-untrusted');
    try {
        $config = cameraHubConfig(cameraHubCommandFixture(['endpoint' => ['trusted_pin' => null]]), $directory);
        $network = new CameraHubFixtureNetwork(CAMERA_HUB_OTHER_PIN);

        $result = cameraHubRunner($config, $network)->run($config->commands[0], collectorNow());

        expect($network->requests)->toBe([])
            ->and($result['execution_status'])->toBe('failed')
            ->and($result['safe_result']['reason_code'])->toBe('camera_certificate_untrusted')
            ->and($result['safe_result']['offered_pin'])->toBe(CAMERA_HUB_OTHER_PIN)
            ->and(array_keys($result['safe_result']))->toBe(['reason_code', 'offered_pin', 'offered_fingerprint', 'offered_subject', 'offered_valid_to']);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('refuses a console whose certificate changed', function () {
    $directory = collectorTempDirectory('camera-changed');
    try {
        $config = cameraHubConfig(cameraHubCommandFixture(), $directory);
        $network = new CameraHubFixtureNetwork(CAMERA_HUB_OTHER_PIN);

        $result = cameraHubRunner($config, $network)->run($config->commands[0], collectorNow());

        expect($network->requests)->toBe([])
            ->and($result['safe_result']['reason_code'])->toBe('camera_certificate_changed');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('finds the camera by MAC over the pinned connection and attaches the picture', function () {
    $directory = collectorTempDirectory('camera-success');
    try {
        $config = cameraHubConfig(cameraHubCommandFixture(), $directory);
        $picture = "\xFF\xD8\xFF\xE0".str_repeat("\x10", 64)."\xFF\xD9";
        $network = new CameraHubFixtureNetwork(CAMERA_HUB_PIN, [
            ['status' => 200, 'body' => json_encode([['id' => 'cam-front-door', 'mac' => 'aa:bb:cc:00:11:22']]), 'truncated' => false],
            ['status' => 200, 'body' => $picture, 'truncated' => false],
        ]);

        $result = cameraHubRunner($config, $network)->run($config->commands[0], collectorNow());

        expect($network->requests)->toHaveCount(2)
            ->and(array_column($network->requests, 'pin'))->toBe([CAMERA_HUB_PIN, CAMERA_HUB_PIN])
            ->and($network->requests[1]['url'])->toBe('https://10.44.0.10/proxy/protect/integration/v1/cameras/cam-front-door/snapshot')
            ->and($network->requests[1]['headers']['X-API-KEY'])->toBe('HUB-CAMERA-TEST-ONLY-API-KEY')
            ->and($result['execution_status'])->toBe('succeeded')
            ->and($result['safe_result'])->toBe([
                'provider_state' => 'captured', 'mime' => 'image/jpeg',
                'image_bytes' => strlen($picture), 'image_sha256' => hash('sha256', $picture),
            ])
            ->and(gzdecode(base64_decode($result['attachment']['data'], true)))->toBe($picture)
            ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('HUB-CAMERA-TEST-ONLY-API-KEY');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('stops at the 1 MB limit, a rejected key and a reply that is not a picture', function (array $response, string $code) {
    $directory = collectorTempDirectory('camera-limits');
    try {
        $config = cameraHubConfig(cameraHubCommandFixture(['endpoint' => ['camera_id' => 'cam-front-door']]), $directory);
        $network = new CameraHubFixtureNetwork(CAMERA_HUB_PIN, [$response]);

        expect(cameraHubRunner($config, $network)->run($config->commands[0], collectorNow())['safe_result'])->toBe(['reason_code' => $code]);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'too large' => [['status' => 200, 'body' => '', 'truncated' => true], 'snapshot_too_large'],
    'key rejected' => [['status' => 401, 'body' => '', 'truncated' => false], 'authentication_required'],
    'not a picture' => [['status' => 200, 'body' => '<html>login</html>', 'truncated' => false], 'invalid_image'],
    'no answer' => [['status' => 0, 'body' => '', 'truncated' => false], 'unreachable'],
]);

it('refuses a camera job with a bad pin, an odd port, no way to find the camera or an open size', function (array $overrides) {
    $directory = collectorTempDirectory('camera-scope');
    try {
        expect(fn () => cameraHubConfig(cameraHubCommandFixture($overrides), $directory))->toThrow(ConfigurationRejected::class);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'malformed pin' => [['endpoint' => ['trusted_pin' => 'sha1//abc']]],
    'odd port' => [['endpoint' => ['port' => 22]]],
    'no camera id or mac' => [['endpoint' => ['mac' => null]]],
    'unbounded picture' => [['endpoint' => ['max_image_bytes' => 50_000_000]]],
    'another method' => [['parameters' => ['method' => 'rtsp_grab']]],
]);
