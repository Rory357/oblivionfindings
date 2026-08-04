<?php

use App\Domain\Monitoring\Adapters\IcmpProbeAdapter;
use App\Domain\Monitoring\Adapters\TcpProbeAdapter;
use App\Domain\Monitoring\Adapters\TlsProbeAdapter;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\IcmpTransport;
use App\Domain\Monitoring\Contracts\ProbeScopeResolver;
use App\Domain\Monitoring\Contracts\TcpTransport;
use App\Domain\Monitoring\Contracts\TlsTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\IcmpTransportResult;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Data\TcpTransportResult;
use App\Domain\Monitoring\Data\TlsTransportResult;
use App\Domain\Monitoring\Discovery\Adapters\NetworkSeedDiscoveryAdapter;
use App\Domain\Monitoring\Discovery\Adapters\SnmpInventoryDiscoveryAdapter;
use App\Domain\Monitoring\Discovery\Contracts\DiscoveryAdapter;
use App\Domain\Monitoring\Discovery\Contracts\DiscoveryThrottle;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Data\DiscoveryProbeResult;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryResult;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Jobs\CompleteDiscoveryRun;
use App\Domain\Monitoring\Jobs\RunDiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/** @return array{site: Site, scope: DiscoveryScope} */
function taskEightScope(array $attributes = []): array
{
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'cidrs' => ['10.44.0.0/24'],
        'seed_hosts' => [],
        'protocols' => ['icmp', 'tcp', 'tls'],
        'exclusions' => [],
        'port_bounds' => ['tcp' => [22, 443], 'tls' => [443]],
        'max_targets_per_run' => 256,
        'packets_per_second' => 20,
        'status' => 'active',
        ...$attributes,
    ]);

    return compact('site', 'scope');
}

function taskEightIdentity(string $address, ?string $serial = null): DiscoveredIdentity
{
    return new DiscoveredIdentity(
        provider: null,
        providerId: null,
        serialNumber: $serial,
        hardwareId: null,
        macAddresses: [],
        certificateFingerprint: null,
        hostname: null,
        addresses: [$address],
        fingerprint: null,
    );
}

function taskEightAssignedDevice(Site $site, array $attributes = []): Device
{
    $device = Device::factory()->itInfrastructure()->create($attributes);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now()->subMinute(),
        'released_at' => null,
    ]);

    return $device;
}

final class TaskEightDiscoveryAdapter implements DiscoveryAdapter
{
    /** @var list<DiscoveryTarget> */
    public array $planned = [];

    /** @var array<string, DiscoveryProbeResult|Throwable> */
    public array $results = [];

    /** @var list<string> */
    public array $probed = [];

    public ?DiscoveryRunner $cancelWith = null;

    public ?int $cancelRunId = null;

    public bool $cancelOnFirstProbe = false;

    public function begin(DiscoveryScope $scope): void {}

    public function targets(DiscoveryScope $scope): iterable
    {
        yield from $this->planned;
    }

    public function discover(DiscoveryScope $scope, DiscoveryTarget $target): DiscoveryProbeResult
    {
        $this->probed[] = $target->key();

        if ($this->cancelOnFirstProbe && count($this->probed) === 1) {
            $this->cancelWith?->cancel((int) $this->cancelRunId, 'manual:test');
        }

        $result = $this->results[$target->key()] ?? DiscoveryProbeResult::unresolved('no_response');
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}

it('plans a bounded run after exclusions and dispatches it to the isolated discovery queue', function () {
    Queue::fake();
    $record = taskEightScope([
        'exclusions' => ['10.44.0.1', '10.44.0.128/25'],
        'max_targets_per_run' => 127,
    ]);

    $targets = iterator_to_array(app(NetworkSeedDiscoveryAdapter::class)->targets($record['scope']), false);
    app()->instance(DiscoveryAdapter::class, app(NetworkSeedDiscoveryAdapter::class));
    $run = app(DiscoveryRunner::class)->start($record['scope'], 'manual:user:7');

    expect($run->status)->toBe('queued')
        ->and($run->planned_targets)->toBe(127)
        ->and($targets)->toHaveCount(127)
        ->and(collect($targets)->pluck('host'))->not->toContain('10.44.0.1')
        ->and(collect($targets)->pluck('host')->contains(
            fn (string $host): bool => str_starts_with($host, '10.44.0.') && (int) substr($host, 8) >= 128,
        ))->toBeFalse();

    Queue::assertPushed(RunDiscoveryScope::class, function (RunDiscoveryScope $job) use ($run): bool {
        return $job->runId === $run->id
            && $job->queue === 'monitoring-discovery'
            && $job->connection === 'redis';
    });
});

it('caps very large CIDRs and jumps excluded ranges without enumerating the excluded block', function () {
    $record = taskEightScope([
        'cidrs' => ['10.0.0.0/8'],
        'exclusions' => ['10.0.0.0/17'],
        'max_targets_per_run' => 65536,
    ]);
    $count = 0;
    $first = null;
    $last = null;

    foreach (app(NetworkSeedDiscoveryAdapter::class)->targets($record['scope']) as $target) {
        $first ??= $target->host;
        $last = $target->host;
        $count++;
    }

    expect($count)->toBe(65536)
        ->and($first)->toBe('10.0.128.0')
        ->and($last)->toBe('10.1.127.255');
});

it('uses authorised direct probe adapters and a packet token for every network attempt', function () {
    $record = taskEightScope([
        'protocols' => ['icmp', 'tcp', 'tls'],
        'port_bounds' => ['tcp' => [22], 'tls' => [443]],
    ]);
    $dns = new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            throw new RuntimeException('DNS should not run for a numeric target.');
        }
    };
    $scopeResolver = new class implements ProbeScopeResolver
    {
        public function resolve(int $siteId, int $deviceId): ProbeScope
        {
            throw new RuntimeException('Canonical Device scope must not run before adoption.');
        }
    };
    $icmpTransport = new class implements IcmpTransport
    {
        public int $calls = 0;

        public function probe(AuthorizedProbeTarget $target): IcmpTransportResult
        {
            $this->calls++;

            return new IcmpTransportResult(true, 2, 0, 'reply');
        }
    };
    $tcpTransport = new class implements TcpTransport
    {
        public int $calls = 0;

        public function probe(AuthorizedProbeTarget $target): TcpTransportResult
        {
            $this->calls++;

            return new TcpTransportResult(true, 3, 'connected');
        }
    };
    $tlsTransport = new class implements TlsTransport
    {
        public int $calls = 0;

        public function probe(AuthorizedProbeTarget $target): TlsTransportResult
        {
            $this->calls++;

            return new TlsTransportResult(
                true,
                4,
                CarbonImmutable::now()->addDays(90),
                hash('sha256', 'issuer'),
                true,
                'TLSv1.3',
                'verified',
                str_repeat('a', 64),
            );
        }
    };
    $throttle = new class implements DiscoveryThrottle
    {
        public int $rate = 0;

        public int $acquisitions = 0;

        public function reset(int $packetsPerSecond): void
        {
            $this->rate = $packetsPerSecond;
        }

        public function acquire(): void
        {
            $this->acquisitions++;
        }
    };
    $adapter = new NetworkSeedDiscoveryAdapter(
        new CidrMatcher,
        new EgressPolicy(new CidrMatcher, $dns, $scopeResolver, config('monitoring.egress')),
        new IcmpProbeAdapter($icmpTransport),
        new TcpProbeAdapter($tcpTransport),
        new TlsProbeAdapter($tlsTransport),
        app(SnmpInventoryDiscoveryAdapter::class),
        $throttle,
    );
    $adapter->begin($record['scope']);

    $result = $adapter->discover($record['scope'], new DiscoveryTarget('10.44.0.50', 'cidr'));

    expect($result->outcome)->toBe('found')
        ->and($result->identity?->addresses)->toBe(['10.44.0.50'])
        ->and($result->identity?->certificateFingerprint)->toBe(str_repeat('a', 64))
        ->and($result->identity?->fingerprint)->toBe('network:tcp:22,443')
        ->and($icmpTransport->calls)->toBe(1)
        ->and($tcpTransport->calls)->toBe(1)
        ->and($tlsTransport->calls)->toBe(1)
        ->and($throttle->rate)->toBe(20)
        ->and($throttle->acquisitions)->toBe(3);
});

it('returns one active run per scope and rejects disabled or unavailable Site scopes', function () {
    Queue::fake();
    $record = taskEightScope(['max_targets_per_run' => 4]);
    $adapter = new TaskEightDiscoveryAdapter;
    $adapter->planned = [new DiscoveryTarget('10.44.0.10', 'cidr')];
    app()->instance(DiscoveryAdapter::class, $adapter);

    $first = app(DiscoveryRunner::class)->start($record['scope'], 'manual:user:7');
    $second = app(DiscoveryRunner::class)->start($record['scope']->fresh(), 'manual:user:8');

    expect($second->id)->toBe($first->id)
        ->and(DiscoveryRun::query()->where('discovery_scope_id', $record['scope']->id)->count())->toBe(1);
    Queue::assertPushed(RunDiscoveryScope::class, 1);

    $record['scope']->update(['status' => 'disabled']);
    expect(fn () => app(DiscoveryRunner::class)->start($record['scope']->fresh(), 'manual:user:9'))
        ->toThrow(UnexpectedValueException::class, 'scope_inactive');

    $record['scope']->update(['status' => 'active']);
    $record['site']->update(['archived' => true, 'archived_at' => now()]);
    expect(fn () => app(DiscoveryRunner::class)->start($record['scope']->fresh(), 'manual:user:9'))
        ->toThrow(UnexpectedValueException::class, 'scope_site_unavailable');
});

it('executes results idempotently and reconciles candidate and result drill-down counts', function () {
    Queue::fake();
    $record = taskEightScope(['max_targets_per_run' => 3]);
    taskEightAssignedDevice($record['site'], ['serial_number' => 'KNOWN-100']);
    $adapter = new TaskEightDiscoveryAdapter;
    $adapter->planned = [
        new DiscoveryTarget('10.44.0.10', 'cidr'),
        new DiscoveryTarget('10.44.0.11', 'cidr'),
        new DiscoveryTarget('10.44.0.12', 'cidr'),
    ];
    $adapter->results = [
        '10.44.0.10' => DiscoveryProbeResult::found(taskEightIdentity('10.44.0.10', 'KNOWN-100')),
        '10.44.0.11' => DiscoveryProbeResult::found(taskEightIdentity('10.44.0.11', 'NEW-200')),
        '10.44.0.12' => DiscoveryProbeResult::unresolved('no_response'),
    ];
    app()->instance(DiscoveryAdapter::class, $adapter);
    $runner = app(DiscoveryRunner::class);
    $run = $runner->start($record['scope'], 'manual:user:7');

    $runner->execute($run->id);
    Queue::assertPushed(CompleteDiscoveryRun::class, fn (CompleteDiscoveryRun $job): bool => $job->runId === $run->id);
    $completed = $runner->complete($run->id);
    $runner->execute($run->id);
    $runner->complete($run->id);

    expect($completed->status)->toBe('completed')
        ->and($completed->planned_targets)->toBe(3)
        ->and($completed->found_count)->toBe(2)
        ->and($completed->matched_count)->toBe(1)
        ->and($completed->proposed_count)->toBe(1)
        ->and($completed->changed_count)->toBe(1)
        ->and($completed->excluded_count)->toBe(0)
        ->and($completed->failed_count)->toBe(0)
        ->and($completed->unresolved_count)->toBe(1)
        ->and(DiscoveryResult::query()->where('discovery_run_id', $run->id)->count())->toBe(3)
        ->and(DiscoveryResult::query()->where('discovery_run_id', $run->id)->whereNotNull('target_reference_hash')->count())->toBe(3)
        ->and(DiscoveryCandidate::query()->where('discovery_run_id', $run->id)->count())->toBe(2)
        ->and($adapter->probed)->toHaveCount(3)
        ->and(fn () => $completed->fresh()->update(['found_count' => 99]))
        ->toThrow(LogicException::class, 'summary is immutable');
});

it('continues after a bounded adapter failure without persisting exception detail', function () {
    Queue::fake();
    $record = taskEightScope(['max_targets_per_run' => 3]);
    $adapter = new TaskEightDiscoveryAdapter;
    $adapter->planned = [
        new DiscoveryTarget('10.44.0.20', 'cidr'),
        new DiscoveryTarget('10.44.0.21', 'cidr'),
        new DiscoveryTarget('10.44.0.22', 'cidr'),
    ];
    $adapter->results = [
        '10.44.0.20' => DiscoveryProbeResult::found(taskEightIdentity('10.44.0.20', 'FOUND-20')),
        '10.44.0.21' => new RuntimeException('secret response body must never persist'),
        '10.44.0.22' => DiscoveryProbeResult::unresolved('no_response'),
    ];
    app()->instance(DiscoveryAdapter::class, $adapter);
    $runner = app(DiscoveryRunner::class);
    $run = $runner->start($record['scope'], 'manual:user:7');

    $runner->execute($run->id);
    $completed = $runner->complete($run->id);

    expect($completed->status)->toBe('completed')
        ->and($completed->found_count)->toBe(1)
        ->and($completed->failed_count)->toBe(1)
        ->and($completed->unresolved_count)->toBe(1)
        ->and($completed->failure_summary)->toBe('adapter_failure:1')
        ->and(DiscoveryResult::query()->where('discovery_run_id', $run->id)->where('failure_code', 'adapter_failure')->count())->toBe(1)
        ->and(json_encode(DiscoveryResult::query()->where('discovery_run_id', $run->id)->get()->toArray()))
        ->not->toContain('secret response body');
});

it('cancels safely and never resumes or overwrites pending target outcomes', function () {
    Queue::fake();
    $record = taskEightScope(['max_targets_per_run' => 3]);
    $adapter = new TaskEightDiscoveryAdapter;
    $adapter->planned = [
        new DiscoveryTarget('10.44.0.30', 'cidr'),
        new DiscoveryTarget('10.44.0.31', 'cidr'),
        new DiscoveryTarget('10.44.0.32', 'cidr'),
    ];
    $adapter->results = [
        '10.44.0.30' => DiscoveryProbeResult::found(taskEightIdentity('10.44.0.30', 'FOUND-30')),
    ];
    app()->instance(DiscoveryAdapter::class, $adapter);
    $runner = app(DiscoveryRunner::class);
    $run = $runner->start($record['scope'], 'manual:user:7');
    $adapter->cancelWith = $runner;
    $adapter->cancelRunId = $run->id;
    $adapter->cancelOnFirstProbe = true;

    $runner->execute($run->id);
    $cancelled = $run->fresh();
    $runner->execute($run->id);

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($adapter->probed)->toHaveCount(1)
        ->and(DiscoveryResult::query()->where('discovery_run_id', $run->id)->where('outcome', 'unresolved')->count())->toBe(3)
        ->and(DiscoveryResult::query()->where('discovery_run_id', $run->id)->where('failure_code', 'run_cancelled')->count())->toBe(3);
});

it('hands a collector scope to its assigned collector without probing it centrally', function () {
    Queue::fake();
    $record = taskEightScope(['max_targets_per_run' => 1]);
    $collector = MonitoringCollector::factory()->create([
        'site_id' => $record['site']->id,
        'status' => 'unavailable',
    ]);
    $record['scope']->update(['collector_id' => $collector->id]);
    $adapter = new TaskEightDiscoveryAdapter;
    $adapter->planned = [new DiscoveryTarget('10.44.0.40', 'cidr')];
    app()->instance(DiscoveryAdapter::class, $adapter);
    $runner = app(DiscoveryRunner::class);
    $run = $runner->start($record['scope']->fresh(), 'manual:user:7');

    $runner->execute($run->id);
    $running = $run->fresh();
    $work = $runner->collectorWork($collector, 512);

    expect($running->status)->toBe('running')
        ->and($running->failure_summary)->toBeNull()
        ->and($adapter->probed)->toBe([])
        ->and(DiscoveryResult::query()->where('discovery_run_id', $run->id)->where('outcome', 'pending')->count())->toBe(1)
        ->and($work)->toHaveCount(1)
        ->and($work[0]['id'])->toBe($run->run_uuid)
        ->and($work[0]['targets'])->toBe([['target' => '10.44.0.40', 'source' => 'cidr']]);
    Queue::assertNotPushed(CompleteDiscoveryRun::class);
});

it('accepts one scoped collector result idempotently and reconciles the canonical candidate summary', function () {
    Queue::fake();
    $record = taskEightScope(['max_targets_per_run' => 1]);
    $collector = MonitoringCollector::factory()->create([
        'site_id' => $record['site']->id,
        'status' => 'online',
    ]);
    $record['scope']->update(['collector_id' => $collector->id]);
    $adapter = new TaskEightDiscoveryAdapter;
    $adapter->planned = [new DiscoveryTarget('10.44.0.41', 'cidr')];
    app()->instance(DiscoveryAdapter::class, $adapter);
    $runner = app(DiscoveryRunner::class);
    $run = $runner->start($record['scope']->fresh(), 'manual:user:7');
    $runner->execute($run->id);
    $payload = [
        'item_type' => 'discovery_result',
        'run_id' => $run->run_uuid,
        'target' => '10.44.0.41',
        'observed_at' => now()->toAtomString(),
        'outcome' => 'found',
        'identity' => [
            'mac_addresses' => ['00:11:22:33:44:55'],
            'certificate_fingerprint' => null,
            'hostname' => 'remote-switch-41',
            'addresses' => ['10.44.0.41'],
            'fingerprint' => 'network:icmp,tcp:443',
        ],
    ];

    $runner->recordCollectorResult($collector, $payload);
    $runner->recordCollectorResult($collector, $payload);

    expect(DiscoveryResult::query()->where('discovery_run_id', $run->id)->where('outcome', 'found')->count())->toBe(1)
        ->and(DiscoveryCandidate::query()->where('discovery_run_id', $run->id)->count())->toBe(1);
    Queue::assertPushed(CompleteDiscoveryRun::class, 1);

    $completed = $runner->complete($run->id);
    expect($completed->status)->toBe('completed')
        ->and($completed->found_count)->toBe(1)
        ->and($completed->proposed_count)->toBe(1)
        ->and($completed->failed_count)->toBe(0)
        ->and($completed->unresolved_count)->toBe(0);
});

it('rejects invalid network bounds before creating or dispatching a run', function (array $attributes, string $reason) {
    Queue::fake();
    $record = taskEightScope($attributes);

    expect(fn () => app(DiscoveryRunner::class)->start($record['scope'], 'manual:user:7'))
        ->toThrow(UnexpectedValueException::class, $reason)
        ->and(DiscoveryRun::query()->where('discovery_scope_id', $record['scope']->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'invalid CIDR' => [['cidrs' => ['10.44.0.0/99']], 'approved_network_invalid'],
    'unbounded target count' => [['max_targets_per_run' => 65537], 'scope_configuration_invalid'],
    'unbounded packet rate' => [['packets_per_second' => 1001], 'scope_configuration_invalid'],
    'unbounded ports' => [['port_bounds' => ['tcp' => range(1, 129)]], 'scope_configuration_invalid'],
]);
