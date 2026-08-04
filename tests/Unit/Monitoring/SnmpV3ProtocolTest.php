<?php

use App\Domain\Monitoring\Adapters\SnmpV3ProbeAdapter;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Protocols\Snmp\SnmpCompatibilityAuthorizer;
use App\Domain\Monitoring\Protocols\Snmp\SnmpCounterNormalizer;
use App\Domain\Monitoring\Protocols\Snmp\SnmpQuery;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTopologyParser;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTransport;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTransportResult;
use App\Domain\Monitoring\Services\UnavailableCredentialLeaseProvider;
use Carbon\CarbonImmutable;

final class TaskNineCredentialLeaseProvider implements CredentialLeaseProvider
{
    /** @var list<array{site_id: int, reference: string, capabilities: list<string>}> */
    public array $requests = [];

    public function __construct(private readonly Closure $factory) {}

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $this->requests[] = [
            'site_id' => $siteId,
            'reference' => $reference,
            'capabilities' => array_values($capabilities),
        ];

        return ($this->factory)();
    }
}

final class TaskNineSnmpTransport implements SnmpTransport
{
    /** @var list<array<string, mixed>> */
    public array $material = [];

    /** @var list<SnmpQuery> */
    public array $queries = [];

    public function __construct(public SnmpTransportResult $result) {}

    public function poll(
        AuthorizedProbeTarget $target,
        CredentialLease $lease,
        SnmpQuery $query,
    ): SnmpTransportResult {
        $this->queries[] = $query;
        $this->material[] = $lease->material();

        return $this->result;
    }
}

final class TaskNineSnmpCompatibilityAuthorizer implements SnmpCompatibilityAuthorizer
{
    /** @var list<array{site_id: int, device_id: int, version: string, reference: string}> */
    public array $requests = [];

    public function __construct(private readonly bool $allowed) {}

    public function authorize(int $siteId, int $deviceId, string $version, string $credentialReference): void
    {
        $this->requests[] = [
            'site_id' => $siteId,
            'device_id' => $deviceId,
            'version' => $version,
            'reference' => $credentialReference,
        ];

        if (! $this->allowed) {
            throw new RuntimeException('SNMP compatibility exception is not active.');
        }
    }
}

function taskNineLease(array $overrides = []): CredentialLease
{
    return new CredentialLease(
        'lease-fixture-1',
        CarbonImmutable::parse('2026-07-23T01:01:00Z'),
        array_replace([
            'security_name' => 'fixture-collector',
            'auth_protocol' => 'SHA256',
            'auth_secret' => 'fixture-auth-passphrase',
            'privacy_protocol' => 'AES',
            'privacy_secret' => 'fixture-privacy-passphrase',
        ], $overrides),
        CarbonImmutable::parse('2026-07-23T01:00:00Z'),
    );
}

function taskNineContext(array $config = []): AuthorisedProbeContext
{
    return new AuthorisedProbeContext(
        monitorId: 41,
        siteId: 9,
        deviceId: 81,
        kind: MonitorKind::Snmp,
        target: AuthorizedProbeTarget::fromEgressPolicy(
            siteId: 9,
            deviceId: 81,
            scheme: 'snmp',
            host: '10.44.1.8',
            port: 161,
            path: null,
            addresses: ['10.44.1.8'],
            connectTimeoutSeconds: 2,
            responseTimeoutSeconds: 5,
            maxResponseBytes: 64_000,
        ),
        config: array_replace([
            'version' => 'v3',
            'credential_reference' => 'vault:snmp/site-9/core-switch',
        ], $config),
    );
}

/** @return array<string, mixed> */
function taskNineFixture(): array
{
    return json_decode(
        file_get_contents(dirname(__DIR__, 2).'/Fixtures/monitoring/snmp/interfaces.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

function taskNineAdapter(
    SnmpTransportResult $result,
    ?TaskNineSnmpCompatibilityAuthorizer $authorizer = null,
): array {
    $provider = new TaskNineCredentialLeaseProvider(fn () => taskNineLease());
    $transport = new TaskNineSnmpTransport($result);
    $compatibility = $authorizer ?? new TaskNineSnmpCompatibilityAuthorizer(false);

    return [
        new SnmpV3ProbeAdapter($provider, $transport, $compatibility, new SnmpTopologyParser),
        $provider,
        $transport,
        $compatibility,
    ];
}

it('issues one-use expiring credential leases without serialising material', function () {
    $lease = taskNineLease();
    $material = $lease->material();

    expect($material)->toMatchArray([
        'security_name' => 'fixture-collector',
        'auth_protocol' => 'SHA256',
        'privacy_protocol' => 'AES',
    ])
        ->and(json_encode($lease, JSON_THROW_ON_ERROR))->not->toContain('fixture-auth-passphrase')
        ->not->toContain('fixture-privacy-passphrase')
        ->and(fn () => $lease->material())
        ->toThrow(RuntimeException::class, 'Credential lease has already been consumed.');

    $expired = new CredentialLease(
        'lease-expired',
        CarbonImmutable::parse('2026-07-23T00:59:59Z'),
        ['auth_secret' => 'expired-fixture-secret'],
        CarbonImmutable::parse('2026-07-23T01:00:00Z'),
    );

    expect(fn () => $expired->material())
        ->toThrow(RuntimeException::class, 'Credential lease expired.')
        ->and(json_encode($expired, JSON_THROW_ON_ERROR))->not->toContain('expired-fixture-secret');
});

it('fails closed when no credential provider is configured', function () {
    expect(fn () => (new UnavailableCredentialLeaseProvider)->acquire(9, 'vault:snmp/site-9', ['snmp:v3']))
        ->toThrow(RuntimeException::class, 'Credential lease provider is not configured.');
});

it('requires authPriv SNMPv3 and normalises bounded inventory interfaces counters and sensors', function () {
    $fixture = taskNineFixture();
    [$adapter, $provider, $transport] = taskNineAdapter(SnmpTransportResult::success(
        $fixture['varbinds'],
        $fixture['latency_ms'],
    ));

    $poll = $adapter->poll(taskNineContext());
    $interface = $poll->interfaces[0];
    $sensor = $poll->sensors[0];

    expect($poll->summary->state)->toBe(MonitorState::Healthy)
        ->and($poll->summary->evidence)->toMatchArray([
            'interface_count' => 2,
            'sensor_count' => 1,
            'system_name' => 'core-switch-01',
        ])
        ->and($poll->identity?->serialNumber)->toBe('switch-serial-0001')
        ->and($poll->identity?->hardwareId)->toBeNull()
        ->and($poll->identity?->fingerprint)->toBe('snmp:1.3.6.1.4.1.9.1.1208:c9300-24t')
        ->and($interface->ifIndex)->toBe(1)
        ->and($interface->name)->toBe('gi1/0/1')
        ->and($interface->adminStatus)->toBe('up')
        ->and($interface->operStatus)->toBe('up')
        ->and($interface->counterBits)->toBe(64)
        ->and($sensor->value)->toBe(23.4)
        ->and($sensor->unit)->toBe('celsius')
        ->and($provider->requests)->toBe([[
            'site_id' => 9,
            'reference' => 'vault:snmp/site-9/core-switch',
            'capabilities' => ['snmp:v3:auth_priv'],
        ]])
        ->and($transport->queries[0]->maxVarbinds)->toBeLessThanOrEqual(4096)
        ->and(json_encode($poll, JSON_THROW_ON_ERROR))->not->toContain('fixture-auth-passphrase')
        ->not->toContain('fixture-privacy-passphrase');
});

it('maps authentication privacy and oversized-walk failures without leaking transport detail', function (
    string $status,
    string $reason,
) {
    [$adapter] = taskNineAdapter(SnmpTransportResult::failure($status, 'raw fixture detail must not persist'));

    $poll = $adapter->poll(taskNineContext());

    expect($poll->summary->state)->toBe(MonitorState::Failed)
        ->and($poll->summary->reasonCode)->toBe($reason)
        ->and(json_encode($poll, JSON_THROW_ON_ERROR))->not->toContain('raw fixture detail');
})->with([
    'authentication' => ['authentication_failed', 'snmp_authentication_failed'],
    'privacy' => ['privacy_failed', 'snmp_privacy_failed'],
    'walk limit' => ['walk_limit_exceeded', 'snmp_walk_limit_exceeded'],
]);

it('keeps partial OID responses usable but visibly degraded', function () {
    $fixture = taskNineFixture();
    unset($fixture['varbinds']['1.3.6.1.2.1.31.1.1.1.10.2']);
    [$adapter] = taskNineAdapter(SnmpTransportResult::success(
        $fixture['varbinds'],
        $fixture['latency_ms'],
        partial: true,
    ));

    $poll = $adapter->poll(taskNineContext());

    expect($poll->summary->state)->toBe(MonitorState::Degraded)
        ->and($poll->summary->reasonCode)->toBe('partial_oid_response')
        ->and($poll->summary->evidence['partial_walk'])->toBeTrue()
        ->and($poll->interfaces)->toHaveCount(2);
});

it('disables v1 and v2c unless a current scoped compatibility exception authorises the call', function () {
    $fixture = taskNineFixture();
    [$denied] = taskNineAdapter(
        SnmpTransportResult::success($fixture['varbinds'], 4),
        new TaskNineSnmpCompatibilityAuthorizer(false),
    );

    expect(fn () => $denied->poll(taskNineContext(['version' => 'v2c'])))
        ->toThrow(RuntimeException::class, 'SNMP compatibility exception is not active.');

    $authorizer = new TaskNineSnmpCompatibilityAuthorizer(true);
    [$allowed, $provider, $transport] = taskNineAdapter(
        SnmpTransportResult::success($fixture['varbinds'], 4),
        $authorizer,
    );
    $poll = $allowed->poll(taskNineContext(['version' => 'v2c']));

    expect($poll->summary->state)->toBe(MonitorState::Healthy)
        ->and($authorizer->requests)->toBe([[
            'site_id' => 9,
            'device_id' => 81,
            'version' => 'v2c',
            'reference' => 'vault:snmp/site-9/core-switch',
        ]])
        ->and($provider->requests[0]['capabilities'])->toBe(['snmp:v2c:compatibility'])
        ->and($transport->material)->toHaveCount(1);
});

it('normalises 32-bit rollover and suppresses rates after reboot or discontinuity', function () {
    $normalizer = new SnmpCounterNormalizer;
    $rollover = $normalizer->rates(
        currentIn: 15,
        currentOut: 35,
        previous: [
            'counter_in_octets' => 4_294_967_290,
            'counter_out_octets' => 15,
            'counter_bits' => 32,
            'uptime_ticks' => 19_000,
            'counter_discontinuity_ticks' => 5,
            'observed_unix' => 100,
        ],
        observedUnix: 110,
        uptimeTicks: 20_000,
        discontinuityTicks: 5,
        counterBits: 32,
        speedBps: 1_000_000,
    );

    expect($rollover)->toMatchArray([
        'in_bps' => 17,
        'out_bps' => 16,
        'counter_discontinuity' => false,
    ]);

    $reboot = $normalizer->rates(
        currentIn: 25,
        currentOut: 45,
        previous: [
            'counter_in_octets' => 20,
            'counter_out_octets' => 40,
            'counter_bits' => 64,
            'uptime_ticks' => 20_000,
            'counter_discontinuity_ticks' => 5,
            'observed_unix' => 100,
        ],
        observedUnix: 110,
        uptimeTicks: 100,
        discontinuityTicks: 5,
        counterBits: 64,
        speedBps: 1_000_000,
    );

    expect($reboot['in_bps'])->toBeNull()
        ->and($reboot['out_bps'])->toBeNull()
        ->and($reboot['counter_discontinuity'])->toBeTrue();
});

it('emits only scalar persisted observations for an interface and sensor', function () {
    $fixture = taskNineFixture();
    [$adapter] = taskNineAdapter(SnmpTransportResult::success($fixture['varbinds'], 8));
    $poll = $adapter->poll(taskNineContext());

    $interface = $poll->interfaceObservation(1, [
        'counter_in_octets' => 1_000_000,
        'counter_out_octets' => 2_000_000,
        'counter_bits' => 64,
        'uptime_ticks' => 110_000,
        'counter_discontinuity_ticks' => 0,
        'observed_unix' => CarbonImmutable::parse('2026-07-23T00:59:50Z')->timestamp,
    ]);
    $sensor = $poll->sensorObservation(101);

    expect(collect($interface->evidence)->every(fn (mixed $value): bool => is_scalar($value) || $value === null))->toBeTrue()
        ->and($interface->evidence)->toMatchArray([
            'if_index' => 1,
            'interface_name' => 'gi1/0/1',
            'admin_status' => 'up',
            'operational_status' => 'up',
        ])
        ->and($sensor->value)->toBe(23.4)
        ->and($sensor->unit)->toBe('celsius')
        ->and(collect($sensor->evidence)->every(fn (mixed $value): bool => is_scalar($value) || $value === null))->toBeTrue();
});
