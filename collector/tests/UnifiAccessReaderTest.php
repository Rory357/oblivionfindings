<?php

use Oblivion\Collector\Access\UnifiAccessLane;
use Oblivion\Collector\Access\UnifiAccessReader;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Runtime\OnvifCameraNetwork;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;

/*
 * S&D Phase H (H0): the hub reads a Site's UniFi Access console. Read only. The token goes only to
 * the trusted key; with none, the hub reads the certificate and sends nothing else. Card tokens
 * never leave the hub, only their hash.
 */

const ACCESS_HUB_PIN = 'sha256//EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE=';
const ACCESS_HUB_OTHER_PIN = 'sha256//FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF=';
const ACCESS_HUB_TOKEN = 'TEST-ONLY-UNIFI-ACCESS-TOKEN';
const ACCESS_CARD_TOKEN = 'TEST-ONLY-CARD-SECRET-TOKEN-0001';

final class AccessFixtureNetwork implements OnvifCameraNetwork
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, pin: ?string}> */
    public array $requests = [];

    public int $inspected = 0;

    /** @param array<string, array{status: int, body: string}> $routes path (no query) => answer */
    public function __construct(public array $routes = [], public ?string $certificatePin = ACCESS_HUB_PIN) {}

    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array
    {
        $this->inspected++;

        return $this->certificatePin === null ? null : ['pin' => $this->certificatePin, 'fingerprint' => str_repeat('AB:', 31).'AB', 'subject' => 'UniFi Access', 'valid_to' => 1_900_000_000, 'publicly_trusted' => false];
    }

    public function request(string $method, string $url, array $headers, ?string $body, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'pin');
        $path = (string) parse_url($url, PHP_URL_PATH);
        $answer = $this->routes[$path] ?? ['status' => 0, 'body' => ''];

        return ['status' => $answer['status'], 'body' => $answer['body'], 'truncated' => false, 'challenge' => ''];
    }
}

/** @return array<string, mixed> */
function accessConsoleFixture(array $overrides = []): array
{
    return array_replace([
        'console_id' => '7',
        'site_id' => 9,
        'address' => '10.44.0.60',
        'port' => 12445,
        'trusted_pin' => ACCESS_HUB_PIN,
        'interval_seconds' => 300,
        'events_since' => '2026-07-23T11:00:00+00:00',
        'credential_lease' => sealedCollectorCredentialLease(
            ['api_token' => ACCESS_HUB_TOKEN],
            ['device_id' => 'access-console-7', 'protocol' => 'access.unifi', 'target' => '10.44.0.60'],
        ),
    ], $overrides);
}

function accessOk(mixed $data, ?int $total = null): array
{
    $payload = ['code' => 'SUCCESS', 'msg' => 'success', 'data' => $data];
    if ($total !== null) {
        $payload['pagination'] = ['page_num' => 1, 'page_size' => 100, 'total' => $total];
    }

    return ['status' => 200, 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
}

/** @return array<string, array{status: int, body: string}> */
function accessRoutes(array $hits = []): array
{
    return [
        '/api/v1/developer/doors' => accessOk([
            ['id' => 'door-front', 'name' => 'Front', 'full_name' => 'Front door', 'door_lock_relay_status' => 'lock', 'door_position_status' => 'close', 'is_bind_hub' => true],
            ['id' => 'door-office', 'name' => 'Office', 'door_lock_relay_status' => 'unlock', 'door_position_status' => 'open', 'is_bind_hub' => false],
        ]),
        '/api/v1/developer/users' => accessOk([
            ['id' => 'user-ana', 'first_name' => 'Ana', 'last_name' => 'Tane', 'employee_number' => 'E104', 'status' => 'ACTIVE', 'access_policy_ids' => ['policy-staff'],
                'nfc_cards' => [['id' => '100001', 'token' => ACCESS_CARD_TOKEN, 'type' => 'common']]],
            ['id' => 'user-leaver', 'first_name' => 'Old', 'last_name' => 'Badge', 'status' => 'DEACTIVATED', 'access_policies' => [['id' => 'policy-staff']]],
        ], 2),
        '/api/v1/developer/visitors' => accessOk([
            ['id' => 'visitor-1', 'first_name' => 'Plumber', 'last_name' => '', 'status' => 'UPCOMING', 'start_time' => 1_784_800_000, 'end_time' => 1_784_810_000],
        ], 1),
        '/api/v1/developer/access_policies' => accessOk([
            ['id' => 'policy-staff', 'name' => 'Staff', 'resources' => [['id' => 'door-front', 'type' => 'door'], ['id' => 'group-1', 'type' => 'door_group']], 'schedule_id' => 'sched-1'],
        ]),
        '/api/v1/developer/system/logs' => accessOk(['hits' => $hits], count($hits)),
        '/api/v1/developer/devices' => accessOk([[
            ['id' => 'reader-front', 'alias' => 'Front reader', 'type' => 'UA-G2-PRO', 'location_id' => 'door-front'],
            ['id' => 'hub-1', 'name' => 'Hub', 'type' => 'UAH', 'location_id' => 'somewhere-else'],
        ]]),
        '/api/v1/developer/doors/settings/emergency' => accessOk(['lockdown' => false, 'evacuation' => false]),
    ];
}

function accessHit(string $id, string $at, string $result = 'ACCESS', string $provider = 'NFC', string $type = 'access.door.unlock'): array
{
    return ['_id' => $id, '@timestamp' => $at, '_source' => [
        'actor' => ['id' => 'user-ana', 'display_name' => 'Ana Tane', 'type' => 'user'],
        'event' => ['type' => $type, 'result' => $result],
        'authentication' => ['credential_provider' => $provider],
        'target' => [['type' => 'door', 'id' => 'door-front', 'display_name' => 'Front door']],
    ]];
}

function accessReader(AccessFixtureNetwork $network): UnifiAccessReader
{
    return new UnifiAccessReader(new CredentialLeaseDecryptor(collectorIdentitySecretKey()), $network);
}

it('reads nothing and sends no token until the console’s key is trusted', function () {
    $network = new AccessFixtureNetwork(accessRoutes());
    $snapshot = accessReader($network)->read(accessConsoleFixture(['trusted_pin' => null]), '2026-07-23T11:00:00+00:00', collectorNow());

    expect($snapshot['state'])->toBe('untrusted')
        ->and($snapshot['certificate']['pin'])->toBe(ACCESS_HUB_PIN)
        ->and($network->requests)->toHaveCount(0)
        ->and(json_encode($snapshot))->not->toContain(ACCESS_HUB_TOKEN);
});

it('reads doors, people, cards, visitors, policies and door events with the trusted key', function () {
    $network = new AccessFixtureNetwork(accessRoutes([
        accessHit('evt-1', '2026-07-23T11:10:00Z'),
        accessHit('evt-2', '2026-07-23T11:20:00Z', 'BLOCKED', 'PIN_CODE'),
        accessHit('evt-3', '2026-07-23T11:30:00Z', 'ACCESS', 'REMOTE_THROUGH_UAH'),
    ]));
    $snapshot = accessReader($network)->read(accessConsoleFixture(), '2026-07-23T11:00:00+00:00', collectorNow());
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

    expect($snapshot['state'])->toBe('ok')
        ->and($snapshot['item_type'])->toBe('unifi_access_snapshot')
        ->and($snapshot['complete'])->toBe(true)
        ->and($snapshot['doors'][0])->toBe(['id' => 'door-front', 'name' => 'Front door', 'lock' => 'locked', 'position' => 'closed', 'hub_bound' => true])
        ->and($snapshot['doors'][1]['lock'])->toBe('unlocked')
        ->and($snapshot['people'][0]['policy_ids'])->toBe(['policy-staff'])
        ->and($snapshot['people'][0]['cards'])->toBe([['token_hash' => hash('sha256', ACCESS_CARD_TOKEN), 'display_id' => '100001']])
        ->and($snapshot['people'][1]['status'])->toBe('deactivated')
        ->and($snapshot['people'][1]['policy_ids'])->toBe(['policy-staff'])
        ->and($snapshot['visitors'][0]['name'])->toBe('Plumber')
        ->and($snapshot['policies'][0]['door_ids'])->toBe(['door-front'])
        ->and($snapshot['events'])->toHaveCount(3)
        ->and($snapshot['events'][0]['kind'])->toBe('opened')
        ->and($snapshot['events'][0]['method'])->toBe('card')
        ->and($snapshot['events'][1]['kind'])->toBe('denied')
        ->and($snapshot['events'][1]['method'])->toBe('pin')
        ->and($snapshot['events'][2]['kind'])->toBe('unlocked')
        ->and($snapshot['events'][2]['method'])->toBe('remote')
        ->and($snapshot['events_until'])->toBe('2026-07-23T12:00:00+00:00')
        ->and($snapshot['readers'])->toBe([['id' => 'reader-front', 'name' => 'Front reader', 'type' => 'UA-G2-PRO', 'door_id' => 'door-front']])
        ->and($snapshot['emergency'])->toBe('normal')
        // The card's secret token and the API token never go up.
        ->and($encoded)->not->toContain(ACCESS_CARD_TOKEN)
        ->and($encoded)->not->toContain(ACCESS_HUB_TOKEN);

    foreach ($network->requests as $request) {
        expect($request['pin'])->toBe(ACCESS_HUB_PIN)
            ->and($request['headers']['Authorization'])->toBe('Bearer '.ACCESS_HUB_TOKEN)
            ->and(str_starts_with($request['url'], 'https://10.44.0.60:12445/api/v1/developer/'))->toBe(true);
    }
    // Only reads: GETs, and the one log search (a POST that changes nothing).
    $posts = array_values(array_filter($network->requests, static fn (array $request): bool => $request['method'] !== 'GET'));
    expect($posts)->toHaveCount(1)
        ->and(parse_url($posts[0]['url'], PHP_URL_PATH))->toBe('/api/v1/developer/system/logs');
});

it('still syncs an older console that has no device or emergency read', function () {
    $routes = accessRoutes();
    $routes['/api/v1/developer/devices'] = ['status' => 404, 'body' => '{}'];
    $routes['/api/v1/developer/doors/settings/emergency'] = ['status' => 404, 'body' => '{}'];
    $snapshot = accessReader(new AccessFixtureNetwork($routes))->read(accessConsoleFixture(), '2026-07-23T11:00:00+00:00', collectorNow());

    expect($snapshot['state'])->toBe('ok')
        ->and($snapshot['readers'])->toBe([])
        ->and($snapshot['emergency'])->toBeNull();
});

it('says when UniFi Access refuses the token, and stops there', function () {
    $routes = accessRoutes();
    $routes['/api/v1/developer/doors'] = ['status' => 401, 'body' => '{}'];
    $network = new AccessFixtureNetwork($routes);
    $snapshot = accessReader($network)->read(accessConsoleFixture(), '2026-07-23T11:00:00+00:00', collectorNow());

    expect($snapshot['state'])->toBe('failed')
        ->and($snapshot['reason'])->toContain('refused the token')
        ->and($network->requests)->toHaveCount(1);
});

it('reports a changed certificate, and an unreachable console, without guessing', function () {
    $changed = new AccessFixtureNetwork([], ACCESS_HUB_OTHER_PIN);
    $snapshot = accessReader($changed)->read(accessConsoleFixture(), '2026-07-23T11:00:00+00:00', collectorNow());
    expect($snapshot['state'])->toBe('untrusted')
        ->and($snapshot['reason'])->toContain('changed')
        ->and($snapshot['certificate']['pin'])->toBe(ACCESS_HUB_OTHER_PIN);

    $away = new AccessFixtureNetwork([], ACCESS_HUB_PIN);
    $snapshot = accessReader($away)->read(accessConsoleFixture(), '2026-07-23T11:00:00+00:00', collectorNow());
    expect($snapshot['state'])->toBe('failed')
        ->and($snapshot['reason'])->toContain('couldn’t reach');
});

it('refuses a lease that isn’t for this console', function () {
    $network = new AccessFixtureNetwork(accessRoutes());
    $console = accessConsoleFixture(['credential_lease' => sealedCollectorCredentialLease(
        ['api_token' => ACCESS_HUB_TOKEN],
        ['device_id' => 'access-console-8', 'protocol' => 'access.unifi', 'target' => '10.44.0.60'],
    )]);
    $console['credential_lease']['device_id'] = 'access-console-7';
    $snapshot = accessReader($network)->read($console, '2026-07-23T11:00:00+00:00', collectorNow());

    expect($snapshot['state'])->toBe('failed')
        ->and($network->requests)->toHaveCount(0);
});

it('keeps a snapshot under the size limit by leaving the newest events for next time', function () {
    $hits = [];
    $start = (new DateTimeImmutable('2026-07-22T12:00:00Z'))->getTimestamp();
    for ($i = 0; $i < 10; $i++) {
        $hit = accessHit('evt-'.$i, gmdate(DATE_ATOM, $start + $i * 60));
        $hit['_source']['actor']['display_name'] = str_repeat('N', 150);
        $hits[] = $hit;
    }
    $network = new AccessFixtureNetwork(accessRoutes($hits));
    $reader = new UnifiAccessReader(new CredentialLeaseDecryptor(collectorIdentitySecretKey()), $network, 20_000);
    $snapshot = $reader->read(accessConsoleFixture(), '2026-07-22T12:00:00+00:00', collectorNow());

    // 24 hourly windows of 10 events would be ~70 KB: the newest windows wait for the next round.
    expect($snapshot['state'])->toBe('ok')
        ->and(strlen(json_encode($snapshot)) <= 20_000)->toBe(true)
        ->and(count($snapshot['events']) > 0)->toBe(true)
        ->and(count($snapshot['events']) < 240)->toBe(true)
        ->and(count($snapshot['events']) % 10)->toBe(0)
        ->and($snapshot['events_until'])->toBe(gmdate(DATE_ATOM, $start + 3600 * intdiv(count($snapshot['events']), 10)))
        ->and($snapshot['complete'])->toBe(true);
});

it('reads each console when it is due and moves its cursor only once the snapshot is stored', function () {
    $directory = collectorTempDirectory('unifi-access-lane');
    try {
        $stored = [];
        $accept = true;
        $lane = new UnifiAccessLane(
            accessReader(new AccessFixtureNetwork(accessRoutes())),
            $directory.'/unifi-access.json',
            function (string $id, array $item, DateTimeImmutable $at) use (&$stored, &$accept): bool {
                if ($accept) {
                    $stored[] = $id;
                }

                return $accept;
            },
        );
        $section = ['consoles' => [accessConsoleFixture()]];

        $accept = false;
        expect($lane->run($section, collectorNow()))->toBe(0);
        $accept = true;
        expect($lane->run($section, collectorNow()))->toBe(1)
            ->and($lane->run($section, collectorNow()->modify('+2 minutes')))->toBe(0)
            ->and($lane->run($section, collectorNow()->modify('+5 minutes')))->toBe(1)
            ->and($stored)->toHaveCount(2);

        $state = json_decode((string) file_get_contents($directory.'/unifi-access.json'), true);
        expect($state['consoles']['7']['events_until'])->toBe('2026-07-23T12:05:00+00:00');

        // A console gone from the work list is forgotten.
        $lane->run(null, collectorNow()->modify('+10 minutes'));
        $state = json_decode((string) file_get_contents($directory.'/unifi-access.json'), true);
        expect($state['consoles'])->toBe([]);
    } finally {
        removeCollectorDirectory($directory);
    }
});

it('accepts only private console addresses with a lease for exactly that console', function (array $overrides) {
    expect(fn () => CollectorConfig::unifiAccess(['consoles' => [accessConsoleFixture($overrides)]], [9]))->toThrow(ConfigurationRejected::class);
})->with([
    'public address' => [['address' => '8.8.8.8']],
    'another Site' => [['site_id' => 10]],
    'odd port' => [['port' => 22]],
    'bad pin' => [['trusted_pin' => 'sha1//abc']],
    'too often' => [['interval_seconds' => 5]],
    'lease for another address' => [['credential_lease' => sealedCollectorCredentialLease(['api_token' => ACCESS_HUB_TOKEN], ['device_id' => 'access-console-7', 'protocol' => 'access.unifi', 'target' => '10.44.0.61'])]],
    'lease for another job' => [['credential_lease' => sealedCollectorCredentialLease(['api_token' => ACCESS_HUB_TOKEN], ['device_id' => 'access-console-7', 'protocol' => 'snmp', 'target' => '10.44.0.60'])]],
    'extra key' => [['command' => 'unlock']],
]);

it('accepts a well-formed console list', function () {
    $section = ['consoles' => [accessConsoleFixture()]];

    expect(CollectorConfig::unifiAccess($section, [9]))->toBe($section)
        ->and(CollectorConfig::unifiAccess(null, [9]))->toBeNull();
});
