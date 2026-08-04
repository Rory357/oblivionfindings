import { runLaravelJson } from './helpers';

export function seedDeviceProfileReadinessFixtures() {
    return runLaravelJson<{
        deviceId: number;
        deviceName: string;
        siteName: string;
        monitorName: string;
        interfaceName: string;
        ticketTitle: string;
        controlRoomReference: string;
        controlRoomAllowed: boolean;
        controlRoomAlertCount: number;
        auditAction: string;
        rawSentinel: string;
    }>(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$rawSentinel = 'PLAYWRIGHT-DEVICE-PROFILE-RAW-SENTINEL';
$site = \\App\\Models\\Site::query()
    ->where('archived', false)
    ->orderBy('id')
    ->first();
if (! $site) {
    $site = \\App\\Models\\Site::factory()->create([
        'name' => 'Playwright device profile site',
    ]);
}

$device = \\App\\Domain\\SecurityDevices\\Models\\Device::withTrashed()
    ->where('device_uid', 'PW-DEVICE-PROFILE')
    ->first();
if (! $device) {
    $device = new \\App\\Domain\\SecurityDevices\\Models\\Device([
        'device_uid' => 'PW-DEVICE-PROFILE',
    ]);
} elseif ($device->trashed()) {
    $device->restore();
}
$device->forceFill([
    'name' => 'Playwright device profile edge',
    'domain' => 'it_infrastructure',
    'category' => 'networking',
    'subcategory' => 'gateway',
    'manufacturer' => 'Oblivion Demo',
    'model' => 'Managed Edge',
    'serial_number' => 'PW-PROFILE-001',
    'mac_address' => '02:00:00:00:00:41',
    'ip_address' => '192.0.2.41',
    'firmware_version' => '4.1.0',
    'status' => 'offline',
    'health_status' => 'critical',
    'last_seen_at' => now()->subMinutes(20),
    'provider' => 'oblivion_native',
    'external_ref' => ['raw' => $rawSentinel],
    'config' => [
        'raw' => $rawSentinel,
        'management' => [
            'capabilities' => ['diagnostics.ping', 'device.reboot'],
        ],
    ],
    'meta' => ['raw' => $rawSentinel],
    'notes' => 'Primary WAN edge device for the Playwright site.',
    'commissioned_at' => now()->subYear(),
    'warranty_expires_at' => now()->addYear(),
    'next_service_due' => now()->addMonth(),
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

$profile = \\App\\Domain\\Monitoring\\Models\\MonitoringProfile::query()->firstOrCreate(
    ['name' => 'Playwright device profile monitoring'],
    [
        'description' => 'Device profile browser acceptance',
        'interval_seconds' => 60,
        'failure_confirmations' => 2,
        'recovery_confirmations' => 2,
        'stale_after_seconds' => 300,
        'is_active' => true,
    ],
);
$monitor = \\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
    [
        'device_id' => $device->id,
        'name' => 'WAN interface health',
    ],
    [
        'profile_id' => $profile->id,
        'kind' => 'snmp_interface',
        'target' => $rawSentinel,
        'config' => ['community' => $rawSentinel],
        'current_state' => 'failed',
        'pending_state' => null,
        'pending_count' => 0,
        'affects_availability' => true,
        'is_enabled' => true,
        'last_observation_at' => now(),
        'last_state_changed_at' => now(),
    ],
);
\\App\\Domain\\Monitoring\\Models\\MonitorObservation::query()
    ->where('monitor_id', $monitor->id)
    ->where('source_key', 'playwright-device-profile')
    ->delete();
\\App\\Domain\\Monitoring\\Models\\MonitorObservation::create([
    'monitor_id' => $monitor->id,
    'device_id' => $device->id,
    'site_id' => $site->id,
    'collector_id' => $monitor->collector_id,
    'source_key' => 'playwright-device-profile',
    'state' => 'failed',
    'value' => 91.4,
    'unit' => '%',
    'message' => $rawSentinel,
    'metrics' => [
        'interface_name' => 'wan0',
        'if_index' => 4,
        'operational_status' => 'down',
        'speed_bps' => 1000000000,
        'in_bps' => 850000000,
        'out_bps' => 620000000,
        'in_utilization_pct' => 85,
        'out_utilization_pct' => 62,
        'secret' => $rawSentinel,
    ],
    'observed_at' => now(),
    'ingested_at' => now(),
]);

$ticket = \\App\\Models\\ItTicket::query()
    ->where('title', 'Investigate Playwright profile edge')
    ->first();
if (! $ticket) {
    $ticket = \\App\\Models\\ItTicket::factory()->create([
        'requester_user_id' => $admin->id,
        'title' => 'Investigate Playwright profile edge',
        'status' => 'open',
        'priority' => 'urgent',
        'work_type' => 'incident',
    ]);
}
\\App\\Models\\ItTicketLink::query()->updateOrCreate(
    [
        'ticket_id' => $ticket->id,
        'relationship' => 'affected_device',
        'linkable_type' => \\App\\Domain\\SecurityDevices\\Models\\Device::class,
        'linkable_id' => $device->id,
    ],
    [
        'context' => ['raw' => $rawSentinel],
        'created_by_user_id' => $admin->id,
    ],
);

\\App\\Models\\AuditLog::query()
    ->where('auditable_type', \\App\\Domain\\SecurityDevices\\Models\\Device::class)
    ->where('auditable_id', $device->id)
    ->where('action', 'playwright.device.profile')
    ->delete();
\\App\\Models\\AuditLog::query()->create([
    'user_id' => $admin->id,
    'action' => 'playwright.device.profile',
    'auditable_type' => \\App\\Domain\\SecurityDevices\\Models\\Device::class,
    'auditable_id' => $device->id,
    'meta' => [
        'fields' => ['firmware_version'],
        'after' => ['raw' => $rawSentinel],
    ],
]);

$projection = \\App\\Models\\ControlRoom\\Device::query()->updateOrCreate(
    ['canonical_device_id' => $device->id],
    [
        'name' => $device->name,
        'type' => \\App\\Models\\ControlRoom\\Device::TYPE_ALARM_PANEL,
        'site_id' => $site->id,
        'status' => 'offline',
    ],
);
$controlRoomAlert = \\App\\Models\\ControlRoomAlert::query()->firstOrCreate(
    [
        'source' => 'security_devices',
        'alert_type' => 'Playwright device profile alert',
        'device_id' => $projection->id,
    ],
    [
        'severity' => 'critical',
        'status' => \\App\\Models\\ControlRoomAlert::STATUS_OPEN,
        'site_id' => $site->id,
        'triggered_at' => now(),
    ],
);
$controlRoomAlert->forceFill([
    'reference_number' => 'CR-PW-DEVICE-PROFILE',
    'status' => \\App\\Models\\ControlRoomAlert::STATUS_OPEN,
    'site_id' => $site->id,
    'triggered_at' => now(),
])->save();
$controlRoomQuery = \\App\\Models\\ControlRoomAlert::query()
    ->whereIn('status', \\App\\Models\\ControlRoomAlert::ACTIVE_STATUSES)
    ->whereHas('device', fn ($query) => $query->where('canonical_device_id', $device->id));
app(\\App\\Services\\UserSiteAccessService::class)->applyAlertScope(
    $controlRoomQuery,
    $admin,
    ['reports.viewAny'],
);

echo json_encode([
    'deviceId' => $device->id,
    'deviceName' => $device->name,
    'siteName' => $site->name,
    'monitorName' => $monitor->name,
    'interfaceName' => 'wan0',
    'ticketTitle' => $ticket->title,
    'controlRoomReference' => $controlRoomAlert->reference_number,
    'controlRoomAllowed' => $admin->canDo('controlRoom.viewAny') || $admin->canDo('controlRoom.alerts.view'),
    'controlRoomAlertCount' => $controlRoomQuery->count(),
    'auditAction' => 'playwright device profile',
    'rawSentinel' => $rawSentinel,
]);
`);
}
