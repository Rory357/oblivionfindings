import { runLaravelPhp } from './helpers';
import { seedNetworkItWorkspaceReadinessFixtures } from './network-it-workspace-fixtures';
import { seedOperationsWorkspaceFixtures } from './operations-workspaces-fixtures';

export type NativeMonitoringRuntimeFixture = {
    allowedSiteName: string;
    hiddenSiteName: string;
    hiddenSiteId: number;
    hiddenDeviceId: number;
    hiddenDeviceName: string;
    restrictedEmail: string;
    rootMonitorName: string;
    symptomNames: string[];
    controlRoomReference: string;
    ticketReference: string;
    collectorName: string;
    collectorUuid: string;
    discoveryScopeName: string;
    matchedDeviceName: string;
    topologySource: string;
    tlsMonitorName: string;
    retentionPolicyName: string;
    configurationSnapshotLabel: string;
    rawSentinel: string;
    operations: ReturnType<typeof seedOperationsWorkspaceFixtures>;
    network: ReturnType<typeof seedNetworkItWorkspaceReadinessFixtures>;
};

export function seedNativeMonitoringRuntimeFixtures(): NativeMonitoringRuntimeFixture {
    const operations = seedOperationsWorkspaceFixtures();
    const network = seedNetworkItWorkspaceReadinessFixtures();
    const output = runLaravelPhp(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$rawSentinel = 'PW-NATIVE-RUNTIME-SECRET-MUST-NOT-RENDER';
$rootDevice = \\App\\Domain\\SecurityDevices\\Models\\Device::query()
    ->where('device_uid', 'PW-OPS-DIRECT')
    ->firstOrFail();
$allowedSite = \\App\\Models\\Site::query()
    ->whereKey(
        \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::query()
            ->where('device_id', $rootDevice->id)
            ->where('assignable_type', \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE)
            ->value('assignable_id'),
    )
    ->firstOrFail();

$hiddenSite = \\App\\Models\\Site::query()->firstOrCreate(
    ['name' => 'Playwright denied monitoring Site'],
    ['type' => 'facility', 'is_active' => true, 'archived' => false],
);
$hiddenDevice = \\App\\Domain\\SecurityDevices\\Models\\Device::withTrashed()
    ->where('device_uid', 'PW-NATIVE-HIDDEN')
    ->first();
if (! $hiddenDevice) {
    $hiddenDevice = new \\App\\Domain\\SecurityDevices\\Models\\Device(['device_uid' => 'PW-NATIVE-HIDDEN']);
} elseif ($hiddenDevice->trashed()) {
    $hiddenDevice->restore();
}
$hiddenDevice->forceFill([
    'name' => 'Playwright denied core switch',
    'domain' => 'it_infrastructure',
    'category' => 'networking',
    'subcategory' => 'managed_switch',
    'manufacturer' => 'Oblivion Native',
    'model' => 'Denied evidence fixture',
    'serial_number' => 'PW-NATIVE-HIDDEN-SERIAL',
    'provider' => 'oblivion_native',
    'status' => 'active',
    'health_status' => 'critical',
    'config' => ['credential' => $rawSentinel],
    'meta' => ['private_runtime' => $rawSentinel],
    'created_by_user_id' => $admin->id,
])->save();
\\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::query()
    ->where('device_id', $hiddenDevice->id)
    ->delete();
\\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::create([
    'device_id' => $hiddenDevice->id,
    'assignable_type' => \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
    'assignable_id' => $hiddenSite->id,
    'assignment_type' => 'permanent',
    'assigned_at' => now(),
]);

$profile = \\App\\Domain\\Monitoring\\Models\\MonitoringProfile::query()
    ->where('name', 'Playwright operations profile')
    ->firstOrFail();
$rootMonitor = \\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
    ['device_id' => $rootDevice->id, 'name' => 'Playwright root SD-WAN failure'],
    [
        'profile_id' => $profile->id,
        'kind' => 'icmp',
        'target' => '10.10.10.1',
        'config' => ['private' => $rawSentinel],
        'current_state' => 'failed',
        'effective_state' => 'failed',
        'pending_count' => 0,
        'affects_availability' => true,
        'is_enabled' => true,
        'last_observation_at' => now()->subMinute(),
        'last_state_changed_at' => now()->subMinutes(5),
    ],
);

$symptomNames = [
    'Playwright CCTV path symptom',
    'Playwright access control path symptom',
    'Playwright clinical telemetry path symptom',
];
foreach ($symptomNames as $index => $name) {
    $symptom = \\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
        ['device_id' => $rootDevice->id, 'name' => $name],
        [
            'profile_id' => $profile->id,
            'kind' => 'tcp',
            'target' => '10.10.10.'.(20 + $index).':443',
            'config' => [],
            'current_state' => 'failed',
            'effective_state' => 'suppressed',
            'root_cause_monitor_id' => $rootMonitor->id,
            'suppression_reason' => 'dependency_failed',
            'suppressed_at' => now()->subMinutes(4),
            'pending_count' => 0,
            'affects_availability' => true,
            'is_enabled' => true,
            'last_observation_at' => now()->subMinutes(2),
            'last_state_changed_at' => now()->subMinutes(4),
        ],
    );
    \\App\\Domain\\Monitoring\\Models\\MonitorDependency::query()->updateOrCreate(
        [
            'upstream_monitor_id' => $rootMonitor->id,
            'downstream_monitor_id' => $symptom->id,
        ],
        [
            'site_id' => $allowedSite->id,
            'policy' => \\App\\Domain\\Monitoring\\Models\\MonitorDependency::POLICY_SUPPRESS,
            'source' => 'topology',
            'confidence' => 0.96,
            'is_active' => true,
        ],
    );
}

$alert = \\App\\Models\\ControlRoomAlert::query()
    ->where('source', 'oblivion_native')
    ->where('alert_type', 'Playwright SD-WAN root failure')
    ->where('site_id', $allowedSite->id)
    ->first();
if (! $alert) {
    $alert = \\App\\Models\\ControlRoomAlert::factory()->create([
        'source' => 'oblivion_native',
        'alert_type' => 'Playwright SD-WAN root failure',
        'site_id' => $allowedSite->id,
        'severity' => 'high',
        'status' => \\App\\Models\\ControlRoomAlert::STATUS_RESOLVED,
        'resolved_at' => now()->subMinute(),
    ]);
}
$source = \\App\\Models\\ControlRoom\\SignalSource::query()->firstOrCreate(
    ['slug' => 'security_devices'],
    ['name' => 'Oblivion native monitoring', 'status' => 'active'],
);
$correlationKey = hash(
    'sha256',
    "site:{$allowedSite->id}:device:{$rootDevice->id}:root:{$rootMonitor->id}:condition:availability",
);
\\App\\Models\\ControlRoom\\Signal::query()->updateOrCreate(
    ['idempotency_key' => 'pw-native-root-correlation'],
    [
        'signal_source_id' => $source->id,
        'signal_type_code' => 'device_offline',
        'site_id' => $allowedSite->id,
        'severity_hint' => 'high',
        'occurred_at' => now()->subMinutes(5),
        'payload' => ['private' => $rawSentinel],
        'normalized_data' => ['monitor_correlation_key' => $correlationKey],
        'status' => 'processed',
        'alert_id' => $alert->id,
        'processed_at' => now()->subMinute(),
    ],
);
$ticket = \\App\\Models\\ItTicket::query()
    ->where('title', 'Playwright SD-WAN root incident')
    ->first();
if (! $ticket) {
    $ticket = \\App\\Models\\ItTicket::factory()->create([
        'site_id' => $allowedSite->id,
        'is_organisation_wide' => false,
        'is_sensitive' => false,
        'title' => 'Playwright SD-WAN root incident',
        'description' => 'Technician-owned incident linked to native monitoring.',
        'source' => 'system',
        'work_type' => 'incident',
        'priority' => 'high',
        'impact' => 'site',
        'urgency' => 'high',
        'status' => 'in_progress',
        'assigned_to_user_id' => $admin->id,
        'owner_user_id' => $admin->id,
        'monitoring_recovered_at' => now()->subMinute(),
    ]);
} else {
    $ticket->forceFill([
        'site_id' => $allowedSite->id,
        'status' => 'in_progress',
        'assigned_to_user_id' => $admin->id,
        'owner_user_id' => $admin->id,
        'monitoring_recovered_at' => now()->subMinute(),
    ])->save();
}
\\App\\Models\\ItTicketLink::query()->updateOrCreate(
    [
        'ticket_id' => $ticket->id,
        'relationship' => 'source_alert',
        'linkable_type' => $alert->getMorphClass(),
        'linkable_id' => $alert->id,
    ],
    ['created_by_user_id' => $admin->id],
);

$collector = \\App\\Domain\\Monitoring\\Models\\MonitoringCollector::query()
    ->where('name', 'Playwright remote collector')
    ->firstOrFail();
$collector->forceFill([
    'status' => 'offline',
    'runtime_state' => 'unavailable',
    'backlog_items' => 7,
    'gap_count' => 1,
    'last_heartbeat_at' => now()->subMinutes(20),
    'last_seen_at' => now()->subMinutes(20),
    'config' => ['private' => $rawSentinel],
])->save();

$scope = \\App\\Domain\\Monitoring\\Discovery\\Models\\DiscoveryScope::query()->updateOrCreate(
    ['site_id' => $allowedSite->id, 'name' => 'Playwright governed clinical discovery'],
    [
        'collector_id' => $collector->id,
        'cidrs' => ['10.44.0.0/24'],
        'seed_hosts' => [],
        'protocols' => ['icmp', 'snmp'],
        'exclusions' => ['10.44.0.1/32'],
        'port_bounds' => ['tcp' => [443]],
        'max_targets_per_run' => 64,
        'packets_per_second' => 10,
        'status' => 'active',
    ],
);
$run = \\App\\Domain\\Monitoring\\Discovery\\Models\\DiscoveryRun::query()
    ->where('run_uuid', '10000000-0000-4000-8000-000000000019')
    ->first();
if (! $run) {
    $run = \\App\\Domain\\Monitoring\\Discovery\\Models\\DiscoveryRun::factory()->create([
        'discovery_scope_id' => $scope->id,
        'run_uuid' => '10000000-0000-4000-8000-000000000019',
        'status' => 'completed',
        'trigger' => 'schedule',
        'scope_snapshot' => $scope->snapshot(),
        'planned_targets' => 3,
        'found_count' => 3,
        'matched_count' => 1,
        'proposed_count' => 1,
        'changed_count' => 0,
        'excluded_count' => 0,
        'failed_count' => 0,
        'unresolved_count' => 1,
        'started_at' => now()->subMinutes(8),
        'completed_at' => now()->subMinutes(7),
    ]);
}
$candidateRows = [
    ['uuid' => '20000000-0000-4000-8000-000000000001', 'decision' => 'matched', 'confidence' => 98, 'device_id' => $rootDevice->id, 'reason' => 'serial_match'],
    ['uuid' => '20000000-0000-4000-8000-000000000002', 'decision' => 'proposed', 'confidence' => 61, 'device_id' => null, 'reason' => 'no_existing_identity_match'],
    ['uuid' => '20000000-0000-4000-8000-000000000003', 'decision' => 'ambiguous', 'confidence' => 42, 'device_id' => null, 'reason' => 'multiple_identity_candidates'],
];
foreach ($candidateRows as $row) {
    $snapshot = [
        'addresses' => ['10.44.0.'.(10 + $row['confidence'] % 10)],
        'hostname' => 'playwright-candidate-'.$row['confidence'],
        'private' => $rawSentinel,
    ];
    \\App\\Domain\\Monitoring\\Discovery\\Models\\DiscoveryCandidate::query()->firstOrCreate(
        ['candidate_uuid' => $row['uuid']],
        [
            'discovery_run_id' => $run->id,
            'canonical_device_id' => $row['device_id'],
            'decision' => $row['decision'],
            'confidence' => $row['confidence'],
            'reasons' => [$row['reason']],
            'evidence_snapshot' => $snapshot,
            'evidence_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
        ],
    );
}

\\App\\Domain\\Monitoring\\Models\\ProviderCapabilityCursor::query()->updateOrCreate(
    ['site_id' => $allowedSite->id, 'provider' => 'unifi', 'capability' => 'device_sync'],
    [
        'cursor' => $rawSentinel,
        'last_started_at' => now()->subMinutes(4),
        'last_completed_at' => now()->subMinutes(6),
        'retry_not_before' => now()->addMinute(),
        'exception_count' => 1,
    ],
);
\\App\\Domain\\Monitoring\\Models\\ProviderCapabilityException::query()->firstOrCreate(
    ['site_id' => $allowedSite->id, 'provider' => 'unifi', 'capability' => 'device_sync', 'code' => 'rate_limited', 'item_reference' => 'page-4'],
    ['occurred_at' => now()->subMinutes(3)],
);

$series = \\App\\Domain\\Monitoring\\Models\\MetricSeries::query()->updateOrCreate(
    ['external_key' => 'pw-native-runtime-capacity'],
    [
        'site_id' => $allowedSite->id,
        'device_id' => $rootDevice->id,
        'monitor_id' => $rootMonitor->id,
        'metric' => 'interface.utilisation',
        'dimensions' => ['interface' => 'WAN1'],
        'dimensions_hash' => hash('sha256', 'PW-WAN1'),
        'unit' => 'percent',
        'source' => 'snmp',
        'data_class' => 'operational',
        'privacy_class' => 'standard',
        'retention_tier' => 'raw',
        'first_point_at' => now()->subDays(30),
        'last_point_at' => now()->subMinute(),
    ],
);
\\App\\Domain\\Monitoring\\Models\\MetricCurrentSummary::query()->updateOrCreate(
    ['series_id' => $series->id],
    [
        'value' => 82.5,
        'statistics' => ['p95' => 86.2],
        'sample_count' => 720,
        'observed_at' => now()->subMinute(),
        'storage_state' => 'available',
        'storage_checked_at' => now()->subMinute(),
    ],
);
$retention = \\App\\Domain\\Monitoring\\Models\\MonitoringRetentionPolicy::query()->updateOrCreate(
    ['name' => 'Playwright native monitoring retention'],
    [
        'scope_kind' => 'application',
        'raw_days' => 14,
        'hourly_days' => 180,
        'daily_days' => 1825,
        'legal_hold' => false,
        'is_active' => true,
        'created_by_user_id' => $admin->id,
    ],
);
\\App\\Domain\\Monitoring\\Models\\ConfigurationSnapshot::query()->firstOrCreate(
    ['snapshot_uuid' => '30000000-0000-4000-8000-000000000019'],
    [
        'site_id' => $allowedSite->id,
        'device_id' => $rootDevice->id,
        'source_kind' => 'ssh',
        'source' => 'approved_read_only',
        'storage_disk' => 'monitoring-snapshots',
        'storage_path' => 'private/'.$rawSentinel,
        'storage_path_hash' => hash('sha256', 'private-pw-runtime-snapshot'),
        'storage_state' => 'available',
        'content_hash' => hash('sha256', 'pw-runtime-content'),
        'configuration_hash' => hash('sha256', 'pw-runtime-config'),
        'content_size' => 2048,
        'mime_type' => 'text/plain',
        'firmware_version' => '1.5.0',
        'captured_at' => now()->subMinutes(10),
        'retention_policy_id' => $retention->id,
        'diff_summary' => ['state' => 'changed', 'changed_sections' => 2],
        'created_by_user_id' => $admin->id,
    ],
);

$tlsMonitorName = 'Playwright TLS certificate expires in 7 days';
\\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
    ['device_id' => $rootDevice->id, 'name' => $tlsMonitorName],
    [
        'profile_id' => $profile->id,
        'kind' => 'tls',
        'target' => 'portal.example.test:443',
        'config' => ['private' => $rawSentinel],
        'current_state' => 'degraded',
        'effective_state' => 'degraded',
        'pending_count' => 0,
        'affects_availability' => false,
        'is_enabled' => true,
        'last_observation_at' => now()->subMinute(),
    ],
);

$networkGateway = \\App\\Domain\\SecurityDevices\\Models\\Device::query()
    ->where('device_uid', 'PW-NET-GATEWAY')
    ->firstOrFail();
$networkSwitch = \\App\\Domain\\SecurityDevices\\Models\\Device::query()
    ->where('device_uid', 'PW-NET-SWITCH')
    ->firstOrFail();
$snapshot = \\App\\Domain\\Monitoring\\Topology\\Models\\TopologySnapshot::query()
    ->where('snapshot_uuid', '40000000-0000-4000-8000-000000000019')
    ->first();
if (! $snapshot) {
    $snapshot = \\App\\Domain\\Monitoring\\Topology\\Models\\TopologySnapshot::query()->create([
        'site_id' => $allowedSite->id,
        'snapshot_uuid' => '40000000-0000-4000-8000-000000000019',
        'source' => 'native:lldp-snmp',
        'source_checkpoint_hash' => hash('sha256', 'pw-native-topology-checkpoint'),
        'captured_at' => now()->subMinutes(6),
        'completed_at' => now()->subMinutes(5),
        'status' => 'completed',
        'node_count' => 2,
        'edge_count' => 1,
        'change_count' => 2,
        'summary' => ['sources' => ['lldp', 'snmp'], 'changes' => ['added' => 1, 'removed' => 1, 'changed' => 0]],
    ]);
    $gatewayNode = \\App\\Domain\\Monitoring\\Topology\\Models\\TopologyNode::query()->create([
        'topology_snapshot_id' => $snapshot->id,
        'canonical_device_id' => $networkGateway->id,
        'node_key_hash' => hash('sha256', 'pw-native-topology-gateway'),
    ]);
    $switchNode = \\App\\Domain\\Monitoring\\Topology\\Models\\TopologyNode::query()->create([
        'topology_snapshot_id' => $snapshot->id,
        'canonical_device_id' => $networkSwitch->id,
        'node_key_hash' => hash('sha256', 'pw-native-topology-switch'),
    ]);
    $edgeHash = hash('sha256', 'pw-native-topology-edge');
    $edge = \\App\\Domain\\Monitoring\\Topology\\Models\\TopologyEdge::query()->create([
        'topology_snapshot_id' => $snapshot->id,
        'from_node_id' => $gatewayNode->id,
        'to_node_id' => $switchNode->id,
        'source' => 'lldp',
        'kind' => 'uplinks_to',
        'local_port' => 'PW-WAN1',
        'remote_port' => 'PW-UPLINK1',
        'confidence' => 0.97,
        'evidence' => ['protocol' => 'lldp'],
        'evidence_hash' => hash('sha256', 'pw-native-topology-evidence'),
        'edge_hash' => $edgeHash,
        'content_hash' => hash('sha256', 'pw-native-topology-content'),
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now()->subMinutes(6),
    ]);
    foreach ([['added', $edge->id], ['removed', null]] as [$changeType, $afterEdgeId]) {
        \\App\\Domain\\Monitoring\\Topology\\Models\\TopologyChange::query()->create([
            'current_snapshot_id' => $snapshot->id,
            'change_type' => $changeType,
            'edge_hash' => hash('sha256', 'pw-native-topology-'.$changeType),
            'after_edge_id' => $afterEdgeId,
            'evidence' => ['source' => 'native_runtime'],
        ]);
    }
}

if (! \\App\\Domain\\Monitoring\\Models\\MonitoringDeadLetter::query()
    ->where('message_id', 'pw-native-replayable')
    ->exists()) {
    \\App\\Domain\\Monitoring\\Models\\MonitoringDeadLetter::query()->create([
        'message_id' => 'pw-native-replayable',
        'consumer' => 'event-projector',
        'source' => 'main_application',
        'sequence' => 19,
        'idempotency_key' => 'pw-native-replayable-key',
        'reason_code' => 'projection_temporarily_unavailable',
        'reason_message' => 'Replayable after operator review.',
        'envelope_bytes' => '{"fixture":"redacted"}',
        'site_id' => $allowedSite->id,
        'replay_count' => 0,
    ]);
}

$restrictedEmail = 'pw-native-site-operator@demo.test';
$restricted = \\App\\Models\\User::query()->updateOrCreate(
    ['email' => $restrictedEmail],
    [
        'name' => 'Playwright Site Operator',
        'password' => \\Illuminate\\Support\\Facades\\Hash::make('password'),
        'email_verified_at' => now(),
        'approved_at' => now(),
        'approved_by' => $admin->id,
    ],
);
$role = \\App\\Models\\Role::query()->where('name', 'support_worker')->firstOrFail();
$restricted->roles()->syncWithoutDetaching([$role->id]);
$permissionIds = \\App\\Models\\Permission::query()->whereIn('key', [
    'securityDevices.viewAny',
    'securityDevices.devices.view',
    'securityDevices.events.view',
    'securityDevices.integrations.view',
    'securityDevices.reports.view',
    'controlRoom.alerts.view',
    'it.view',
])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all();
$restricted->permissionOverrides()->syncWithoutDetaching($permissionIds);
\\App\\Domain\\Hr\\Models\\HrEmployeeProfile::query()->updateOrCreate(
    ['user_id' => $restricted->id],
    [
        'employee_number' => 'PW-NATIVE-SITE-OPERATOR',
        'work_email' => $restricted->email,
        'position_title' => 'Site Operator',
        'position_role' => 'support_worker',
        'primary_site_id' => $allowedSite->id,
        'secondary_site_ids' => [],
        'employment_status' => 'active',
        'employment_type' => 'full_time',
        'start_date' => today()->subYear(),
        'is_active' => true,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ],
);

echo json_encode([
    'allowedSiteName' => $allowedSite->name,
    'hiddenSiteName' => $hiddenSite->name,
    'hiddenSiteId' => $hiddenSite->id,
    'hiddenDeviceId' => $hiddenDevice->id,
    'hiddenDeviceName' => $hiddenDevice->name,
    'restrictedEmail' => $restrictedEmail,
    'rootMonitorName' => $rootMonitor->name,
    'symptomNames' => $symptomNames,
    'controlRoomReference' => $alert->reference_number,
    'ticketReference' => $ticket->reference,
    'collectorName' => $collector->name,
    'collectorUuid' => $collector->collector_uuid,
    'discoveryScopeName' => $scope->name,
    'matchedDeviceName' => $rootDevice->name,
    'topologySource' => 'native:lldp-snmp',
    'tlsMonitorName' => $tlsMonitorName,
    'retentionPolicyName' => $retention->name,
    'configurationSnapshotLabel' => 'Approved read only',
    'rawSentinel' => $rawSentinel,
]);
`);

    const jsonStart = output.lastIndexOf('{"allowedSiteName"');
    if (jsonStart === -1) throw new Error(output.trim());

    return {
        ...(JSON.parse(output.slice(jsonStart)) as Omit<
            NativeMonitoringRuntimeFixture,
            'operations' | 'network'
        >),
        operations,
        network,
    };
}

export function markCollectorOrderedReturn(collectorUuid: string) {
    runLaravelPhp(`
$collector = \\App\\Domain\\Monitoring\\Models\\MonitoringCollector::query()
    ->where('collector_uuid', '${collectorUuid}')
    ->firstOrFail();
$collector->forceFill([
    'status' => 'online',
    'runtime_state' => 'healthy',
    'backlog_items' => 0,
    'gap_count' => 0,
    'last_heartbeat_at' => now(),
    'last_seen_at' => now(),
])->save();
`);
}

export function markCollectorOutage(collectorUuid: string) {
    runLaravelPhp(`
$collector = \\App\\Domain\\Monitoring\\Models\\MonitoringCollector::query()
    ->where('collector_uuid', '${collectorUuid}')
    ->firstOrFail();
$collector->forceFill([
    'status' => 'offline',
    'runtime_state' => 'unavailable',
    'backlog_items' => 7,
    'gap_count' => 1,
    'last_heartbeat_at' => now()->subMinutes(20),
    'last_seen_at' => now()->subMinutes(20),
])->save();
`);
}
