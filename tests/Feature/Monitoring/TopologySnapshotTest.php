<?php

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Jobs\BuildSnmpTopologySnapshot;
use App\Domain\Monitoring\Jobs\BuildTopologySnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Protocols\Snmp\SnmpTopologyObservation;
use App\Domain\Monitoring\Services\DependencyEvaluator;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\Monitoring\Services\RuntimeEnvelopeHandlerRegistry;
use App\Domain\Monitoring\Topology\Data\TopologyEvidence;
use App\Domain\Monitoring\Topology\Models\TopologyEdge;
use App\Domain\Monitoring\Topology\Models\TopologySnapshot;
use App\Domain\Monitoring\Topology\Services\NativeSnmpTopologyProjector;
use App\Domain\Monitoring\Topology\Services\ProviderTopologyCollector;
use App\Domain\Monitoring\Topology\Services\TopologySnapshotBuilder;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Presenters\NetworkItWorkspacePresenter;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\Contracts\TopologyCollectionCapability;
use App\Services\Integration\Data\IntegrationCapabilityManifest;
use App\Services\Integration\Data\ProviderTopologyPage;
use App\Services\Integration\IntegrationAdapterInterface;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\SyncResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

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

beforeEach(function () {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-23T12:00:00Z');
    config()->set('monitoring.signing', [
        'active_key_id' => 'topology-test-key',
        'keys' => [
            'topology-test-key' => base64_encode(str_repeat("\x41", SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ],
    ]);
    config()->set('monitoring.delivery.sequence_lock_store', 'array');
    config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('stores temporal Site topology without overwriting reviewed device relationships', function () {
    expect(Schema::hasColumns('monitoring_topology_snapshots', [
        'site_id', 'snapshot_uuid', 'source', 'source_checkpoint_hash', 'source_envelope_id',
        'captured_at', 'completed_at', 'status', 'node_count', 'edge_count', 'change_count', 'summary',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_topology_nodes', [
            'topology_snapshot_id', 'canonical_device_id', 'discovery_candidate_id',
            'observed_identity_hash', 'node_key_hash',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_topology_edges', [
            'topology_snapshot_id', 'from_node_id', 'to_node_id', 'source', 'kind',
            'local_port', 'remote_port', 'confidence', 'evidence', 'evidence_hash',
            'edge_hash', 'content_hash', 'first_seen_at', 'last_seen_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_topology_changes', [
            'previous_snapshot_id', 'current_snapshot_id', 'change_type', 'edge_hash',
            'before_edge_id', 'after_edge_id', 'evidence',
        ]))->toBeTrue();

    $site = topologySite();
    $switch = topologyDevice($site, ['name' => 'Core switch']);
    $accessPoint = topologyDevice($site, ['name' => 'Hall access point']);

    $snapshot = app(TopologySnapshotBuilder::class)->build($site, [
        new TopologyEvidence('lldp', $switch->id, $accessPoint->id, 'ethernet', 'Gi1/0/8', 'eth0', 0.95, [
            'chassis_hash' => hash('sha256', 'chassis-a'),
        ]),
        new TopologyEvidence('arp', $switch->id, $accessPoint->id, 'observed_path', null, null, 0.45, [
            'table_age_seconds' => 12,
        ]),
        new TopologyEvidence('forwarding_table', $switch->id, $accessPoint->id, 'ethernet', '8', null, 0.6, [
            'bridge_port' => 8,
        ]),
        new TopologyEvidence('route', $switch->id, $accessPoint->id, 'route', null, null, 0.35, [
            'destination_hash' => hash('sha256', '10.44.0.0/16'),
        ]),
    ], source: 'native:snmp', sourceCheckpoint: 'snmp-run-001');

    expect($snapshot->status)->toBe('completed')
        ->and($snapshot->node_count)->toBe(2)
        ->and($snapshot->edge_count)->toBe(4)
        ->and($snapshot->changes)->toHaveCount(4)
        ->and($snapshot->edges->pluck('source')->sort()->values()->all())
        ->toBe(['arp', 'forwarding_table', 'lldp', 'route'])
        ->and($snapshot->edges->firstWhere('source', 'lldp')?->confidence)->toBe('0.9500')
        ->and(DeviceRelationship::query()->count())->toBe(0);
});

it('deduplicates identical evidence while retaining conflicts and unresolved nodes', function () {
    $site = topologySite();
    $switch = topologyDevice($site);
    $firstHash = hash('sha256', 'unresolved-ap-a');
    $secondHash = hash('sha256', 'unresolved-ap-b');
    $first = new TopologyEvidence(
        source: 'lldp',
        fromDeviceId: $switch->id,
        toDeviceId: null,
        kind: 'ethernet',
        localPort: 'Gi1/0/8',
        remotePort: 'eth0',
        confidence: 0.8,
        evidence: ['chassis_hash' => hash('sha256', 'a')],
        toObservedIdentityHash: $firstHash,
    );

    $snapshot = app(TopologySnapshotBuilder::class)->build($site, [
        $first,
        $first,
        new TopologyEvidence(
            source: 'lldp',
            fromDeviceId: $switch->id,
            toDeviceId: null,
            kind: 'ethernet',
            localPort: 'Gi1/0/8',
            remotePort: 'eth0',
            confidence: 0.7,
            evidence: ['chassis_hash' => hash('sha256', 'b')],
            toObservedIdentityHash: $secondHash,
        ),
    ], source: 'native:snmp', sourceCheckpoint: 'snmp-run-conflict');

    expect($snapshot->nodes)->toHaveCount(3)
        ->and($snapshot->edges)->toHaveCount(2)
        ->and($snapshot->summary['unresolved_nodes'])->toBe(2)
        ->and($snapshot->summary['conflicts'])->toBe(1);
});

it('rejects cross Site canonical and discovery candidate endpoints', function () {
    $site = topologySite();
    $otherSite = topologySite();
    $local = topologyDevice($site);
    $foreign = topologyDevice($otherSite);
    $scope = DiscoveryScope::factory()->create(['site_id' => $otherSite->id]);
    $run = DiscoveryRun::factory()->create([
        'discovery_scope_id' => $scope->id,
        'scope_snapshot' => ['site_id' => $otherSite->id],
    ]);
    $candidate = DiscoveryCandidate::factory()->create(['discovery_run_id' => $run->id]);

    expect(fn () => app(TopologySnapshotBuilder::class)->build($site, [
        new TopologyEvidence('lldp', $local->id, $foreign->id, 'ethernet', '1', '1', 0.9, []),
    ], sourceCheckpoint: 'cross-site-device'))
        ->toThrow(UnexpectedValueException::class, 'canonical Site')
        ->and(fn () => app(TopologySnapshotBuilder::class)->build($site, [
            new TopologyEvidence(
                source: 'provider',
                fromDeviceId: $local->id,
                toDeviceId: null,
                kind: 'logical',
                localPort: null,
                remotePort: null,
                confidence: 0.6,
                evidence: [],
                toCandidateId: $candidate->id,
            ),
        ], sourceCheckpoint: 'cross-site-candidate'))
        ->toThrow(UnexpectedValueException::class, 'discovery candidate Site');
});

it('records added removed and changed edges against the previous completed snapshot', function () {
    $site = topologySite();
    $a = topologyDevice($site);
    $b = topologyDevice($site);
    $c = topologyDevice($site);
    $builder = app(TopologySnapshotBuilder::class);

    $first = $builder->build($site, [
        new TopologyEvidence('lldp', $a->id, $b->id, 'ethernet', '1', 'eth0', 0.9, ['vlan' => 10]),
        new TopologyEvidence('arp', $a->id, $c->id, 'observed_path', null, null, 0.4, []),
    ], source: 'native:snmp', sourceCheckpoint: 'diff-001');
    CarbonImmutable::setTestNow('2026-07-23T12:05:00Z');
    $second = $builder->build($site, [
        new TopologyEvidence('lldp', $a->id, $b->id, 'ethernet', '2', 'eth0', 0.95, ['vlan' => 20]),
        new TopologyEvidence('cdp', $b->id, $c->id, 'uplink', '2', '1', 0.85, []),
    ], source: 'native:snmp', sourceCheckpoint: 'diff-002');

    expect($first->change_count)->toBe(2)
        ->and($second->changes->pluck('change_type')->sort()->values()->all())
        ->toBe(['added', 'changed', 'removed'])
        ->and($second->summary['changes'])->toMatchArray([
            'added' => 1,
            'removed' => 1,
            'changed' => 1,
        ])
        ->and($second->edges->firstWhere('source', 'lldp')?->first_seen_at?->equalTo($first->captured_at))->toBeTrue();
});

it('makes completed snapshots nodes edges and changes immutable', function () {
    $site = topologySite();
    $a = topologyDevice($site);
    $b = topologyDevice($site);
    $snapshot = app(TopologySnapshotBuilder::class)->build($site, [
        new TopologyEvidence('lldp', $a->id, $b->id, 'ethernet', '1', '1', 0.9, []),
    ], sourceCheckpoint: 'immutable-001');
    $node = $snapshot->nodes->first();
    $edge = $snapshot->edges->first();
    $change = $snapshot->changes->first();

    $snapshot->summary = ['forged' => true];
    expect(fn () => $snapshot->saveQuietly())
        ->toThrow(LogicException::class, 'Completed topology snapshot is immutable.')
        ->and(fn () => TopologySnapshot::query()->whereKey($snapshot->id)->update(['edge_count' => 99]))
        ->toThrow(LogicException::class, 'Completed topology snapshot is immutable.');

    $node->observed_identity_hash = hash('sha256', 'forged');
    $edge->confidence = 0.1;
    $change->change_type = 'removed';

    expect(fn () => $node->saveQuietly())->toThrow(LogicException::class, 'Topology nodes are immutable.')
        ->and(fn () => $edge->saveQuietly())->toThrow(LogicException::class, 'Topology edges are immutable.')
        ->and(fn () => $change->saveQuietly())->toThrow(LogicException::class, 'Topology changes are immutable.')
        ->and(fn () => TopologyEdge::query()->whereKey($edge->id)->delete())
        ->toThrow(LogicException::class, 'Topology edges are immutable.');
});

it('rejects unsafe unbounded and incomplete topology evidence', function () {
    expect(fn () => new TopologyEvidence('lldp', 1, 2, 'ethernet', '1', '2', 1.1, []))
        ->toThrow(InvalidArgumentException::class, 'Topology confidence is invalid.')
        ->and(fn () => new TopologyEvidence('shell', 1, 2, 'ethernet', '1', '2', 0.9, []))
        ->toThrow(InvalidArgumentException::class, 'Topology source is invalid.')
        ->and(fn () => new TopologyEvidence('lldp', 1, 2, 'ethernet', '1', '2', 0.9, [
            'authorization_token' => 'secret',
        ]))->toThrow(InvalidArgumentException::class, 'Topology evidence is invalid.')
        ->and(fn () => new TopologyEvidence(
            source: 'lldp',
            fromDeviceId: 1,
            toDeviceId: null,
            kind: 'ethernet',
            localPort: '1',
            remotePort: '2',
            confidence: 0.9,
            evidence: [],
        ))->toThrow(InvalidArgumentException::class, 'Topology endpoint is invalid.');
});

it('is idempotent per Site source checkpoint and publishes a signed projection', function () {
    $site = topologySite();
    $a = topologyDevice($site);
    $b = topologyDevice($site);
    $evidence = [new TopologyEvidence('lldp', $a->id, $b->id, 'ethernet', '1', '1', 0.9, [])];
    $job = new BuildTopologySnapshot(
        siteId: $site->id,
        source: 'native:snmp',
        checkpoint: 'job-001',
        evidence: array_map(fn (TopologyEvidence $item): array => $item->toArray(), $evidence),
    );

    $job->handle(
        app(TopologySnapshotBuilder::class),
        app(ProviderTopologyCollector::class),
        app(MonitoringOutboxPublisher::class),
    );
    $job->handle(
        app(TopologySnapshotBuilder::class),
        app(ProviderTopologyCollector::class),
        app(MonitoringOutboxPublisher::class),
    );

    $snapshot = TopologySnapshot::query()->sole();
    $outbox = MonitoringOutbox::query()->sole();
    $envelope = app(RuntimeEnvelopeCodec::class)->decode($outbox->envelope_bytes);
    app(RuntimeEnvelopeHandlerRegistry::class)->for(RuntimeMessageType::Projection)->handle($envelope);

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('monitoring-topology')
        ->and($job->timeout)->toBe(300)
        ->and($job->uniqueId())->toBe($site->id.':'.hash('sha256', 'native:snmp:job-001'))
        ->and($envelope->type)->toBe(RuntimeMessageType::Projection)
        ->and($envelope->payload)->toMatchArray([
            'projection_family' => 'topology_snapshot',
            'site_id' => $site->id,
            'snapshot_id' => $snapshot->id,
            'node_count' => 2,
            'edge_count' => 1,
        ]);
});

it('projects native SNMP source snapshots into one private idempotent Site topology', function () {
    $site = topologySite();
    $switch = topologyDevice($site, [
        'name' => 'Core switch',
        'mac_address' => 'AA:BB:CC:DD:EE:01',
    ]);
    $accessPoint = topologyDevice($site, [
        'name' => 'Hall access point',
        'mac_address' => 'AA:BB:CC:DD:EE:02',
    ]);
    DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
        'cidrs' => ['10.44.0.0/16'],
    ]);
    $observedAt = CarbonImmutable::parse('2026-07-23T12:00:00Z');
    $lldp = new SnmpTopologyObservation(
        source: 'lldp',
        kind: 'ethernet',
        localPort: 'gi1/0/8',
        remotePort: 'eth0',
        confidence: 0.98,
        remoteIdentity: new DiscoveredIdentity(
            provider: null,
            providerId: null,
            serialNumber: null,
            hardwareId: null,
            macAddresses: ['AA:BB:CC:DD:EE:02'],
            certificateFingerprint: null,
            hostname: 'Hall access point',
            addresses: ['10.44.0.30'],
            fingerprint: null,
        ),
        evidence: ['protocol' => 'lldp', 'relationship' => 'direct_neighbor'],
        observedAt: $observedAt,
    );
    $arp = new SnmpTopologyObservation(
        source: 'arp',
        kind: 'observed_path',
        localPort: 'gi1/0/8',
        remotePort: null,
        confidence: 0.58,
        remoteIdentity: new DiscoveredIdentity(
            provider: null,
            providerId: null,
            serialNumber: null,
            hardwareId: null,
            macAddresses: ['AA:BB:CC:DD:EE:99'],
            certificateFingerprint: null,
            hostname: null,
            addresses: ['10.44.0.99'],
            fingerprint: null,
        ),
        evidence: ['protocol' => 'arp', 'relationship' => 'address_resolution'],
        observedAt: $observedAt,
    );
    $job = new BuildSnmpTopologySnapshot(
        siteId: $site->id,
        deviceId: $switch->id,
        checkpoint: 'monitor:42:2026-07-23T12:00:00Z',
        observations: [$lldp->jsonSerialize(), $arp->jsonSerialize()],
        completedSources: ['lldp', 'arp'],
    );

    $job->handle(app(NativeSnmpTopologyProjector::class));
    $job->handle(app(NativeSnmpTopologyProjector::class));

    $aggregate = TopologySnapshot::query()
        ->with(['nodes', 'edges', 'changes'])
        ->where('source', 'native:snmp')
        ->sole();
    $decodedPayloads = MonitoringOutbox::query()->get()
        ->map(fn (MonitoringOutbox $outbox): array => app(RuntimeEnvelopeCodec::class)
            ->decode($outbox->envelope_bytes)
            ->payload)
        ->all();
    $persisted = json_encode([
        'snapshots' => TopologySnapshot::query()->with(['nodes', 'edges', 'changes'])->get()->toArray(),
        'projections' => $decodedPayloads,
    ], JSON_THROW_ON_ERROR);

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('monitoring-topology')
        ->and($job->timeout)->toBe(300)
        ->and($job->uniqueId())->toBe($site->id.':'.$switch->id.':'.hash('sha256', $job->checkpoint))
        ->and(TopologySnapshot::query()->count())->toBe(3)
        ->and(MonitoringOutbox::query()->count())->toBe(1)
        ->and($aggregate->edge_count)->toBe(2)
        ->and($aggregate->nodes->pluck('canonical_device_id')->filter()->sort()->values()->all())
        ->toBe(collect([$switch->id, $accessPoint->id])->sort()->values()->all())
        ->and($aggregate->nodes->whereNull('canonical_device_id')->whereNotNull('observed_identity_hash'))
        ->toHaveCount(1)
        ->and($persisted)->not->toContain(
            'AA:BB:CC:DD:EE:02',
            'aa:bb:cc:dd:ee:02',
            'AA:BB:CC:DD:EE:99',
            'aa:bb:cc:dd:ee:99',
            '10.44.0.30',
            '10.44.0.99',
        );

    CarbonImmutable::setTestNow('2026-07-23T12:05:00Z');
    $removal = new BuildSnmpTopologySnapshot(
        siteId: $site->id,
        deviceId: $switch->id,
        checkpoint: 'monitor:42:2026-07-23T12:05:00Z',
        observations: [],
        completedSources: ['lldp'],
    );
    $removal->handle(app(NativeSnmpTopologyProjector::class));

    $latest = TopologySnapshot::query()
        ->with('changes')
        ->where('source', 'native:snmp')
        ->latest('id')
        ->firstOrFail();
    expect(TopologySnapshot::query()->count())->toBe(5)
        ->and(MonitoringOutbox::query()->count())->toBe(2)
        ->and($latest->edge_count)->toBe(1)
        ->and($latest->changes->where('change_type', 'removed'))->toHaveCount(1);
});

it('combines the latest native and provider sources in the readable topology map without internal duplicates', function () {
    $site = topologySite();
    $gateway = topologyDevice($site, ['name' => 'Rimu gateway']);
    $switch = topologyDevice($site, ['name' => 'Rimu core switch']);
    $accessPoint = topologyDevice($site, ['name' => 'Rimu access point']);
    $builder = app(TopologySnapshotBuilder::class);
    $native = new TopologyEvidence(
        'lldp',
        $gateway->id,
        $switch->id,
        'ethernet',
        'lan1',
        'uplink',
        0.98,
        ['protocol' => 'lldp'],
    );
    $builder->build(
        $site,
        [$native],
        source: "native:snmp:lldp:device:{$gateway->id}",
        sourceCheckpoint: 'internal-lldp-001',
    );
    $builder->build($site, [$native], source: 'native:snmp', sourceCheckpoint: 'native-001');
    $builder->build($site, [new TopologyEvidence(
        'provider',
        $switch->id,
        $accessPoint->id,
        'uplink',
        null,
        null,
        0.99,
        ['provider' => 'unifi', 'protocol' => 'unifi_network_api'],
    )], source: 'provider:unifi', sourceCheckpoint: 'unifi-001');
    $viewer = User::factory()->create(['approved_at' => now()]);

    $workspace = app(NetworkItWorkspacePresenter::class)->present(
        $viewer,
        Device::query()->whereKey([$gateway->id, $switch->id, $accessPoint->id]),
        ['key' => 'map', 'label' => 'Map', 'description' => 'Known topology'],
    );
    $topology = $workspace['activeTab']['topology'];

    expect($topology['source'])->toBe('runtime_topology')
        ->and($topology['state'])->toBe('known')
        ->and($topology['nodeCount'])->toBe(3)
        ->and($topology['edgeCount'])->toBe(2)
        ->and(collect($topology['snapshots'])->pluck('source')->sort()->values()->all())
        ->toBe(['native:snmp', 'provider:unifi'])
        ->and(collect($topology['edges'])->pluck('evidenceLabel')->all())
        ->toContain('UniFi provider Uplink evidence')
        ->and($topology['changes'])->toBe(['added' => 2, 'removed' => 0, 'changed' => 0]);
});

it('uses a confidence-qualified topology edge to suppress only the downstream symptom', function () {
    $site = topologySite();
    $gateway = topologyDevice($site, ['name' => 'Root gateway']);
    $accessPoint = topologyDevice($site, ['name' => 'Downstream access point']);
    $snapshot = app(TopologySnapshotBuilder::class)->build($site, [
        new TopologyEvidence(
            'lldp',
            $gateway->id,
            $accessPoint->id,
            'ethernet',
            'lan1',
            'uplink',
            0.98,
            ['protocol' => 'lldp'],
        ),
    ], source: 'native:snmp', sourceCheckpoint: 'root-cause-001');
    $upstream = Monitor::factory()->create([
        'device_id' => $gateway->id,
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
    ]);
    $downstream = Monitor::factory()->create([
        'device_id' => $accessPoint->id,
        'current_state' => MonitorState::Failed,
        'effective_state' => MonitorState::Failed,
    ]);
    MonitorDependency::query()->create([
        'site_id' => $site->id,
        'upstream_monitor_id' => $upstream->id,
        'downstream_monitor_id' => $downstream->id,
        'policy' => MonitorDependency::POLICY_SUPPRESS,
        'source' => 'topology',
        'confidence' => 0.98,
        'topology_edge_id' => $snapshot->edges->sole()->id,
        'is_active' => true,
    ]);

    $decision = app(DependencyEvaluator::class)->evaluate($downstream, now());

    expect($decision->effectiveState)->toBe(MonitorState::Suppressed)
        ->and($decision->underlyingState)->toBe(MonitorState::Failed)
        ->and($decision->rootCauseMonitorId)->toBe($upstream->id)
        ->and($decision->symptomVisible)->toBeTrue();
});

it('collects bounded provider pages and resolves provider identity through the canonical matcher', function () {
    $site = topologySite();
    $switch = topologyDevice($site);
    $accessPoint = topologyDevice($site, [
        'provider' => 'topology-fixture',
        'external_ref' => ['provider_entity_id' => 'ap-42'],
    ]);
    DiscoveryScope::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    IntegrationProviderConnection::query()->create([
        'provider' => 'topology-fixture',
        'secret_encrypted' => Crypt::encryptString('fixture-key'),
        'secret_last4' => '-key',
        'status' => IntegrationProviderConnection::STATUS_CONNECTED,
    ]);
    IntegrationSiteConfig::query()->create([
        'site_id' => $site->id,
        'provider' => 'topology-fixture',
        'status' => IntegrationSiteConfig::STATUS_HYBRID,
        'mapped_external_site_id' => 'provider-site-42',
        'is_active' => true,
    ]);
    $adapter = new TopologyFixtureAdapter([
        null => new ProviderTopologyPage(
            nodes: [
                ['key' => 'switch', 'device_id' => $switch->id],
                ['key' => 'ap', 'identity' => ['provider' => 'topology-fixture', 'provider_id' => 'ap-42']],
            ],
            edges: [[
                'from' => 'switch',
                'to' => 'ap',
                'source' => 'provider',
                'kind' => 'ethernet',
                'local_port' => '8',
                'remote_port' => 'eth0',
                'confidence' => 0.97,
                'evidence' => ['protocol' => 'provider_lldp'],
            ]],
            nextCursor: 'page-2',
        ),
        'page-2' => new ProviderTopologyPage(nodes: [], edges: []),
    ]);
    app()->instance(TopologyFixtureAdapter::class, $adapter);
    app(IntegrationAdapterRegistry::class)->register(
        'topology-fixture',
        TopologyFixtureAdapter::class,
        new IntegrationCapabilityManifest(
            provider: 'topology-fixture',
            version: '1.0',
            capabilities: [TopologyCollectionCapability::class],
            requiredPermissions: ['securityDevices.integrations.view'],
            sensitivityLabels: ['site_topology'],
            pageLimit: 25,
            minimumIntervalSeconds: 60,
            backfillLimit: 100,
        ),
    );

    $job = new BuildTopologySnapshot(
        siteId: $site->id,
        source: 'provider:topology-fixture',
        checkpoint: 'provider-sync-001',
        provider: 'topology-fixture',
    );
    $job->handle(
        app(TopologySnapshotBuilder::class),
        app(ProviderTopologyCollector::class),
        app(MonitoringOutboxPublisher::class),
    );

    $snapshot = TopologySnapshot::query()->with('nodes')->sole();

    expect($adapter->requestedCursors)->toBe([null, 'page-2'])
        ->and($adapter->requestedLimits)->toBe([25, 25])
        ->and($snapshot->edge_count)->toBe(1)
        ->and($snapshot->nodes->pluck('canonical_device_id')->sort()->values()->all())
        ->toBe(collect([$switch->id, $accessPoint->id])->sort()->values()->all());
});

function topologySite(): Site
{
    return Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
}

/** @param array<string, mixed> $attributes */
function topologyDevice(Site $site, array $attributes = []): Device
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

final class TopologyFixtureAdapter implements IntegrationAdapterInterface, TopologyCollectionCapability
{
    /** @var list<?string> */
    public array $requestedCursors = [];

    /** @var list<int> */
    public array $requestedLimits = [];

    /** @param array<string|int|null, ProviderTopologyPage> $pages */
    public function __construct(private readonly array $pages) {}

    public function provider(): string
    {
        return 'topology-fixture';
    }

    public function capabilities(): array
    {
        return [TopologyCollectionCapability::class];
    }

    public function testConnection(IntegrationProviderConnection $connection): bool
    {
        return true;
    }

    public function discoverSites(IntegrationProviderConnection $connection): array
    {
        return [];
    }

    public function syncDevices(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
    ): SyncResult {
        return new SyncResult;
    }

    public function pullHealth(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
    ): array {
        return [];
    }

    public function pullEvents(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?DateTimeInterface $since = null,
    ): array {
        return [];
    }

    public function collectTopology(
        IntegrationSiteConfig $siteConfig,
        IntegrationProviderConnection $providerConnection,
        ?string $cursor,
        int $limit,
    ): ProviderTopologyPage {
        $this->requestedCursors[] = $cursor;
        $this->requestedLimits[] = $limit;

        return $this->pages[$cursor] ?? throw new RuntimeException('Unexpected provider topology cursor.');
    }
}
