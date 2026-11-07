<?php

use Oblivion\Collector\Config\SignedConfigLoader;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Runtime\OnvifCameraNetwork;
use Oblivion\Collector\Runtime\OnvifSnapshotRunner;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;

/*
 * S&D Phase E (R5): a picture from a standard ONVIF camera, taken on the hub. The password never
 * travels in the clear: SOAP carries a WS-Security digest, and only an HTTP Digest challenge is
 * answered. The picture address must be the camera's own IP.
 */

const ONVIF_HUB_PIN = 'sha256//CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC=';
const ONVIF_HUB_OTHER_PIN = 'sha256//DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD=';
const ONVIF_HUB_PASSWORD = 'HUB-ONVIF-TEST-ONLY-PASSWORD';
const ONVIF_DIGEST_CHALLENGE = 'Digest realm="IP Camera", qop="auth", nonce="abc123", algorithm=MD5';

final class OnvifHubFixtureNetwork implements OnvifCameraNetwork
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, pin: ?string}> */
    public array $requests = [];

    /** @param list<array{status: int, body: string, truncated?: bool, challenge?: string}> $responses */
    public function __construct(public array $responses = [], public string $pin = ONVIF_HUB_PIN, public bool $publiclyTrusted = false) {}

    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array
    {
        return ['pin' => $this->pin, 'fingerprint' => 'AB:CD', 'subject' => 'IPC', 'valid_to' => 1_900_000_000, 'publicly_trusted' => $this->publiclyTrusted];
    }

    public function request(string $method, string $url, array $headers, ?string $body, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'pin');
        $next = array_shift($this->responses) ?? ['status' => 0, 'body' => ''];

        return ['status' => $next['status'], 'body' => $next['body'], 'truncated' => $next['truncated'] ?? false, 'challenge' => $next['challenge'] ?? ''];
    }
}

/** @return array<string, mixed> */
function onvifHubCommandFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'command_uuid' => '6a0b7c8d-2e1f-4a3b-8c4d-5e6f7a8b9c0d',
        'attempt_uuid' => '7b1c8d9e-3f2a-4b4c-9d5e-6f7a8b9c0d1e',
        'attempt_number' => 1,
        'site_id' => 9,
        'device_id' => '1',
        'capability' => 'camera.snapshot.capture',
        'provider' => 'onvif',
        'adapter' => 'camera_snapshot_onvif_v1',
        'protocol' => 'command.camera_onvif',
        'target' => '10.44.0.30',
        'expires_at' => '2026-07-23T12:30:00+00:00',
        'idempotency_hash' => hash('sha256', 'onvif-picture-once'),
        'contract_hash' => hash('sha256', 'immutable-onvif-picture-contract'),
        'parameters' => ['method' => 'onvif_media_v1'],
        'expected_state' => ['picture_captured' => true],
        'endpoint' => [
            'scheme' => 'http',
            'address' => '10.44.0.30',
            'port' => 80,
            'trusted_pin' => null,
            'media_path' => '/onvif/media_service',
            'media2_path' => '/onvif/media2_service',
            'connect_timeout_seconds' => 5,
            'response_timeout_seconds' => 10,
            'max_image_bytes' => 1_048_576,
        ],
        'credential_lease' => sealedCollectorCredentialLease(
            ['username' => 'viewer', 'password' => ONVIF_HUB_PASSWORD],
            ['device_id' => '1', 'protocol' => 'command.camera_onvif', 'target' => '10.44.0.30'],
        ),
    ], $overrides);
}

function onvifHubConfig(array $command, string $directory): CollectorConfig
{
    return (new SignedConfigLoader(
        new EnvelopeVerifier(collectorPublicKey()),
        new CheckpointFile($directory.'/checkpoint.json'),
        '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        9,
    ))->load(signedCollectorConfig([
        'version' => 3,
        'scope' => [
            'devices' => ['1' => ['10.44.0.30']],
            'protocols' => ['icmp', 'tcp', 'command.camera_onvif'],
        ],
        'commands' => [$command],
    ]), collectorNow());
}

function onvifHubRunner(CollectorConfig $config, OnvifHubFixtureNetwork $network): OnvifSnapshotRunner
{
    return new OnvifSnapshotRunner(new ScopeGuard($config), new CredentialLeaseDecryptor(collectorIdentitySecretKey()), $network);
}

function onvifProfilesXml(string $token = 'Profile_1'): string
{
    return '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><trt:GetProfilesResponse xmlns:trt="http://www.onvif.org/ver10/media/wsdl"><trt:Profiles fixed="true" token="'.$token.'"><tt:Name>main</tt:Name></trt:Profiles></trt:GetProfilesResponse></s:Body></s:Envelope>';
}

function onvifSnapshotUriXml(string $uri): string
{
    return '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><trt:GetSnapshotUriResponse xmlns:trt="http://www.onvif.org/ver10/media/wsdl"><trt:MediaUri><tt:Uri>'.htmlspecialchars($uri, ENT_XML1).'</tt:Uri></trt:MediaUri></trt:GetSnapshotUriResponse></s:Body></s:Envelope>';
}

function onvifPicture(): string
{
    return "\xFF\xD8\xFF\xE0".str_repeat("\x22", 64)."\xFF\xD9";
}

it('takes the picture with digests only, and the password never travels in the clear', function () {
    $directory = collectorTempDirectory('onvif-success');
    try {
        $config = onvifHubConfig(onvifHubCommandFixture(), $directory);
        $network = new OnvifHubFixtureNetwork([
            ['status' => 401, 'body' => '', 'challenge' => ONVIF_DIGEST_CHALLENGE],
            ['status' => 200, 'body' => onvifProfilesXml()],
            ['status' => 200, 'body' => onvifSnapshotUriXml('http://10.44.0.30/onvif-http/snapshot?Profile_1&amp=1')],
            ['status' => 401, 'body' => '', 'challenge' => ONVIF_DIGEST_CHALLENGE],
            ['status' => 200, 'body' => onvifPicture()],
        ]);

        $result = onvifHubRunner($config, $network)->run($config->commands[0], collectorNow());

        $wire = json_encode($network->requests, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        expect(array_column($network->requests, 'method'))->toBe(['POST', 'POST', 'POST', 'GET', 'GET'])
            ->and($network->requests[0]['url'])->toBe('http://10.44.0.30/onvif/media_service')
            ->and(str_contains((string) $network->requests[0]['body'], '#PasswordDigest'))->toBe(true)
            ->and(isset($network->requests[0]['headers']['Authorization']))->toBe(false)
            ->and(str_starts_with($network->requests[1]['headers']['Authorization'], 'Digest '))->toBe(true)
            ->and(str_contains((string) $network->requests[2]['body'], '<trt:ProfileToken>Profile_1</trt:ProfileToken>'))->toBe(true)
            ->and($network->requests[3]['url'])->toBe('http://10.44.0.30/onvif-http/snapshot?Profile_1&amp=1')
            ->and(isset($network->requests[3]['headers']['Authorization']))->toBe(false)
            ->and(str_starts_with($network->requests[4]['headers']['Authorization'], 'Digest '))->toBe(true)
            ->and($wire)->not->toContain(ONVIF_HUB_PASSWORD)
            ->and($wire)->not->toContain('Basic ')
            ->and($result['execution_status'])->toBe('succeeded')
            ->and($result['safe_result']['provider_state'])->toBe('captured')
            ->and($result['provider_request_reference'])->toBe('camera-snapshot:6a0b7c8d-2e1f-4a3b-8c4d-5e6f7a8b9c0d')
            ->and(gzdecode(base64_decode($result['attachment']['data'], true)))->toBe(onvifPicture())
            ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain(ONVIF_HUB_PASSWORD);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('sends no password to a camera that asks for Basic', function () {
    $directory = collectorTempDirectory('onvif-basic');
    try {
        $config = onvifHubConfig(onvifHubCommandFixture(), $directory);
        $network = new OnvifHubFixtureNetwork([
            ['status' => 200, 'body' => onvifProfilesXml()],
            ['status' => 200, 'body' => onvifSnapshotUriXml('http://10.44.0.30/snap.jpg')],
            ['status' => 401, 'body' => '', 'challenge' => 'Basic realm="camera"'],
        ]);

        $result = onvifHubRunner($config, $network)->run($config->commands[0], collectorNow());

        expect($network->requests)->toHaveCount(3)
            ->and(isset($network->requests[2]['headers']['Authorization']))->toBe(false)
            ->and($result['execution_status'])->toBe('failed')
            ->and($result['safe_result'])->toBe(['reason_code' => 'basic_auth_refused']);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('refuses a picture address that isn’t the camera, or https on another port', function (string $uri, array $overrides) {
    $directory = collectorTempDirectory('onvif-elsewhere');
    try {
        $config = onvifHubConfig(onvifHubCommandFixture($overrides), $directory);
        $network = new OnvifHubFixtureNetwork([
            ['status' => 200, 'body' => onvifProfilesXml()],
            ['status' => 200, 'body' => onvifSnapshotUriXml($uri)],
        ], publiclyTrusted: false);

        $result = onvifHubRunner($config, $network)->run($config->commands[0], collectorNow());

        expect($network->requests)->toHaveCount(2)
            ->and($result['safe_result'])->toBe(['reason_code' => 'invalid_snapshot_uri']);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'another address' => ['http://10.44.0.99/snap.jpg', []],
    'a host name' => ['http://camera.local/snap.jpg', []],
    'a login in the address' => ['http://admin:x@10.44.0.30/snap.jpg', []],
    'an odd port' => ['http://10.44.0.30:22/snap.jpg', []],
    'https while the camera is http' => ['https://10.44.0.30/snap.jpg', []],
    'https on another port' => ['https://10.44.0.30:8443/snap.jpg', ['endpoint' => ['scheme' => 'https', 'port' => 443, 'trusted_pin' => ONVIF_HUB_PIN]]],
]);

it('falls back to Media2 when Media1 isn’t there', function () {
    $directory = collectorTempDirectory('onvif-media2');
    try {
        $config = onvifHubConfig(onvifHubCommandFixture(), $directory);
        $network = new OnvifHubFixtureNetwork([
            ['status' => 500, 'body' => ''],
            ['status' => 200, 'body' => onvifProfilesXml('Profile_000')],
            ['status' => 200, 'body' => onvifSnapshotUriXml('http://10.44.0.30/snap.jpg')],
            ['status' => 200, 'body' => onvifPicture()],
        ]);

        $result = onvifHubRunner($config, $network)->run($config->commands[0], collectorNow());

        expect(array_column($network->requests, 'url'))->toBe([
            'http://10.44.0.30/onvif/media_service',
            'http://10.44.0.30/onvif/media2_service',
            'http://10.44.0.30/onvif/media2_service',
            'http://10.44.0.30/snap.jpg',
        ])
            ->and(str_contains((string) $network->requests[2]['body'], 'ver20/media/wsdl'))->toBe(true)
            ->and($result['execution_status'])->toBe('succeeded');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('reads an https camera’s certificate first and sends nothing when it isn’t trusted or has changed', function (?string $trusted, string $code) {
    $directory = collectorTempDirectory('onvif-https-untrusted');
    try {
        $config = onvifHubConfig(onvifHubCommandFixture(['endpoint' => ['scheme' => 'https', 'port' => 443, 'trusted_pin' => $trusted]]), $directory);
        $network = new OnvifHubFixtureNetwork([], ONVIF_HUB_OTHER_PIN);

        $result = onvifHubRunner($config, $network)->run($config->commands[0], collectorNow());

        expect($network->requests)->toBe([])
            ->and($result['safe_result']['reason_code'])->toBe($code)
            ->and($result['safe_result']['offered_pin'])->toBe(ONVIF_HUB_OTHER_PIN);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'not trusted yet' => [null, 'camera_certificate_untrusted'],
    'changed' => [ONVIF_HUB_PIN, 'camera_certificate_changed'],
]);

it('uses the trusted key for every request to an https camera', function () {
    $directory = collectorTempDirectory('onvif-https-pinned');
    try {
        $config = onvifHubConfig(onvifHubCommandFixture(['endpoint' => ['scheme' => 'https', 'port' => 443, 'trusted_pin' => ONVIF_HUB_PIN]]), $directory);
        $network = new OnvifHubFixtureNetwork([
            ['status' => 200, 'body' => onvifProfilesXml()],
            ['status' => 200, 'body' => onvifSnapshotUriXml('https://10.44.0.30/snap.jpg')],
            ['status' => 200, 'body' => onvifPicture()],
        ]);

        $result = onvifHubRunner($config, $network)->run($config->commands[0], collectorNow());

        expect(array_column($network->requests, 'pin'))->toBe([ONVIF_HUB_PIN, ONVIF_HUB_PIN, ONVIF_HUB_PIN])
            ->and($result['execution_status'])->toBe('succeeded');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('says what went wrong without guessing', function (array $responses, string $code) {
    $directory = collectorTempDirectory('onvif-failures');
    try {
        $config = onvifHubConfig(onvifHubCommandFixture(), $directory);
        $result = onvifHubRunner($config, new OnvifHubFixtureNetwork($responses))->run($config->commands[0], collectorNow());

        expect($result['safe_result'])->toBe(['reason_code' => $code])
            ->and($result['reconciliation'])->toBeNull();
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'no answer' => [[['status' => 0, 'body' => '']], 'unreachable'],
    'login rejected' => [[['status' => 401, 'body' => '', 'challenge' => 'Basic realm="x"']], 'authentication_required'],
    'no profile' => [[['status' => 200, 'body' => '<Envelope/>']], 'no_media_profile'],
    'an entity trick' => [[['status' => 200, 'body' => '<!DOCTYPE x [<!ENTITY a "b">]><Profiles token="&a;"/>']], 'no_media_profile'],
    'no picture address' => [[['status' => 200, 'body' => onvifProfilesXml()], ['status' => 500, 'body' => '']], 'snapshot_uri_unavailable'],
    'too large' => [[['status' => 200, 'body' => onvifProfilesXml()], ['status' => 200, 'body' => onvifSnapshotUriXml('http://10.44.0.30/s.jpg')], ['status' => 200, 'body' => '', 'truncated' => true]], 'snapshot_too_large'],
    'not a picture' => [[['status' => 200, 'body' => onvifProfilesXml()], ['status' => 200, 'body' => onvifSnapshotUriXml('http://10.44.0.30/s.jpg')], ['status' => 200, 'body' => '<html>login</html>']], 'invalid_image'],
]);

it('refuses an ONVIF job outside its signed shape', function (array $overrides) {
    $directory = collectorTempDirectory('onvif-scope');
    try {
        expect(fn () => onvifHubConfig(onvifHubCommandFixture($overrides), $directory))->toThrow(ConfigurationRejected::class);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'ssh port' => [['endpoint' => ['port' => 22]]],
    'https on port 80' => [['endpoint' => ['scheme' => 'https']]],
    'a pin on plain http' => [['endpoint' => ['trusted_pin' => ONVIF_HUB_PIN]]],
    'a path that climbs' => [['endpoint' => ['media_path' => '/onvif/../admin']]],
    'a path with a query' => [['endpoint' => ['media2_path' => '/onvif?x=1']]],
    'unbounded picture' => [['endpoint' => ['max_image_bytes' => 50_000_000]]],
    'another method' => [['parameters' => ['method' => 'rtsp_grab']]],
    'a host name' => [['target' => 'camera.local', 'endpoint' => ['address' => 'camera.local']]],
]);
