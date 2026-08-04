<?php

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Protocols\Snmp\SnmpCompatibilityException;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTrapIntakeService;
use App\Domain\Monitoring\Services\MonitoringEnvelopeConsumer;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

final class TaskNineTrapCredentialProvider implements CredentialLeaseProvider
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

        return ($this->factory)($capabilities);
    }
}

function taskNineTrapLease(array $material): CredentialLease
{
    return new CredentialLease(
        'trap-lease-'.bin2hex(random_bytes(4)),
        CarbonImmutable::now('UTC')->addMinute(),
        $material,
    );
}

function taskNineTrapTlv(int $tag, string $value): string
{
    $length = strlen($value);
    if ($length < 128) {
        return chr($tag).chr($length).$value;
    }

    $encoded = '';
    while ($length > 0) {
        $encoded = chr($length & 0xFF).$encoded;
        $length >>= 8;
    }

    return chr($tag).chr(0x80 | strlen($encoded)).$encoded.$value;
}

function taskNineTrapUnsignedBytes(int $value): string
{
    if ($value === 0) {
        return "\0";
    }

    $encoded = '';
    while ($value > 0) {
        $encoded = chr($value & 0xFF).$encoded;
        $value >>= 8;
    }

    return (ord($encoded[0]) & 0x80) !== 0 ? "\0".$encoded : $encoded;
}

function taskNineTrapInteger(int $value): string
{
    return taskNineTrapTlv(0x02, taskNineTrapUnsignedBytes($value));
}

function taskNineTrapOctet(string $value): string
{
    return taskNineTrapTlv(0x04, $value);
}

function taskNineTrapOid(string $oid): string
{
    $parts = array_map('intval', explode('.', trim($oid, '.')));
    $encoded = chr(($parts[0] * 40) + $parts[1]);

    foreach (array_slice($parts, 2) as $part) {
        $bytes = [($part & 0x7F)];
        while (($part >>= 7) > 0) {
            array_unshift($bytes, ($part & 0x7F) | 0x80);
        }
        $encoded .= implode('', array_map('chr', $bytes));
    }

    return taskNineTrapTlv(0x06, $encoded);
}

function taskNineTrapVarbind(string $oid, string $encodedValue): string
{
    return taskNineTrapTlv(0x30, taskNineTrapOid($oid).$encodedValue);
}

function taskNineTrapPdu(int $requestId = 7001): string
{
    $varbinds = taskNineTrapVarbind(
        '1.3.6.1.2.1.1.3.0',
        taskNineTrapTlv(0x43, taskNineTrapUnsignedBytes(123456)),
    ).taskNineTrapVarbind(
        '1.3.6.1.6.3.1.1.4.1.0',
        taskNineTrapOid('1.3.6.1.6.3.1.1.5.3'),
    ).taskNineTrapVarbind(
        '1.3.6.1.2.1.1.5.0',
        taskNineTrapOctet('core-switch-01'),
    ).taskNineTrapVarbind(
        '1.3.6.1.4.1.9.9.999.1.0',
        taskNineTrapOctet('must-not-cross-the-allowlist'),
    );

    return taskNineTrapTlv(
        0xA7,
        taskNineTrapInteger($requestId)
            .taskNineTrapInteger(0)
            .taskNineTrapInteger(0)
            .taskNineTrapTlv(0x30, $varbinds),
    );
}

function taskNineV2Trap(string $community = 'fixture-community'): string
{
    return taskNineTrapTlv(
        0x30,
        taskNineTrapInteger(1).taskNineTrapOctet($community).taskNineTrapPdu(),
    );
}

function taskNinePasswordToKey(string $passphrase, string $engineId): string
{
    $length = strlen($passphrase);
    $expanded = '';
    for ($offset = 0; $offset < 1_048_576; $offset += $length) {
        $expanded .= substr($passphrase, 0, min($length, 1_048_576 - $offset));
    }
    $key = hash('sha256', $expanded, true);

    return hash('sha256', $key.$engineId.$key, true);
}

function taskNineV3Trap(
    string $authSecret = 'fixture-auth-passphrase',
    string $privacySecret = 'fixture-privacy-passphrase',
): string {
    $engineId = hex2bin('80001f8880e9630000d61ff449') ?: '';
    $engineBoots = 7;
    $engineTime = 1234;
    $privacyParameters = hex2bin('0000000100000002') ?: '';
    $privacyKey = substr(taskNinePasswordToKey($privacySecret, $engineId), 0, 16);
    $iv = pack('N', $engineBoots).pack('N', $engineTime).$privacyParameters;
    $scopedPdu = taskNineTrapTlv(
        0x30,
        taskNineTrapOctet($engineId).taskNineTrapOctet('').taskNineTrapPdu(),
    );
    $encrypted = openssl_encrypt($scopedPdu, 'aes-128-cfb', $privacyKey, OPENSSL_RAW_DATA, $iv);
    if (! is_string($encrypted)) {
        throw new RuntimeException('Unable to create the synthetic SNMPv3 fixture.');
    }

    $zeroAuthentication = str_repeat("\0", 24);
    $securityParameters = taskNineTrapTlv(
        0x30,
        taskNineTrapOctet($engineId)
            .taskNineTrapInteger($engineBoots)
            .taskNineTrapInteger($engineTime)
            .taskNineTrapOctet('fixture-collector')
            .taskNineTrapOctet($zeroAuthentication)
            .taskNineTrapOctet($privacyParameters),
    );
    $header = taskNineTrapTlv(
        0x30,
        taskNineTrapInteger(501).taskNineTrapInteger(65_507).taskNineTrapOctet("\x07").taskNineTrapInteger(3),
    );
    $message = taskNineTrapTlv(
        0x30,
        taskNineTrapInteger(3).$header.taskNineTrapOctet($securityParameters).taskNineTrapOctet($encrypted),
    );
    $needle = taskNineTrapOctet($zeroAuthentication);
    $position = strpos($message, $needle);
    if ($position === false) {
        throw new RuntimeException('Synthetic SNMPv3 authentication field is missing.');
    }
    $authentication = substr(
        hash_hmac('sha256', $message, taskNinePasswordToKey($authSecret, $engineId), true),
        0,
        24,
    );

    return substr_replace($message, $authentication, $position + 2, 24);
}

function taskNineUnauthenticatedV3Trap(): string
{
    $engineId = hex2bin('80001f8880e9630000d61ff449') ?: '';
    $securityParameters = taskNineTrapTlv(
        0x30,
        taskNineTrapOctet($engineId)
            .taskNineTrapInteger(7)
            .taskNineTrapInteger(1234)
            .taskNineTrapOctet('fixture-collector')
            .taskNineTrapOctet('')
            .taskNineTrapOctet(''),
    );
    $header = taskNineTrapTlv(
        0x30,
        taskNineTrapInteger(502).taskNineTrapInteger(65_507).taskNineTrapOctet("\x04").taskNineTrapInteger(3),
    );
    $scoped = taskNineTrapTlv(0x30, taskNineTrapOctet($engineId).taskNineTrapOctet('').taskNineTrapPdu(7002));

    return taskNineTrapTlv(
        0x30,
        taskNineTrapInteger(3).$header.taskNineTrapOctet($securityParameters).$scoped,
    );
}

/** @return array{site: Site, device: Device, scope: DiscoveryScope} */
function taskNineTrapEstate(string $cidr = '10.44.0.0/16'): array
{
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $device = Device::factory()->itInfrastructure()->create(['ip_address' => '10.44.1.10']);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMinute(),
        'released_at' => null,
    ]);
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'cidrs' => [$cidr],
        'protocols' => ['snmp'],
        'port_bounds' => ['snmp' => [161]],
        'snmp_credential_reference' => 'vault:snmp/site-'.$site->id.'/traps',
        'status' => 'active',
    ]);

    return compact('site', 'device', 'scope');
}

beforeEach(function () {
    config()->set('monitoring.signing', [
        'active_key_id' => 'snmp-trap-test-key',
        'keys' => [
            'snmp-trap-test-key' => base64_encode(str_repeat("\x31", SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ],
    ]);
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    Queue::fake();
});

it('adds a Site-scoped opaque reference and governed compatibility-exception schema', function () {
    expect(Schema::hasColumns('monitoring_discovery_scopes', ['snmp_credential_reference']))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_snmp_engine_states', [
            'site_id',
            'sender_address_hash',
            'engine_id_hash',
            'engine_boots',
            'engine_time',
            'received_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_snmp_compatibility_exceptions', [
            'site_id',
            'device_id',
            'version',
            'credential_reference',
            'owner_user_id',
            'reason',
            'expires_at',
            'migration_status',
            'revoked_at',
        ]))->toBeTrue();
});

it('authenticates and decrypts a v3 trap then creates one canonical DeviceEvent only in the event consumer', function () {
    $estate = taskNineTrapEstate();
    $provider = new TaskNineTrapCredentialProvider(fn () => taskNineTrapLease([
        'security_name' => 'fixture-collector',
        'auth_protocol' => 'SHA256',
        'auth_secret' => 'fixture-auth-passphrase',
        'privacy_protocol' => 'AES',
        'privacy_secret' => 'fixture-privacy-passphrase',
    ]));
    app()->instance(CredentialLeaseProvider::class, $provider);

    $first = app(SnmpTrapIntakeService::class)->ingest(taskNineV3Trap(), '10.44.1.10');
    $duplicate = app(SnmpTrapIntakeService::class)->ingest(taskNineV3Trap(), '10.44.1.10');

    expect($duplicate->id)->toBe($first->id)
        ->and(MonitoringOutbox::count())->toBe(1)
        ->and(DeviceEvent::count())->toBe(0)
        ->and($provider->requests[0])->toMatchArray([
            'site_id' => $estate['site']->id,
            'reference' => 'vault:snmp/site-'.$estate['site']->id.'/traps',
            'capabilities' => ['snmp:trap:v3:auth_priv'],
        ]);

    app(MonitoringEnvelopeConsumer::class)->consume(
        'event-projector',
        $first->envelope_bytes,
        $estate['site']->id,
    );
    app(MonitoringEnvelopeConsumer::class)->consume(
        'event-projector',
        $first->envelope_bytes,
        $estate['site']->id,
    );

    $event = DeviceEvent::query()->sole();
    expect($event->device_id)->toBe($estate['device']->id)
        ->and($event->event_type)->toBe('offline')
        ->and($event->severity)->toBe('warning')
        ->and($event->source)->toBe('oblivion_snmp')
        ->and($event->payload)->toMatchArray([
            'trap_oid' => '1.3.6.1.6.3.1.1.5.3',
            'uptime_ticks' => 123456,
            'system_name' => 'core-switch-01',
        ]);

    $allEvidence = json_encode([
        'outbox' => $first->envelope_bytes,
        'event' => $event->payload,
    ], JSON_THROW_ON_ERROR);
    expect($allEvidence)->not->toContain('fixture-auth-passphrase')
        ->not->toContain('fixture-privacy-passphrase')
        ->not->toContain('must-not-cross-the-allowlist');
});

it('rejects unauthenticated tampered oversized out-of-scope and ambiguous trap intake', function () {
    taskNineTrapEstate();
    app()->instance(CredentialLeaseProvider::class, new TaskNineTrapCredentialProvider(fn () => taskNineTrapLease([
        'security_name' => 'fixture-collector',
        'auth_protocol' => 'SHA256',
        'auth_secret' => 'fixture-auth-passphrase',
        'privacy_protocol' => 'AES',
        'privacy_secret' => 'fixture-privacy-passphrase',
    ])));

    $tampered = taskNineV3Trap();
    $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0x01);

    expect(fn () => app(SnmpTrapIntakeService::class)->ingest(taskNineUnauthenticatedV3Trap(), '10.44.1.10'))
        ->toThrow(RuntimeException::class, 'SNMPv3 trap requires authenticated privacy.')
        ->and(fn () => app(SnmpTrapIntakeService::class)->ingest($tampered, '10.44.1.10'))
        ->toThrow(RuntimeException::class, 'SNMPv3 trap authentication failed.')
        ->and(fn () => app(SnmpTrapIntakeService::class)->ingest(str_repeat('x', 65_508), '10.44.1.10'))
        ->toThrow(RuntimeException::class, 'SNMP trap datagram exceeds the configured limit.')
        ->and(fn () => app(SnmpTrapIntakeService::class)->ingest(taskNineV3Trap(), '10.45.1.10'))
        ->toThrow(RuntimeException::class, 'SNMP trap sender does not resolve to one approved Site scope.');

    $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $other->id,
        'cidrs' => ['10.44.0.0/16'],
        'protocols' => ['snmp'],
        'port_bounds' => ['snmp' => [161]],
        'snmp_credential_reference' => 'vault:snmp/site-'.$other->id.'/traps',
        'status' => 'active',
    ]);

    expect(fn () => app(SnmpTrapIntakeService::class)->ingest(taskNineV3Trap(), '10.44.1.10'))
        ->toThrow(RuntimeException::class, 'SNMP trap sender does not resolve to one approved Site scope.')
        ->and(MonitoringOutbox::count())->toBe(0)
        ->and(DeviceEvent::count())->toBe(0);
});

it('keeps v1 and v2c disabled until a current Site and Device exception is recorded', function () {
    $estate = taskNineTrapEstate();
    $owner = User::factory()->create();
    $provider = new TaskNineTrapCredentialProvider(fn () => taskNineTrapLease([
        'community' => 'fixture-community',
    ]));
    app()->instance(CredentialLeaseProvider::class, $provider);

    expect(fn () => app(SnmpTrapIntakeService::class)->ingest(taskNineV2Trap(), '10.44.1.10'))
        ->toThrow(RuntimeException::class, 'SNMP compatibility exception is not active.');

    SnmpCompatibilityException::query()->create([
        'site_id' => $estate['site']->id,
        'device_id' => $estate['device']->id,
        'version' => 'v2c',
        'credential_reference' => 'vault:snmp/site-'.$estate['site']->id.'/traps',
        'owner_user_id' => $owner->id,
        'reason' => 'Legacy UPS controller awaiting scheduled replacement.',
        'expires_at' => now()->addDay(),
        'migration_status' => 'replacement_scheduled',
    ]);

    $outbox = app(SnmpTrapIntakeService::class)->ingest(taskNineV2Trap(), '10.44.1.10');

    expect($outbox)->toBeInstanceOf(MonitoringOutbox::class)
        ->and($provider->requests[0]['capabilities'])->toBe(['snmp:trap:v2c:compatibility'])
        ->and(DeviceEvent::count())->toBe(0);

    $exception = SnmpCompatibilityException::query()->firstOrFail();
    $exception->forceFill(['expires_at' => now()->subSecond()])->save();
    expect(fn () => app(SnmpTrapIntakeService::class)->ingest(taskNineV2Trap('fixture-community'), '10.44.1.10'))
        ->toThrow(RuntimeException::class, 'SNMP compatibility exception is not active.');
});

it('keeps the UDP command bounded configurable and free of inline DeviceEvent writes', function () {
    $source = file_get_contents(app_path('Console/Commands/MonitoringListenSnmpTraps.php'));

    expect($source)->toContain("config('monitoring.snmp.traps.bind'")
        ->toContain("config('monitoring.snmp.traps.port'")
        ->toContain('SnmpTrapIntakeService')
        ->toContain('65_507')
        ->not->toContain('DeviceEvent::', 'new DeviceEvent', 'device_events');
});
