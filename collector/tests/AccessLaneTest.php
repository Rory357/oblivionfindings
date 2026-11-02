<?php

use Oblivion\Collector\Access\AccessLane;
use Oblivion\Collector\Access\UnifiAccessAdmin;
use Oblivion\Collector\Runtime\OnvifCameraNetwork;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;

/*
 * S&D Phase H (H1): UniFi Access changes on the hub. Only Oblivion-signed changes run, only to a
 * private console with a trusted key and a token sealed for it; a change is never run twice; a
 * card enrolment waits for its tap without stalling the relay. No door is ever unlocked here.
 */

const LANE_PIN = 'sha256//HHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHH=';
const LANE_TOKEN = 'TEST-ONLY-ACCESS-ADMIN-TOKEN';
const LANE_CARD = 'TEST-ONLY-NEW-CARD-TOKEN-7';

final class LaneFixtureNetwork implements OnvifCameraNetwork
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, pin: ?string}> */
    public array $requests = [];

    /** @param array<string, list<array{status: int, body: string}>> $routes "METHOD /path" => answers in turn (the last repeats) */
    public function __construct(public array $routes = []) {}

    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array
    {
        return null;
    }

    public function request(string $method, string $url, array $headers, ?string $body, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'pin');
        $key = $method.' '.parse_url($url, PHP_URL_PATH);
        $answers = $this->routes[$key] ?? [['status' => 0, 'body' => '']];
        $answer = count($answers) > 1 ? array_shift($this->routes[$key]) : $answers[0];

        return ['status' => $answer['status'], 'body' => $answer['body'], 'truncated' => false, 'challenge' => ''];
    }
}

function laneOk(mixed $data = []): array
{
    return ['status' => 200, 'body' => json_encode(['code' => 'SUCCESS', 'msg' => 'success', 'data' => $data], JSON_THROW_ON_ERROR)];
}

/** @return array<string, mixed> */
function laneJob(string $kind, array $params, array $overrides = [], bool $sign = true): array
{
    $details = array_replace([
        'job' => '0b9c8d7e-6f5a-4b3c-9d2e-1f0a9b8c7d6e',
        'kind' => $kind,
        'params' => $params,
        'console_id' => '7',
        'site_id' => 9,
        'address' => '10.44.0.60',
        'port' => 12445,
        'trusted_pin' => LANE_PIN,
        'expires_at' => '2026-07-23T12:10:00+00:00',
        'credential_lease' => sealedCollectorCredentialLease(
            ['api_token' => LANE_TOKEN],
            ['device_id' => 'access-console-7', 'protocol' => 'access.unifi', 'target' => '10.44.0.60'],
        ),
    ], $overrides);
    $details['signature'] = $sign
        ? base64_encode(sodium_crypto_sign_detached(AccessLane::canonical($details), collectorSecretKey()))
        : base64_encode(str_repeat("\0", 64));

    return $details;
}

/**
 * @param  list<array<string, mixed>>  $responses  what the server answers each poll
 * @param  list<array<string, mixed>>  $reports  what the hub sent each poll
 */
function laneUnderTest(array &$responses, array &$reports, LaneFixtureNetwork $network, float &$now, string $journal): AccessLane
{
    return new AccessLane(
        function (array $report) use (&$responses, &$reports): array {
            $reports[] = $report;

            return array_shift($responses) ?? ['jobs' => []];
        },
        new EnvelopeVerifier(collectorPublicKey()),
        new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
        $journal,
        new UnifiAccessAdmin($network),
        function () use (&$now): float {
            return $now;
        },
    );
}

/** @return list<array<string, mixed>> every result row the hub reported */
function laneResults(array $reports): array
{
    return array_merge(...array_map(static fn (array $report): array => $report['results'], $reports ?: [['results' => []]]));
}

it('turns a person off, reads it back, and never runs the same change twice', function () {
    $directory = collectorTempDirectory('access-lane');
    try {
        $now = (float) collectorNow()->getTimestamp();
        $job = laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'deactivated']);
        $responses = [['jobs' => [$job]], ['jobs' => []], ['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $network = new LaneFixtureNetwork([
            'PUT /api/v1/developer/users/user-kiri' => [laneOk()],
            'GET /api/v1/developer/users/user-kiri' => [laneOk(['id' => 'user-kiri', 'status' => 'DEACTIVATED'])],
        ]);
        $lane = laneUnderTest($responses, $reports, $network, $now, $directory.'/access-jobs.json');

        $lane->tick();
        $lane->tick();
        expect(laneResults($reports))->toBe([['job' => $job['job'], 'state' => 'succeeded', 'result' => ['status' => 'deactivated']]])
            ->and($network->requests)->toHaveCount(2)
            ->and($network->requests[0]['body'])->toBe('{"status":"DEACTIVATED"}')
            ->and($network->requests[0]['pin'])->toBe(LANE_PIN)
            ->and($network->requests[0]['headers']['Authorization'])->toBe('Bearer '.LANE_TOKEN);

        // The server didn't get the answer and asks again: the same answer, nothing run.
        $now += 6;
        $lane->tick();
        $now += 6;
        $lane->tick();
        $rows = laneResults($reports);
        expect($rows)->toHaveCount(2)
            ->and($rows[1])->toBe($rows[0])
            ->and($network->requests)->toHaveCount(2);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('runs nothing that Oblivion didn’t sign, and says nothing about it', function (array $job) {
    $directory = collectorTempDirectory('access-lane-unsigned');
    try {
        $now = (float) collectorNow()->getTimestamp();
        $responses = [['jobs' => [$job]], ['jobs' => []]];
        $reports = [];
        $network = new LaneFixtureNetwork;
        $lane = laneUnderTest($responses, $reports, $network, $now, $directory.'/access-jobs.json');
        $lane->tick();
        $now += 6;
        $lane->tick();

        expect($network->requests)->toHaveCount(0)
            ->and(laneResults($reports))->toBe([]);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'unsigned' => [laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'active'], sign: false)],
    'changed after signing' => [array_replace(laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'deactivated']), ['params' => ['user_id' => 'user-boss', 'status' => 'active']])],
]);

it('refuses a signed change it can’t run safely, without touching the console', function (array $job, string $reason) {
    $directory = collectorTempDirectory('access-lane-refused');
    try {
        $now = (float) collectorNow()->getTimestamp();
        $responses = [['jobs' => [$job]]];
        $reports = [];
        $network = new LaneFixtureNetwork;
        $lane = laneUnderTest($responses, $reports, $network, $now, $directory.'/access-jobs.json');
        $lane->tick();
        $lane->tick();

        $rows = laneResults($reports);
        expect($network->requests)->toHaveCount(0)
            ->and($rows)->toHaveCount(1)
            ->and($rows[0]['state'])->toBe('failed')
            ->and($rows[0]['reason'])->toContain($reason);
    } finally {
        removeCollectorDirectory($directory);
    }
})->with([
    'expired' => [laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'active'], ['expires_at' => '2026-07-23T11:59:00+00:00']), 'too late'],
    'a door unlock' => [laneJob('door_unlock', ['door_id' => 'door-front']), 'kind of change'],
    'an extra detail' => [laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'active', 'force' => true]), 'kind of change'],
    'a public address' => [laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'active'], ['address' => '8.8.8.8']), 'reach safely'],
    'no trusted key' => [laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'active'], ['trusted_pin' => null]), 'reach safely'],
    'a lease for another console' => [laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'active'], ['credential_lease' => sealedCollectorCredentialLease(['api_token' => LANE_TOKEN], ['device_id' => 'access-console-8', 'protocol' => 'access.unifi', 'target' => '10.44.0.60'])]), 'reach safely'],
]);

it('waits for a card to be tapped, adds it, and sends up only its hash', function () {
    $directory = collectorTempDirectory('access-lane-card');
    try {
        $now = (float) collectorNow()->getTimestamp();
        $job = laneJob('card_enrol', ['user_id' => 'user-ana', 'reader_id' => 'reader-front']);
        $responses = [['jobs' => [$job]]];
        $reports = [];
        $network = new LaneFixtureNetwork([
            'POST /api/v1/developer/credentials/nfc_cards/sessions' => [laneOk(['session_id' => 'session-1'])],
            'GET /api/v1/developer/credentials/nfc_cards/sessions/session-1' => [laneOk(['status' => 'waiting']), laneOk(['token' => LANE_CARD, 'card_id' => '100245'])],
            'PUT /api/v1/developer/users/user-ana/nfc_cards' => [laneOk()],
        ]);
        $lane = laneUnderTest($responses, $reports, $network, $now, $directory.'/access-jobs.json');

        $lane->tick();
        expect($lane->active())->toBe(true)
            ->and(laneResults($reports))->toBe([]);
        $now += 2;
        $lane->tick(); // no tap yet
        $now += 2;
        $lane->tick(); // tapped: added, and reported straight away
        $now += 1;
        $lane->tick();

        $rows = laneResults($reports);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['state'])->toBe('succeeded')
            ->and($rows[0]['result'])->toBe(['token_hash' => hash('sha256', LANE_CARD), 'display_id' => '100245'])
            ->and(json_encode($reports))->not->toContain(LANE_CARD)
            ->and($network->requests[count($network->requests) - 1]['body'])->toBe('{"token":"'.LANE_CARD.'","force_add":false}');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('gives up on a card nobody taps within a minute, and closes the reader’s session', function () {
    $directory = collectorTempDirectory('access-lane-card-timeout');
    try {
        $now = (float) collectorNow()->getTimestamp();
        $responses = [['jobs' => [laneJob('card_enrol', ['user_id' => 'user-ana', 'reader_id' => 'reader-front'])]]];
        $reports = [];
        $network = new LaneFixtureNetwork([
            'POST /api/v1/developer/credentials/nfc_cards/sessions' => [laneOk(['session_id' => 'session-2'])],
            'GET /api/v1/developer/credentials/nfc_cards/sessions/session-2' => [laneOk(['status' => 'waiting'])],
            'DELETE /api/v1/developer/credentials/nfc_cards/sessions/session-2' => [laneOk()],
        ]);
        $lane = laneUnderTest($responses, $reports, $network, $now, $directory.'/access-jobs.json');
        $lane->tick();
        for ($i = 0; $i < 32; $i++) {
            $now += 2;
            $lane->tick();
        }
        $now += 1;
        $lane->tick();

        $rows = laneResults($reports);
        $methods = array_column($network->requests, 'method');
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['state'])->toBe('failed')
            ->and($rows[0]['reason'])->toContain('No card was tapped')
            ->and(in_array('DELETE', $methods, true))->toBe(true)
            ->and(in_array('PUT', $methods, true))->toBe(false);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('says “uncertain”, never “failed”, when a change was sent but not answered', function () {
    $directory = collectorTempDirectory('access-lane-uncertain');
    try {
        $now = (float) collectorNow()->getTimestamp();
        $responses = [['jobs' => [laneJob('visitor_create', ['first_name' => 'Plumber', 'last_name' => '', 'starts_at' => 1_784_800_000, 'ends_at' => 1_784_810_000, 'door_ids' => ['door-front']])]]];
        $reports = [];
        $lane = laneUnderTest($responses, $reports, new LaneFixtureNetwork, $now, $directory.'/access-jobs.json');
        $lane->tick();
        $lane->tick();

        $rows = laneResults($reports);
        expect($rows[0]['state'])->toBe('uncertain')
            ->and($rows[0]['reason'])->toContain('Check UniFi Access');
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('reports a change cut off by a restart as uncertain, once, and doesn’t repeat it', function () {
    $directory = collectorTempDirectory('access-lane-restart');
    try {
        file_put_contents($directory.'/access-jobs.json', json_encode(['0b9c8d7e-6f5a-4b3c-9d2e-1f0a9b8c7d6e' => ['state' => 'started', 'at' => time()]]));
        $now = (float) collectorNow()->getTimestamp();
        $responses = [['jobs' => [laneJob('user_status', ['user_id' => 'user-kiri', 'status' => 'deactivated'])]], ['jobs' => []]];
        $reports = [];
        $network = new LaneFixtureNetwork;
        $lane = laneUnderTest($responses, $reports, $network, $now, $directory.'/access-jobs.json');
        $lane->tick();
        $now += 6;
        $lane->tick();

        $rows = laneResults($reports);
        expect($rows[0]['state'])->toBe('uncertain')
            ->and($rows[0]['reason'])->toContain('restarted')
            ->and($network->requests)->toHaveCount(0);
    } finally {
        removeCollectorDirectory($directory);
    }
});
