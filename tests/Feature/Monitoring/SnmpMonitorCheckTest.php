<?php

use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Jobs\BuildSnmpTopologySnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Protocols\Snmp\SnmpQuery;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTransport;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTransportResult;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\MonitorCheckRunner;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Queue;

if (getenv('MONITORING_USE_PREBUILT_TEST_DATABASE') === '1') {
    $databasePath = getenv('DB_DATABASE');
    if (getenv('APP_ENV') !== 'testing'
        || getenv('DB_CONNECTION') !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || ! is_file($databasePath)) {
        throw new RuntimeException(
            'MONITORING_USE_PREBUILT_TEST_DATABASE requires APP_ENV=testing, DB_CONNECTION=sqlite, and an existing file-backed database.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

final class TaskNineMonitorScopeProvider implements ApprovedProbeScopeProvider
{
    public function forDeviceAtSite(int $siteId, int $deviceId): ProbeScope
    {
        return new ProbeScope($siteId, $deviceId, ['10.44.0.0/16'], [161], 2, 5, 128_000);
    }
}

final class TaskNineMonitorCredentialProvider implements CredentialLeaseProvider
{
    public int $calls = 0;

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $this->calls++;

        return new CredentialLease(
            'monitor-lease-'.$this->calls,
            CarbonImmutable::now('UTC')->addMinute(),
            [
                'security_name' => 'fixture-collector',
                'auth_protocol' => 'SHA256',
                'auth_secret' => 'fixture-auth-passphrase',
                'privacy_protocol' => 'AES',
                'privacy_secret' => 'fixture-privacy-passphrase',
            ],
        );
    }
}

final class TaskNineMonitorSnmpTransport implements SnmpTransport
{
    /** @param list<SnmpTransportResult> $results */
    public function __construct(private array $results) {}

    public int $calls = 0;

    public function poll(
        AuthorizedProbeTarget $target,
        CredentialLease $lease,
        SnmpQuery $query,
    ): SnmpTransportResult {
        $this->calls++;
        $lease->material();

        return array_shift($this->results) ?? throw new RuntimeException('SNMP fixture result is unavailable.');
    }
}

/** @return array<string, int|float|string|bool|null> */
function taskNineMonitorVarbinds(int $inOctets, int $outOctets, int $uptimeTicks): array
{
    $fixture = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/fixtures/monitoring/snmp/interfaces.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $values = $fixture['varbinds'];
    $values['1.3.6.1.2.1.1.3.0'] = $uptimeTicks;
    $values['1.3.6.1.2.1.31.1.1.1.6.1'] = $inOctets;
    $values['1.3.6.1.2.1.31.1.1.1.10.1'] = $outOctets;

    return $values;
}

/** @return array<string, int|float|string|bool|null> */
function taskNineMonitorTopologyVarbinds(int $inOctets, int $outOctets, int $uptimeTicks): array
{
    return [
        ...taskNineMonitorVarbinds($inOctets, $outOctets, $uptimeTicks),
        '1.0.8802.1.1.2.1.4.1.1.4.100.1.1' => 4,
        '1.0.8802.1.1.2.1.4.1.1.5.100.1.1' => 'AA:BB:CC:DD:EE:02',
        '1.0.8802.1.1.2.1.4.1.1.6.100.1.1' => 5,
        '1.0.8802.1.1.2.1.4.1.1.7.100.1.1' => 'eth0',
        '1.0.8802.1.1.2.1.4.1.1.8.100.1.1' => 'Ethernet 0',
        '1.0.8802.1.1.2.1.4.1.1.9.100.1.1' => 'Hall access point',
    ];
}

it('runs one root SNMPv3 poll and fans bounded scalar observations into interface monitors', function () {
    Queue::fake([BuildSnmpTopologySnapshot::class]);
    CarbonImmutable::setTestNow('2026-07-23T01:00:00Z');
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $device = Device::factory()->itInfrastructure()->create(['ip_address' => '10.44.1.8']);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->subMinute(),
    ]);
    $profile = MonitoringProfile::factory()->create([
        'failure_confirmations' => 1,
        'recovery_confirmations' => 1,
    ]);
    $root = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'kind' => MonitorKind::Snmp,
        'name' => 'SNMP inventory',
        'target' => '10.44.1.8',
        'config' => [
            'host' => '10.44.1.8',
            'version' => 'v3',
            'credential_reference' => 'vault:snmp/site-'.$site->id.'/core-switch',
        ],
        'current_state' => MonitorState::Unknown,
    ]);
    $interface = Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'kind' => MonitorKind::SnmpInterface,
        'name' => 'WAN uplink',
        'target' => '10.44.1.8',
        'config' => [
            'parent_monitor_id' => $root->id,
            'if_index' => 1,
            'credential_reference' => 'vault:snmp/site-'.$site->id.'/core-switch',
        ],
        'current_state' => MonitorState::Unknown,
    ]);
    $credentials = new TaskNineMonitorCredentialProvider;
    $transport = new TaskNineMonitorSnmpTransport([
        SnmpTransportResult::success(
            taskNineMonitorTopologyVarbinds(1_000_000, 2_000_000, 110_000),
            8,
            completedOptionalWalkRoots: ['1.0.8802.1.1.2.1.4.1.1'],
        ),
        SnmpTransportResult::success(taskNineMonitorVarbinds(2_000_000, 3_500_000, 116_000), 9),
    ]);
    app()->instance(ApprovedProbeScopeProvider::class, new TaskNineMonitorScopeProvider);
    app()->instance(CredentialLeaseProvider::class, $credentials);
    app()->instance(SnmpTransport::class, $transport);
    app()->forgetInstance(EgressPolicy::class);

    app(MonitorCheckRunner::class)->run($root->id, 'scheduled:first');
    app(MonitorCheckRunner::class)->run($root->id, 'scheduled:first');

    expect($transport->calls)->toBe(1)
        ->and($credentials->calls)->toBe(1)
        ->and(MonitorObservation::query()->where('monitor_id', $root->id)->count())->toBe(1)
        ->and(MonitorObservation::query()->where('monitor_id', $interface->id)->count())->toBe(1);
    Queue::assertPushed(BuildSnmpTopologySnapshot::class, 1);
    Queue::assertPushed(BuildSnmpTopologySnapshot::class, function (BuildSnmpTopologySnapshot $job) use ($site, $device, $root): bool {
        return $job->siteId === $site->id
            && $job->deviceId === $device->id
            && $job->checkpoint === "monitor:{$root->id}:scheduled:first"
            && $job->completedSources === ['lldp']
            && count($job->observations) === 1
            && $job->connection === 'redis'
            && $job->queue === 'monitoring-topology';
    });

    CarbonImmutable::setTestNow('2026-07-23T01:01:00Z');
    app(MonitorCheckRunner::class)->run($root->id, 'scheduled:second');

    $latest = MonitorObservation::query()
        ->where('monitor_id', $interface->id)
        ->latest('observed_at')
        ->latest('id')
        ->firstOrFail();
    expect($transport->calls)->toBe(2)
        ->and($latest->metrics)->toMatchArray([
            'if_index' => 1,
            'interface_name' => 'gi1/0/1',
            'admin_status' => 'up',
            'operational_status' => 'up',
            'in_bps' => 133_333,
            'out_bps' => 200_000,
            'counter_discontinuity' => false,
            'protocol_kind' => 'snmp_interface',
            'parent_monitor_id' => $root->id,
        ])
        ->and(collect($latest->metrics)->every(fn (mixed $value): bool => is_scalar($value) || $value === null))->toBeTrue()
        ->and(json_encode($latest->metrics, JSON_THROW_ON_ERROR))->not->toContain('fixture-auth-passphrase')
        ->not->toContain('fixture-privacy-passphrase');
    Queue::assertPushed(BuildSnmpTopologySnapshot::class, 1);

    CarbonImmutable::setTestNow();
});
