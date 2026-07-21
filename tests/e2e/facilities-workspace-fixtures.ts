import { runLaravelJson } from './helpers';

export function seedFacilitiesWorkspaceReadinessFixtures() {
    return runLaravelJson<{
        siteName: string;
        environmentName: string;
        environmentMonitorName: string;
        buildingName: string;
        utilityName: string;
        automationName: string;
        automationEvidenceName: string;
        thresholdLabel: string;
        integrationName: string;
        rawSentinel: string;
    }>(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$tenantId = (int) ($admin->organization_id ?? 1);
$site = \\App\\Models\\Site::query()
    ->where('tenant_id', $tenantId)
    ->where('archived', false)
    ->orderBy('id')
    ->firstOrFail();

$rawSentinel = 'PW-FACILITY-RAW-PRIVATE-EVIDENCE-MUST-NOT-RENDER';
$upsertDevice = function (string $uid, string $name, string $category, string $subcategory, array $attributes = []) use ($tenantId, $admin, $site) {
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

    $device->forceFill(array_merge([
        'name' => $name,
        'domain' => 'facilities',
        'category' => $category,
        'subcategory' => $subcategory,
        'manufacturer' => 'Oblivion Native',
        'model' => 'Playwright facility device',
        'serial_number' => $uid.'-SERIAL',
        'provider' => 'oblivion_native',
        'status' => 'active',
        'health_status' => 'healthy',
        'last_seen_at' => now(),
        'created_by_user_id' => $admin->id,
    ], $attributes))->save();

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

$environmentName = 'Playwright Kauri cool room sensor';
$buildingName = 'Playwright Kauri fire panel';
$utilityName = 'Playwright Kauri backup generator';
$automationName = 'Playwright Kauri ventilation relay';
$automationEvidenceName = 'Plant room ventilation schedule';
$environment = $upsertDevice('PW-FAC-ENVIRONMENT', $environmentName, 'cold_chain', 'cool_room_sensor', [
    'config' => ['provider_envelope' => $rawSentinel],
    'meta' => ['private_sensor_context' => $rawSentinel],
]);
$building = $upsertDevice('PW-FAC-BUILDING', $buildingName, 'building_safety', 'fire_panel');
$utility = $upsertDevice('PW-FAC-UTILITY', $utilityName, 'mechanical', 'generator_monitor', [
    'provider' => 'milesight',
]);
$automation = $upsertDevice('PW-FAC-AUTOMATION', $automationName, 'facility_access', 'smart_relay', [
    'provider' => 'milesight',
    'config' => [
        'automation' => ['raw_command' => $rawSentinel],
        'provider_envelope' => $rawSentinel,
    ],
    'meta' => [
        'automation' => [
            'name' => $automationEvidenceName,
            'enabled' => true,
            'status' => 'success',
            'last_executed_at' => now()->subMinutes(18)->toIso8601String(),
            'private_result' => $rawSentinel,
        ],
    ],
]);

$profile = \\App\\Domain\\Monitoring\\Models\\MonitoringProfile::query()->updateOrCreate(
    ['name' => 'Playwright native facilities profile'],
    [
        'description' => 'Browser acceptance evidence for native Facilities and IoT.',
        'interval_seconds' => 60,
        'failure_confirmations' => 3,
        'recovery_confirmations' => 2,
        'stale_after_seconds' => 300,
        'is_active' => true,
    ],
);

$upsertMonitor = function ($device, string $name, string $kind, string $state, array $attributes = []) use ($profile) {
    return \\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
        ['device_id' => $device->id, 'name' => $name],
        array_merge([
            'profile_id' => $profile->id,
            'collector_id' => null,
            'kind' => $kind,
            'target' => $device->device_uid,
            'config' => [],
            'current_state' => $state,
            'pending_state' => null,
            'pending_count' => 0,
            'affects_availability' => true,
            'is_enabled' => true,
            'last_observation_at' => now(),
            'last_state_changed_at' => now(),
        ], $attributes),
    );
};

$environmentMonitorName = 'Playwright cool room temperature';
$environmentMonitor = $upsertMonitor($environment, $environmentMonitorName, 'provider', 'healthy', [
    'config' => ['authorization' => $rawSentinel],
]);
$buildingMonitor = $upsertMonitor($building, 'Playwright fire panel availability', 'provider', 'healthy');
$utilityMonitor = $upsertMonitor($utility, 'Playwright generator availability', 'provider', 'healthy');
$automationMonitor = $upsertMonitor($automation, 'Playwright relay availability', 'provider', 'healthy');

$observe = function ($monitor, string $sourceKey, string $state, ?float $value, ?string $unit, array $metrics = []) use ($site) {
    \\App\\Domain\\Monitoring\\Models\\MonitorObservation::query()
        ->where('monitor_id', $monitor->id)
        ->where('source_key', $sourceKey)
        ->delete();

    return \\App\\Domain\\Monitoring\\Models\\MonitorObservation::query()->create([
        'monitor_id' => $monitor->id,
        'device_id' => $monitor->device_id,
        'site_id' => $site->id,
        'collector_id' => $monitor->collector_id,
        'source_key' => $sourceKey,
        'state' => $state,
        'value' => $value,
        'unit' => $unit,
        'latency_ms' => 8,
        'metrics' => $metrics,
        'observed_at' => now(),
        'ingested_at' => now(),
    ]);
};
$observe($environmentMonitor, 'playwright-current', 'healthy', 3.2, 'C', [
    'probe' => 'cool-room-a',
    'private_payload' => $rawSentinel,
]);
$observe($buildingMonitor, 'playwright-current', 'healthy', null, null);
$observe($utilityMonitor, 'playwright-current', 'healthy', 74.5, 'percent');
$observe($automationMonitor, 'playwright-current', 'healthy', null, null);

foreach ([$environment, $building] as $device) {
    \\App\\Domain\\SecurityDevices\\Models\\DeviceEvent::query()
        ->where('device_id', $device->id)
        ->where('source', 'playwright_facilities')
        ->delete();
}
$thresholdType = 'temperature_threshold_exceeded';
\\App\\Domain\\SecurityDevices\\Models\\DeviceEvent::query()->create([
    'device_id' => $environment->id,
    'event_type' => $thresholdType,
    'severity' => 'warning',
    'payload' => ['private_provider_event' => $rawSentinel],
    'source' => 'playwright_facilities',
    'occurred_at' => now()->subMinutes(4),
]);
\\App\\Domain\\SecurityDevices\\Models\\DeviceEvent::query()->create([
    'device_id' => $building->id,
    'event_type' => 'fire_panel_restored',
    'severity' => 'info',
    'payload' => ['private_provider_event' => $rawSentinel],
    'source' => 'playwright_facilities',
    'occurred_at' => now()->subMinutes(2),
    'processed_at' => now()->subMinute(),
]);

\\App\\Domain\\SecurityDevices\\Models\\DeviceMaintenanceRecord::query()->updateOrCreate(
    [
        'device_id' => $building->id,
        'type' => 'inspection',
        'description' => 'Playwright scheduled fire-panel inspection',
    ],
    [
        'status' => 'scheduled',
        'scheduled_for' => now()->addDays(3)->toDateString(),
        'vendor_reference' => 'PW-FAC-MAINT-100',
        'notes' => $rawSentinel,
    ],
);

$integrationName = 'Milesight IoT';
\\App\\Models\\Integration\\Integration::query()->updateOrCreate(
    ['tenant_id' => $tenantId, 'provider' => 'milesight'],
    [
        'display_name' => $integrationName,
        'status' => \\App\\Models\\Integration\\Integration::STATUS_ACTIVE,
        'capabilities' => ['environmental', 'event_stream', 'utility_metering', 'private_admin'],
        'config' => ['token' => $rawSentinel],
        'last_error' => $rawSentinel,
        'last_tested_at' => now()->subHour(),
    ],
);
\\App\\Models\\Integration\\IntegrationSyncLog::query()
    ->where('tenant_id', $tenantId)
    ->where('provider', 'milesight')
    ->where('action', 'playwright_facilities_sync')
    ->delete();
\\App\\Models\\Integration\\IntegrationSyncLog::query()->create([
    'tenant_id' => $tenantId,
    'provider' => 'milesight',
    'site_id' => $site->id,
    'action' => 'playwright_facilities_sync',
    'status' => \\App\\Models\\Integration\\IntegrationSyncLog::STATUS_SUCCESS,
    'items_processed' => 4,
    'items_created' => 0,
    'items_updated' => 4,
    'items_errored' => 0,
    'error_message' => $rawSentinel,
    'started_at' => now()->subMinutes(15),
    'completed_at' => now()->subMinutes(14),
]);

echo json_encode([
    'siteName' => $site->name,
    'environmentName' => $environmentName,
    'environmentMonitorName' => $environmentMonitorName,
    'buildingName' => $buildingName,
    'utilityName' => $utilityName,
    'automationName' => $automationName,
    'automationEvidenceName' => $automationEvidenceName,
    'thresholdLabel' => \\Illuminate\\Support\\Str::headline($thresholdType),
    'integrationName' => $integrationName,
    'rawSentinel' => $rawSentinel,
]);
`);
}
