<?php

use Oblivion\Collector\NetworkChecks\DelayProbe;
use Oblivion\Collector\NetworkChecks\NetworkCheckLane;
use Oblivion\Collector\NetworkChecks\UnifiNetworkReader;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;

/*
 * S&D Phase I (pop-up 97): Obli's network checks on the hub. Only Oblivion-signed checks of the
 * four read-only kinds run, only against addresses the hub's own work list already lists for that
 * device and Site, and a UniFi login is used only for exactly that console with its trusted key.
 * Nothing is changed on the network: the console is only read.
 */

const NC_PIN = 'sha256//NNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNNN=';
const NC_KEY = 'TEST-ONLY-UNIFI-READ-KEY';

/** @return array<string, mixed> */
function ncJob(string $kind, array $params, array $overrides = [], bool $sign = true): array
{
    $unifi = $kind !== 'delay';
    $details = array_replace([
        'job' => '5f0c2b1a-3d4e-4f5a-8b6c-7d8e9f0a1b2c',
        'kind' => $kind,
        'site_id' => 9,
        'device_id' => $unifi ? '31' : '42',
        'params' => $params,
        'expires_at' => '2026-07-23T12:10:00+00:00',
        'credential_lease' => $unifi ? sealedCollectorCredentialLease(
            ['api_key' => NC_KEY, 'site' => 'default'],
            ['device_id' => '31', 'protocol' => 'https', 'target' => '10.44.0.1'],
        ) : null,
    ], $overrides);
    $details['signature'] = $sign
        ? base64_encode(sodium_crypto_sign_detached(NetworkCheckLane::canonical($details), collectorSecretKey()))
        : base64_encode(str_repeat("\0", 64));

    return $details;
}

function ncScope(string $file, int $writtenAt, array $devices = ['42' => ['10.44.0.42'], '31' => ['10.44.0.1']], array $protocols = ['icmp', 'network_checks'], ?int $expiresAt = null): void
{
    file_put_contents($file, json_encode([
        'version' => 1, 'written_at' => $writtenAt, 'expires_at' => $expiresAt ?? $writtenAt + 600, 'protocols' => $protocols,
        'allowed_site_ids' => [9], 'cidrs' => ['10.44.0.42/32', '10.44.0.1/32'], 'devices' => $devices,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/**
 * @param  list<array<string, mixed>>  $responses
 * @param  list<array<string, mixed>>  $reports
 * @param  list<array<string, mixed>>  $requests  every UniFi request (method, url, headers, pin)
 * @param  list<list<string>>  $pings  every ping argv
 * @param  array<string, array{status: int, body: string}>  $routes  "METHOD /path" => answer
 */
function ncLane(array &$responses, array &$reports, array &$requests, array &$pings, array $routes, float &$now, string $scopeFile, string $pingOutput = ''): NetworkCheckLane
{
    return new NetworkCheckLane(
        function (array $report) use (&$responses, &$reports): array {
            $reports[] = $report;

            return array_shift($responses) ?? ['jobs' => []];
        },
        new EnvelopeVerifier(collectorPublicKey()),
        new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
        $scopeFile,
        new DelayProbe(function (array $argv, int $timeout) use (&$pings, $pingOutput): string {
            $pings[] = $argv;

            return $pingOutput;
        }),
        new UnifiNetworkReader(function (string $method, string $url, array $headers, ?string $body, string $pin, float $timeout, int $maxBytes) use (&$requests, $routes): array {
            $requests[] = compact('method', 'url', 'headers', 'pin');
            $answer = $routes[$method.' '.parse_url($url, PHP_URL_PATH)] ?? ['status' => 404, 'body' => ''];

            return ['status' => $answer['status'], 'headers' => [], 'body' => $answer['body'], 'error' => null];
        }),
        function () use (&$now): float {
            return $now;
        },
    );
}

/** @return list<array<string, mixed>> */
function ncResults(array $reports): array
{
    return array_merge(...array_map(static fn (array $report): array => $report['results'], $reports ?: [['results' => []]]));
}

function ncData(array $rows): array
{
    return ['status' => 200, 'body' => json_encode(['meta' => ['rc' => 'ok'], 'data' => $rows], JSON_THROW_ON_ERROR)];
}

it('measures the delay to a device the hub watches, and answers again without measuring twice', function () {
    $directory = collectorTempDirectory('network-lane-delay');
    try {
        $now = (float) collectorNow()->getTimestamp();
        ncScope($directory.'/scope.json', (int) $now);
        $job = ncJob('delay', ['address' => '10.44.0.42']);
        $responses = [['jobs' => [$job]], ['jobs' => []], ['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $requests = [];
        $pings = [];
        $output = implode("\n", [
            '64 bytes from 10.44.0.42: icmp_seq=1 ttl=64 time=3.0 ms',
            '64 bytes from 10.44.0.42: icmp_seq=2 ttl=64 time=5.0 ms',
            '64 bytes from 10.44.0.99: icmp_seq=3 ttl=64 time=1.0 ms',
            '64 bytes from 10.44.0.42: icmp_seq=4 ttl=64 time=4.0 ms',
        ]);
        $lane = ncLane($responses, $reports, $requests, $pings, [], $now, $directory.'/scope.json', $output);

        $lane->tick(); // poll: the check arrives
        $lane->tick(); // run it, send what was found
        $rows = ncResults($reports);
        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toBe(['job' => $job['job'], 'state' => 'done', 'result' => ['sent' => 10, 'received' => 3, 'min_ms' => 3.0, 'median_ms' => 4.0, 'max_ms' => 5.0]])
            ->and($pings)->toHaveCount(1)
            ->and(in_array('10.44.0.42', $pings[0], true))->toBe(true)
            ->and($requests)->toHaveCount(0);

        // The server didn't get the answer and asks again: the same answer, nothing measured.
        $now += 6;
        $lane->tick();
        $now += 6;
        $lane->tick();
        $rows = ncResults($reports);
        expect($rows)->toHaveCount(2)
            ->and($rows[1])->toBe($rows[0])
            ->and($pings)->toHaveCount(1);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('runs nothing Oblivion didn’t sign, and says nothing about it', function () {
    $directory = collectorTempDirectory('network-lane-unsigned');
    try {
        $now = (float) collectorNow()->getTimestamp();
        ncScope($directory.'/scope.json', (int) $now);
        $unsigned = ncJob('delay', ['address' => '10.44.0.42'], sign: false);
        $extra = ncJob('delay', ['address' => '10.44.0.42']);
        $extra['command'] = 'reboot';
        $responses = [['jobs' => [$unsigned, $extra]], ['jobs' => []]];
        $reports = [];
        $requests = [];
        $pings = [];
        $lane = ncLane($responses, $reports, $requests, $pings, [], $now, $directory.'/scope.json');

        $lane->tick();
        $now += 6;
        $lane->tick();
        expect(ncResults($reports))->toHaveCount(0)
            ->and($pings)->toHaveCount(0);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('refuses a kind that isn’t on its list, even when signed', function () {
    $directory = collectorTempDirectory('network-lane-kind');
    try {
        $now = (float) collectorNow()->getTimestamp();
        ncScope($directory.'/scope.json', (int) $now);
        $job = ncJob('restart_switch', ['address' => '10.44.0.42']);
        $responses = [['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $requests = [];
        $pings = [];
        $lane = ncLane($responses, $reports, $requests, $pings, [], $now, $directory.'/scope.json');

        $lane->tick();
        $lane->tick();
        expect(ncResults($reports))->toBe([['job' => $job['job'], 'state' => 'not_available', 'reason' => 'refused']])
            ->and($pings)->toHaveCount(0)
            ->and($requests)->toHaveCount(0);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('won’t contact an address its own work list doesn’t list for that device, or when the list is stale', function () {
    $directory = collectorTempDirectory('network-lane-scope');
    try {
        $now = (float) collectorNow()->getTimestamp();
        // Listed, but for another device.
        ncScope($directory.'/scope.json', (int) $now, ['42' => ['10.44.0.1'], '31' => ['10.44.0.42']]);
        $job = ncJob('delay', ['address' => '10.44.0.42']);
        $responses = [['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $requests = [];
        $pings = [];
        $lane = ncLane($responses, $reports, $requests, $pings, [], $now, $directory.'/scope.json');
        $lane->tick();
        $lane->tick();
        expect(ncResults($reports))->toBe([['job' => $job['job'], 'state' => 'not_available', 'reason' => 'out_of_scope']])
            ->and($pings)->toHaveCount(0);

        // Listed properly, but written two hours ago.
        ncScope($directory.'/stale.json', (int) $now - 7200);
        $responses = [['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $lane = ncLane($responses, $reports, $requests, $pings, [], $now, $directory.'/stale.json');
        $lane->tick();
        $lane->tick();
        expect(ncResults($reports))->toBe([['job' => $job['job'], 'state' => 'not_available', 'reason' => 'out_of_scope']])
            ->and($pings)->toHaveCount(0);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('reads a Wi-Fi point from the UniFi console with GETs only, pinned, and counts weak signals and drop-outs', function () {
    $directory = collectorTempDirectory('network-lane-wifi');
    try {
        $now = (float) collectorNow()->getTimestamp();
        ncScope($directory.'/scope.json', (int) $now);
        $job = ncJob('wifi', ['console' => '10.44.0.1', 'port' => 443, 'trusted_pin' => NC_PIN, 'address' => '10.44.0.21', 'mac' => '', 'hours' => 72]);
        $responses = [['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $requests = [];
        $pings = [];
        $ms = (int) $now * 1000;
        $lane = ncLane($responses, $reports, $requests, $pings, [
            'GET /proxy/network/api/s/default/stat/device' => ncData([
                ['ip' => '10.44.0.21', 'mac' => 'AA:BB:CC:00:00:21', 'satisfaction' => 81, 'radio_table_stats' => [
                    ['radio' => 'ng', 'num_sta' => 2, 'channel' => 6, 'cu_total' => 64],
                    ['radio' => 'na', 'num_sta' => 1, 'channel' => 44, 'cu_total' => 12],
                ]],
            ]),
            'GET /proxy/network/api/s/default/stat/sta' => ncData([
                ['ap_mac' => 'aa:bb:cc:00:00:21', 'is_wired' => false, 'signal' => -80],
                ['ap_mac' => 'aa:bb:cc:00:00:21', 'is_wired' => false, 'signal' => -60],
                ['ap_mac' => 'aa:bb:cc:00:00:21', 'is_wired' => false, 'signal' => -77],
                ['ap_mac' => 'aa:bb:cc:00:00:99', 'is_wired' => false, 'signal' => -90],
                ['sw_mac' => 'aa:bb:cc:00:00:01', 'is_wired' => true],
            ]),
            'GET /proxy/network/api/s/default/stat/event' => ncData([
                ['key' => 'EVT_WU_Disconnected', 'ap' => 'aa:bb:cc:00:00:21', 'time' => $ms - 3_600_000],
                ['key' => 'EVT_WU_Disconnected', 'ap' => 'aa:bb:cc:00:00:21', 'time' => $ms - 7_200_000],
                ['key' => 'EVT_WU_Disconnected', 'ap' => 'aa:bb:cc:00:00:99', 'time' => $ms - 3_600_000],
                ['key' => 'EVT_WU_Disconnected', 'ap' => 'aa:bb:cc:00:00:21', 'time' => $ms - 100 * 3_600_000],
                ['key' => 'EVT_WU_Roam', 'ap' => 'aa:bb:cc:00:00:21', 'time' => $ms - 600_000],
            ]),
        ], $now, $directory.'/scope.json');

        $lane->tick();
        $lane->tick();
        $rows = ncResults($reports);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['state'])->toBe('done')
            ->and($rows[0]['result']['clients'])->toBe(3)
            ->and($rows[0]['result']['weak'])->toBe(2)
            ->and($rows[0]['result']['signal_avg'])->toBe(-72)
            ->and($rows[0]['result']['history'])->toBe(true)
            ->and($rows[0]['result']['drops'])->toBe(2)
            ->and(array_sum($rows[0]['result']['drop_hours']))->toBe(2)
            ->and($rows[0]['result']['radios'][0])->toBe(['band' => '2.4', 'clients' => 2, 'channel' => 6, 'busy' => 64])
            ->and(array_unique(array_column($requests, 'method')))->toBe(['GET'])
            ->and(array_unique(array_column($requests, 'pin')))->toBe([NC_PIN])
            ->and($requests[0]['headers']['X-API-KEY'])->toBe(NC_KEY)
            ->and($requests[0]['url'])->toBe('https://10.44.0.1/proxy/network/api/s/default/stat/device');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('says drop-outs aren’t available when the console keeps no history, rather than "none"', function () {
    $directory = collectorTempDirectory('network-lane-history');
    try {
        $now = (float) collectorNow()->getTimestamp();
        ncScope($directory.'/scope.json', (int) $now);
        $job = ncJob('wifi', ['console' => '10.44.0.1', 'port' => 443, 'trusted_pin' => NC_PIN, 'address' => '', 'mac' => 'aa:bb:cc:00:00:21', 'hours' => 72]);
        $responses = [['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $requests = [];
        $pings = [];
        $lane = ncLane($responses, $reports, $requests, $pings, [
            'GET /proxy/network/api/s/default/stat/device' => ncData([['mac' => 'aa:bb:cc:00:00:21']]),
            'GET /proxy/network/api/s/default/stat/sta' => ncData([]),
            'GET /proxy/network/api/s/default/stat/event' => ncData([]),
        ], $now, $directory.'/scope.json');

        $lane->tick();
        $lane->tick();
        $rows = ncResults($reports);
        expect($rows[0]['result']['clients'])->toBe(0)
            ->and($rows[0]['result']['history'])->toBe(false)
            ->and($rows[0]['result']['drops'])->toBeNull();
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('never sends a UniFi login sealed for another console', function () {
    $directory = collectorTempDirectory('network-lane-lease');
    try {
        $now = (float) collectorNow()->getTimestamp();
        ncScope($directory.'/scope.json', (int) $now);
        $job = ncJob('gateway_speed', ['console' => '10.44.0.1', 'port' => 443, 'trusted_pin' => NC_PIN], [
            'credential_lease' => sealedCollectorCredentialLease(['api_key' => NC_KEY], ['device_id' => '31', 'protocol' => 'https', 'target' => '10.44.0.77']),
        ]);
        $responses = [['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $requests = [];
        $pings = [];
        $lane = ncLane($responses, $reports, $requests, $pings, [], $now, $directory.'/scope.json');

        $lane->tick();
        $lane->tick();
        expect(ncResults($reports))->toBe([['job' => $job['job'], 'state' => 'not_available', 'reason' => 'no_login']])
            ->and($requests)->toHaveCount(0);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('reports the gateway’s own speed test, and says so when it hasn’t run one', function () {
    $directory = collectorTempDirectory('network-lane-speed');
    try {
        $now = (float) collectorNow()->getTimestamp();
        ncScope($directory.'/scope.json', (int) $now);
        $job = ncJob('gateway_speed', ['console' => '10.44.0.1', 'port' => 443, 'trusted_pin' => NC_PIN]);
        $second = ncJob('gateway_speed', ['console' => '10.44.0.1', 'port' => 443, 'trusted_pin' => NC_PIN], ['job' => '6a1d3c2b-4e5f-4a6b-9c7d-8e9f0a1b2c3d']);
        $responses = [['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $requests = [];
        $pings = [];
        $lane = ncLane($responses, $reports, $requests, $pings, [
            'GET /proxy/network/api/s/default/stat/health' => ncData([
                ['subsystem' => 'www', 'xput_down' => 474.2, 'xput_up' => 97.1, 'speedtest_ping' => 6, 'speedtest_lastrun' => 1784800000],
                ['subsystem' => 'wan', 'isp_name' => 'One NZ'],
            ]),
        ], $now, $directory.'/scope.json');
        $lane->tick();
        $lane->tick();
        expect(ncResults($reports)[0]['result'])->toBe(['down_mbps' => 474.2, 'up_mbps' => 97.1, 'latency_ms' => 6.0, 'tested_at' => 1784800000, 'isp' => 'One NZ']);

        $responses = [['jobs' => [$second]], ['jobs' => []]];
        $reports = [];
        $lane = ncLane($responses, $reports, $requests, $pings, [
            'GET /proxy/network/api/s/default/stat/health' => ncData([['subsystem' => 'www', 'latency' => 12]]),
        ], $now, $directory.'/scope.json');
        $lane->tick();
        $lane->tick();
        expect(ncResults($reports))->toBe([['job' => $second['job'], 'state' => 'not_available', 'reason' => 'no_speed_test']]);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('counts only replies that really came from the address', function () {
    $parsed = DelayProbe::parse('10.0.0.5', implode("\n", [
        'Reply from 10.0.0.5: bytes=32 time=14ms TTL=64',
        'Reply from 10.0.0.9: bytes=32 time=1ms TTL=64',
        'Reply from 10.0.0.5: bytes=32 time<1ms TTL=64',
        'Request timed out.',
    ]));

    // "time<1ms" is the bound ping gives (1 ms), never a smaller number nobody measured.
    expect($parsed)->toBe(['sent' => 10, 'received' => 2, 'min_ms' => 1.0, 'median_ms' => 7.5, 'max_ms' => 14.0, 'measured' => true]);
});

it('says nothing was measured when ping didn’t run, never that every reply was lost', function () {
    expect(DelayProbe::parse('10.0.0.5', '')['measured'])->toBe(false)
        ->and(DelayProbe::parse('10.0.0.5', "ping: socket: Operation not permitted\n")['measured'])->toBe(false)
        ->and(DelayProbe::parse('10.0.0.5', "--- 10.0.0.5 ping statistics ---\n10 packets transmitted, 0 received, 100% packet loss, time 9011ms\n")['measured'])->toBe(true)
        ->and(DelayProbe::parse('10.0.0.5', "Ping statistics for 10.0.0.5:\n    Packets: Sent = 10, Received = 0, Lost = 10 (100% loss),\n")['measured'])->toBe(true);
});

it('runs no checks unless its signed work allows network checks and hasn’t expired', function () {
    foreach ([['protocols' => ['icmp'], 'expires' => null], ['protocols' => ['icmp', 'network_checks'], 'expires' => -60]] as $case) {
        $directory = collectorTempDirectory('network-lane-revoked');
        try {
            $now = (float) collectorNow()->getTimestamp();
            ncScope($directory.'/scope.json', (int) $now, protocols: $case['protocols'], expiresAt: $case['expires'] === null ? null : (int) $now + $case['expires']);
            $job = ncJob('delay', ['address' => '10.44.0.42']);
            $responses = [['jobs' => [$job]], ['jobs' => []]];
            $reports = [];
            $requests = [];
            $pings = [];
            $lane = ncLane($responses, $reports, $requests, $pings, [], $now, $directory.'/scope.json');
            $lane->tick();
            $lane->tick();
            expect(ncResults($reports))->toBe([['job' => $job['job'], 'state' => 'not_available', 'reason' => 'out_of_scope']])
                ->and($pings)->toHaveCount(0);
        } finally {
            removeCollectorDirectory($directory);
        }
    }
});
