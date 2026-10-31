<?php

use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ScopeViolation;
use Oblivion\Collector\Media\UniFiProtectLocalClient;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use Oblivion\Collector\Spool\CheckpointFile;
use Oblivion\Collector\Spool\EncryptedSpool;
use Oblivion\Collector\Telemetry\HubTelemetryReceiver;

function phase5CollectorConfig(array $cidrs = ['172.16.0.0/24'], array $protocols = ['icmp', 'https', 'syslog', 'flow'], int $siteId = 9): CollectorConfig
{
    return CollectorConfig::fromPayload([
        'version' => 2,
        'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        'site_id' => $siteId,
        'sequence' => 12,
        'issued_at' => '2026-07-23T11:55:00+00:00',
        'expires_at' => '2026-07-23T13:00:00+00:00',
        'revoked' => false,
        'scope' => [
            'cidrs' => $cidrs,
            'devices' => ['101' => ['172.16.0.1']],
            'protocols' => $protocols,
            'rate_limits' => ['max_checks_per_run' => 5, 'packets_per_second' => 20],
        ],
        'checks' => [[
            'id' => 'chk-101',
            'device_id' => '101',
            'protocol' => 'icmp',
            'target' => '172.16.0.1',
            'interval_seconds' => 60,
        ]],
    ]);
}

it('queries UniFi Protect local integration v1 inventory, snapshots, and sanitised RTSPS streams without leaking tokens', function (): void {
    $requests = [];
    // Minimal valid JPEG SOI + EOI header
    $jpegBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    $client = new UniFiProtectLocalClient(function (
        string $method,
        string $ip,
        int $port,
        string $path,
        array $headers,
        ?string $body,
    ) use (&$requests, $jpegBytes): array {
        $requests[] = [$method, $ip, $port, $path, $headers['X-API-KEY'] ?? null, $body];

        if ($path === '/proxy/protect/integration/v1/meta/info') {
            return [
                'status' => 200,
                'headers' => ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
                'body' => json_encode(['applicationVersion' => '5.1.87'], JSON_THROW_ON_ERROR),
            ];
        }

        if ($path === '/proxy/protect/integration/v1/cameras') {
            return [
                'status' => 200,
                'headers' => ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
                'body' => json_encode([
                    [
                        'id' => '66b4c9e100a1b203e4000419',
                        'name' => 'Front Porch G5 Turret',
                        'modelKey' => 'UVC G5 Turret Ultra',
                        'state' => 'CONNECTED',
                        'mac' => '70-A7-41-AA-BB-CC',
                        'featureFlags' => ['isPtz' => false],
                    ],
                    [
                        'id' => '66b4c9e100a1b203e4000420',
                        'name' => 'Car Park G5 PTZ',
                        'modelKey' => 'UVC G5 PTZ',
                        'state' => 'CONNECTED',
                        'mac' => '70:A7:41:AA:BB:CD',
                        'featureFlags' => ['isPtz' => true],
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        }

        if (str_contains($path, '/snapshot')) {
            return [
                'status' => 200,
                'headers' => ['HTTP/1.1 200 OK', 'Content-Type: image/jpeg'],
                'body' => $jpegBytes,
            ];
        }

        if (str_ends_with($path, '/rtsps-stream')) {
            return [
                'status' => 200,
                'headers' => ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
                'body' => json_encode([
                    'high' => 'rtsps://172.16.0.1:7441/AbCdEfGh123456?enableSrtp&token=SECRET_STREAM_TOKEN',
                    'low' => 'rtsps://10.99.99.99:7441/EvilCrossHostRedirect?token=SHOULD_DROP',
                ], JSON_THROW_ON_ERROR),
            ];
        }

        return ['status' => 404, 'headers' => [], 'body' => ''];
    });

    $info = $client->fetchApplicationInfo('172.16.0.1', ['api_key' => 'protect-local-key']);
    expect($info)->toBe(['ok' => true, 'application_version' => '5.1.87']);

    $cameras = $client->listCameras('172.16.0.1', ['api_key' => 'protect-local-key']);
    expect($cameras)->toHaveCount(2)
        ->and($cameras[0])->toMatchArray([
            'id' => '66b4c9e100a1b203e4000419',
            'name' => 'Front Porch G5 Turret',
            'model' => 'UVC G5 Turret Ultra',
            'state' => 'CONNECTED',
            'mac' => '70:A7:41:AA:BB:CC',
            'ptz_supported' => false,
        ])
        ->and($cameras[1]['ptz_supported'])->toBeTrue();

    $snapshot = $client->captureSnapshot('172.16.0.1', '66b4c9e100a1b203e4000419', ['api_key' => 'protect-local-key']);
    expect($snapshot['ok'])->toBeTrue()
        ->and($snapshot['reason_code'])->toBe('captured')
        ->and($snapshot['mime'])->toBe('image/jpeg')
        ->and($snapshot['bytes'])->toBe($jpegBytes);

    $streams = $client->fetchSanitisedRtspsStreams(
        '172.16.0.1',
        '66b4c9e100a1b203e4000419',
        ['api_key' => 'protect-local-key'],
        ['high', 'low'],
    );
    expect($streams['ok'])->toBeTrue()
        ->and($streams['streams'])->toHaveKey('high')
        ->and($streams['streams'])->not->toHaveKey('low')
        ->and($streams['streams']['high'])->toBe('rtsps://172.16.0.1:7441/[redacted-stream]')
        ->and($streams['streams']['high'])->not->toContain('AbCdEfGh123456')
        ->and($streams['streams']['high'])->not->toContain('SECRET_STREAM_TOKEN');
});

it('enforces ScopeGuard CIDR boundaries and sealed credential leases on UniFi Protect collector calls', function (): void {
    $config = phase5CollectorConfig(['172.16.0.0/24']);
    $guard = new ScopeGuard($config);
    $decryptor = new CredentialLeaseDecryptor(collectorIdentitySecretKey());
    $sealed = sealedCollectorCredentialLease(
        ['api_key' => 'leased-protect-key'],
        [
            'site_id' => 9,
            'device_id' => 'protect:101',
            'protocol' => 'https',
            'target' => '172.16.0.1',
        ],
    );

    $client = new UniFiProtectLocalClient(fn (): array => [
        'status' => 200,
        'headers' => ['HTTP/1.1 200 OK'],
        'body' => json_encode([
            ['id' => 'cam01', 'name' => 'Gate Cam', 'state' => 'CONNECTED'],
        ], JSON_THROW_ON_ERROR),
    ]);

    $cameras = $client->listCamerasWithLease(
        $guard,
        $decryptor,
        $sealed,
        '172.16.0.1',
        'protect:101',
        '172.16.0.1',
        collectorNow(),
    );
    expect($cameras)->toHaveCount(1)
        ->and($cameras[0]['name'])->toBe('Gate Cam');

    expect(fn () => $client->listCamerasWithLease(
        $guard,
        $decryptor,
        $sealed,
        '192.168.99.1',
        'protect:101',
        '172.16.0.1',
        collectorNow(),
    ))->toThrow(ScopeViolation::class);
});

it('spools inbound Syslog and Flow UDP datagrams on the Site hub while rejecting out-of-scope senders and sites', function (): void {
    $directory = collectorTempDirectory('phase5-hub-telemetry');
    try {
        $config = phase5CollectorConfig(['127.0.0.0/8', '192.168.1.0/24'], ['icmp', 'syslog', 'flow'], siteId: 9);
        $guard = new ScopeGuard($config);
        $spool = new EncryptedSpool($directory, new CheckpointFile($directory.'/checkpoint.json'), 262_144, 50, 3600);
        $receiver = new HubTelemetryReceiver($config, $guard, $spool);

        $syslogLine = '<131>1 2026-07-23T12:00:00Z router-harbour sshd 412 - - Failed password for invalid user';
        $netflowV5Packet = pack(
            'nnNNNNCCn',
            5,
            0,
            120_000,
            1_753_272_000,
            0,
            1,
            0,
            0,
            0,
        );

        $syslogId = $receiver->spoolSyslogDatagram(9, '192.168.1.1', $syslogLine, collectorNow());
        $flowId = $receiver->spoolFlowDatagram(9, '192.168.1.1', $netflowV5Packet, collectorNow());

        expect($syslogId)->toStartWith('syslog-9-1-')
            ->and($flowId)->toStartWith('flow-9-2-');

        // Reject out-of-scope sender IP or unauthorised Site ID
        expect(fn () => $receiver->spoolSyslogDatagram(9, '10.88.0.5', $syslogLine, collectorNow()))
            ->toThrow(ScopeViolation::class);
        expect(fn () => $receiver->spoolSyslogDatagram(999, '192.168.1.1', $syslogLine, collectorNow()))
            ->toThrow(ScopeViolation::class);

        // Drain live loopback UDP socket
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        expect($server)->toBeResource();
        $boundName = stream_socket_get_name($server, false);
        $client = stream_socket_client('udp://'.$boundName);
        fwrite($client, $syslogLine);
        fclose($client);

        $drained = $receiver->drainUdpSocket($server, 'syslog', 9, 10, collectorNow());
        fclose($server);

        expect($drained['accepted'])->toBe(1)
            ->and($drained['dropped_scope'])->toBe(0);

        $batch = $spool->readBatch(10, collectorNow());
        expect($batch)->toHaveCount(3)
            ->and($batch[0]['payload']['item_type'])->toBe('syslog_event')
            ->and(base64_decode($batch[0]['payload']['datagram_base64'], true))->toBe($syslogLine)
            ->and($batch[1]['payload']['item_type'])->toBe('flow_datagram')
            ->and(base64_decode($batch[1]['payload']['packet_base64'], true))->toBe($netflowV5Packet)
            ->and($batch[2]['payload']['sender_ip'])->toBe('127.0.0.1');
    } finally {
        removeCollectorDirectory($directory);
    }
});
