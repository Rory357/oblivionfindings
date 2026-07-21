import { runLaravelPhp } from './helpers';

export function seedOperationsWorkspaceFixtures() {
    type Fixture = {
        siteName: string;
        directDeviceName: string;
        directMonitorName: string;
        remoteDeviceName: string;
        remoteMonitorName: string;
        collectorName: string;
        overdueWork: string;
        calibrationWork: string;
        firmwareWork: string;
        configurationWork: string;
        rawSentinel: string;
    };
    const output = runLaravelPhp(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$tenantId = (int) ($admin->organization_id ?? 1);
\\Illuminate\\Support\\Facades\\Cache::put(
    "tasks.nav.{$admin->id}",
    ['view' => false, 'badge' => 0],
    now()->addMinutes(30),
);
$site = \\App\\Models\\Site::query()
    ->where('tenant_id', $tenantId)
    ->where('is_active', true)
    ->orderBy('id')
    ->first();
if (! $site) {
    $site = \\App\\Models\\Site::forceCreate([
        'tenant_id' => $tenantId,
        'name' => 'Playwright Operations Site',
        'type' => 'facility',
        'is_active' => true,
    ]);
}
$rawSentinel = 'PW-OPERATIONS-PRIVATE-EVIDENCE-MUST-NOT-RENDER';

$upsertDevice = function (string $uid, string $name) use ($tenantId, $admin, $site, $rawSentinel) {
    $device = \\App\\Domain\\SecurityDevices\\Models\\Device::withTrashed()
        ->where('tenant_id', $tenantId)
        ->where('device_uid', $uid)
        ->first();
    if (! $device) {
        $device = new \\App\\Domain\\SecurityDevices\\Models\\Device([
            'tenant_id' => $tenantId,
            'device_uid' => $uid,
        ]);
    } elseif ($device->trashed()) {
        $device->restore();
    }
    $device->forceFill([
        'name' => $name,
        'domain' => 'it_infrastructure',
        'category' => 'networking',
        'subcategory' => 'managed_switch',
        'manufacturer' => 'Oblivion Native',
        'model' => 'Playwright operations device',
        'serial_number' => $uid.'-SERIAL',
        'provider' => 'oblivion_native',
        'status' => 'active',
        'health_status' => 'healthy',
        'last_seen_at' => now(),
        'config' => ['private_runtime' => $rawSentinel],
        'meta' => ['private_context' => $rawSentinel],
        'created_by_user_id' => $admin->id,
    ])->save();

    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::query()
        ->where('device_id', $device->id)
        ->delete();
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);

    return $device;
};

$directDeviceName = 'Playwright main SD-WAN gateway';
$remoteDeviceName = 'Playwright remote care switch';
$directDevice = $upsertDevice('PW-OPS-DIRECT', $directDeviceName);
$remoteDevice = $upsertDevice('PW-OPS-REMOTE', $remoteDeviceName);

$profile = \\App\\Domain\\Monitoring\\Models\\MonitoringProfile::query()->updateOrCreate(
    ['name' => 'Playwright operations profile'],
    [
        'description' => 'Browser acceptance evidence for native monitoring operations.',
        'interval_seconds' => 60,
        'failure_confirmations' => 3,
        'recovery_confirmations' => 2,
        'stale_after_seconds' => 300,
        'is_active' => true,
    ],
);
$collectorName = 'Playwright remote collector';
$collector = \\App\\Domain\\Monitoring\\Models\\MonitoringCollector::query()->updateOrCreate(
    ['collector_uuid' => '0f25e52a-b2b4-42bf-8d4a-145f82cd4301'],
    [
        'name' => $collectorName,
        'site_id' => $site->id,
        'status' => 'offline',
        'last_seen_at' => now()->subMinutes(15),
        'config' => ['token' => $rawSentinel],
    ],
);

$directMonitorName = 'Playwright direct WAN availability';
$remoteMonitorName = 'Playwright remote SNMP availability';
$directMonitor = \\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
    ['device_id' => $directDevice->id, 'name' => $directMonitorName],
    [
        'profile_id' => $profile->id,
        'collector_id' => null,
        'kind' => 'icmp',
        'target' => '10.10.10.1?secret='.$rawSentinel,
        'config' => ['credential' => $rawSentinel],
        'current_state' => 'healthy',
        'pending_count' => 0,
        'affects_availability' => true,
        'is_enabled' => true,
        'last_observation_at' => now()->subMinute(),
        'last_state_changed_at' => now()->subHour(),
    ],
);
$remoteMonitor = \\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
    ['device_id' => $remoteDevice->id, 'name' => $remoteMonitorName],
    [
        'profile_id' => $profile->id,
        'collector_id' => $collector->id,
        'kind' => 'snmp',
        'target' => 'snmp://private/'.$rawSentinel,
        'config' => ['community' => $rawSentinel],
        'current_state' => 'failed',
        'pending_count' => 0,
        'affects_availability' => true,
        'is_enabled' => true,
        'last_observation_at' => now()->subMinutes(14),
        'last_state_changed_at' => now()->subMinutes(14),
    ],
);

foreach ([
    [$directMonitor, 'pw-ops-direct-current', 'healthy', 11.4, 'ms', now()->subMinute()],
    [$directMonitor, 'pw-ops-direct-previous', 'healthy', 13.7, 'ms', now()->subMinutes(3)],
    [$remoteMonitor, 'pw-ops-remote-current', 'failed', null, null, now()->subMinutes(14)],
] as [$monitor, $sourceKey, $state, $value, $unit, $observedAt]) {
    \\App\\Domain\\Monitoring\\Models\\MonitorObservation::query()
        ->where('monitor_id', $monitor->id)
        ->where('source_key', $sourceKey)
        ->delete();
    \\App\\Domain\\Monitoring\\Models\\MonitorObservation::query()->create([
        'monitor_id' => $monitor->id,
        'device_id' => $monitor->device_id,
        'site_id' => $site->id,
        'collector_id' => $monitor->collector_id,
        'source_key' => $sourceKey,
        'state' => $state,
        'value' => $value,
        'unit' => $unit,
        'latency_ms' => 12,
        'message' => $rawSentinel,
        'metrics' => ['private_payload' => $rawSentinel],
        'observed_at' => $observedAt,
        'ingested_at' => $observedAt,
    ]);
}

$maintenance = [
    ['type' => 'repair', 'status' => 'scheduled', 'description' => 'Playwright overdue gateway repair', 'scheduled_for' => now()->subDay()],
    ['type' => 'calibration', 'status' => 'scheduled', 'description' => 'Playwright switch calibration', 'scheduled_for' => now()->addDays(3)],
    ['type' => 'firmware_update', 'status' => 'in_progress', 'description' => 'Playwright firmware rollout', 'scheduled_for' => now()],
    ['type' => 'configuration_change', 'status' => 'scheduled', 'description' => 'Playwright approved configuration baseline', 'scheduled_for' => now()->addDays(30)],
];
foreach ($maintenance as $record) {
    \\App\\Domain\\SecurityDevices\\Models\\DeviceMaintenanceRecord::query()->updateOrCreate(
        ['device_id' => $directDevice->id, 'type' => $record['type'], 'description' => $record['description']],
        [
            'status' => $record['status'],
            'scheduled_for' => $record['scheduled_for']->toDateString(),
            'vendor_reference' => 'PW-OPS-'.strtoupper($record['type']),
            'notes' => $rawSentinel,
        ],
    );
}

echo json_encode([
    'siteName' => $site->name,
    'directDeviceName' => $directDeviceName,
    'directMonitorName' => $directMonitorName,
    'remoteDeviceName' => $remoteDeviceName,
    'remoteMonitorName' => $remoteMonitorName,
    'collectorName' => $collectorName,
    'overdueWork' => $maintenance[0]['description'],
    'calibrationWork' => $maintenance[1]['description'],
    'firmwareWork' => $maintenance[2]['description'],
    'configurationWork' => $maintenance[3]['description'],
    'rawSentinel' => $rawSentinel,
]);
`);

    try {
        return JSON.parse(output) as Fixture;
    } catch {
        throw new Error(`Operations fixture failed:\n${output}`);
    }
}
