import { runLaravelJson } from './helpers';

export function seedSecurityWorkspaceReadinessFixtures() {
    return runLaravelJson<{
        siteName: string;
        cameraName: string;
        alarmName: string;
        doorName: string;
        alertTitle: string;
    }>(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$site = \\App\\Models\\Site::query()
    ->where('archived', false)
    ->orderBy('id')
    ->firstOrFail();

$upsertDevice = function (string $uid, array $attributes) use ($site) {
    $device = \\App\\Domain\\SecurityDevices\\Models\\Device::withTrashed()
        ->where('device_uid', $uid)
        ->first();

    if (! $device) {
        $device = new \\App\\Domain\\SecurityDevices\\Models\\Device([
            'device_uid' => $uid,
        ]);
    } elseif ($device->trashed()) {
        $device->restore();
    }

    $device->forceFill(array_merge([
        'domain' => 'security',
        'manufacturer' => 'Oblivion Demo',
        'provider' => 'unifi',
        'status' => 'active',
        'health_status' => 'healthy',
        'last_seen_at' => now(),
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

$camera = $upsertDevice('PW-SEC-CAMERA', [
    'name' => 'Playwright reception camera',
    'category' => 'cctv',
    'subcategory' => 'dome_camera',
    'config' => [
        'stream_health' => 'healthy',
        'recording_health' => 'degraded',
        'media_href' => '/security-devices/devices',
    ],
]);
$alarm = $upsertDevice('PW-SEC-ALARM', [
    'name' => 'Playwright main alarm panel',
    'category' => 'alarm',
    'subcategory' => 'panel',
    'config' => [
        'alarm_state' => 'armed',
        'zones' => ['total' => 8, 'faulted' => 1],
    ],
]);
$door = $upsertDevice('PW-SEC-DOOR', [
    'name' => 'Playwright staff entrance reader',
    'category' => 'access_control',
    'subcategory' => 'card_reader',
    'config' => [
        'door_state' => 'secured',
        'credential_count' => 42,
        'schedule_count' => 3,
    ],
]);

\\App\\Domain\\SecurityDevices\\Models\\DeviceMaintenanceRecord::query()->updateOrCreate(
    [
        'device_id' => $camera->id,
        'type' => 'inspection',
        'description' => 'Playwright lens and recording inspection',
    ],
    [
        'status' => 'scheduled',
        'scheduled_for' => now()->addDays(2)->toDateString(),
    ],
);

foreach ([
    [$alarm, 'alarm_trigger', 'critical'],
    [$door, 'door_opened', 'info'],
] as [$device, $type, $severity]) {
    \\App\\Domain\\SecurityDevices\\Models\\DeviceEvent::query()
        ->where('device_id', $device->id)
        ->where('source', 'playwright_security_workspace')
        ->delete();
    \\App\\Domain\\SecurityDevices\\Models\\DeviceEvent::create([
        'device_id' => $device->id,
        'event_type' => $type,
        'severity' => $severity,
        'source' => 'playwright_security_workspace',
        'occurred_at' => now(),
    ]);
}

$projection = \\App\\Models\\ControlRoom\\Device::query()->updateOrCreate(
    ['canonical_device_id' => $alarm->id],
    [
        'name' => $alarm->name,
        'device_uid' => 'pw-sec-alarm-projection',
        'type' => \\App\\Models\\ControlRoom\\Device::TYPE_ALARM_PANEL,
        'site_id' => $site->id,
        'status' => 'online',
    ],
);
$alertTitle = 'Playwright reception alarm';
\\App\\Models\\ControlRoomAlert::query()->updateOrCreate(
    [
        'source' => 'security_devices',
        'alert_type' => $alertTitle,
        'device_id' => $projection->id,
    ],
    [
        'site_id' => $site->id,
        'severity' => 'critical',
        'status' => \\App\\Models\\ControlRoomAlert::STATUS_OPEN,
        'triggered_at' => now(),
    ],
);

echo json_encode([
    'siteName' => $site->name,
    'cameraName' => $camera->name,
    'alarmName' => $alarm->name,
    'doorName' => $door->name,
    'alertTitle' => $alertTitle,
]);
`);
}
