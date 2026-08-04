import { runLaravelJson } from './helpers';

export function seedHealthcareWorkspaceReadinessFixtures() {
    return runLaravelJson<{
        clientDisplayName: string;
        clientDeviceName: string;
        sharedDeviceName: string;
        siteName: string;
        supportName: string;
        ticketReference: string;
        calibrationDescription: string;
        clinicalSentinel: string;
    }>(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$site = \\App\\Models\\Site::query()
    ->where('archived', false)
    ->orderBy('id')
    ->firstOrFail();

$client = \\App\\Models\\Client::withTrashed()
    ->where('site_id', $site->id)
    ->where('first_name', 'Playwright')
    ->where('last_name', 'Healthcare')
    ->first();
if (! $client) {
    $client = new \\App\\Models\\Client();
} elseif ($client->trashed()) {
    $client->restore();
}
$client->forceFill([
    'site_id' => $site->id,
    'first_name' => 'Playwright',
    'last_name' => 'Healthcare',
    'preferred_name' => 'Mere Playwright',
    'nhi_number' => 'PWH0001',
    'date_of_birth' => '1990-01-01',
    'status' => 'active',
    'key_worker_id' => $admin->id,
])->save();

$upsertDevice = function (string $uid, array $attributes, string $targetType, int $targetId, string $assignmentType = 'shared') {
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
        'domain' => 'iot_healthcare',
        'category' => 'fall_detection',
        'subcategory' => 'wearable_fall',
        'manufacturer' => 'Oblivion Demo',
        'provider' => 'oblivion_native',
        'status' => 'active',
        'health_status' => 'healthy',
        'last_seen_at' => now(),
    ], $attributes))->save();

    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::query()
        ->where('device_id', $device->id)
        ->delete();
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => $targetType,
        'assignable_id' => $targetId,
        'assignment_type' => $assignmentType,
        'assigned_at' => now(),
    ]);

    return $device;
};

$clinicalSentinel = 'PW-CLINICAL-VALUE-MUST-NOT-RENDER';
$clientDevice = $upsertDevice(
    'PW-HC-CLIENT',
    [
        'name' => 'Playwright client fall detector',
        'battery_level' => 72,
        'battery_updated_at' => now(),
        'config' => [
            'connectivity_state' => 'connected',
            'integration_state' => 'healthy',
            'last_successful_delivery_at' => now()->subMinutes(2)->toIso8601String(),
            'delivery_stale_after_minutes' => 30,
            'clinical_reading' => $clinicalSentinel,
        ],
        'meta' => ['diagnosis' => $clinicalSentinel],
    ],
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_CLIENT,
    $client->id,
    'permanent',
);
$sharedDevice = $upsertDevice(
    'PW-HC-SHARED',
    [
        'name' => 'Playwright shared nurse call',
        'category' => 'nurse_call',
        'subcategory' => 'call_button',
        'health_status' => 'warning',
        'config' => [
            'connectivity_state' => 'connected',
            'integration_state' => 'failed',
            'last_successful_delivery_at' => now()->subMinutes(2)->toIso8601String(),
        ],
    ],
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
    $site->id,
);
$upsertDevice(
    'PW-HC-OFFLINE',
    [
        'name' => 'Playwright offline bed sensor',
        'category' => 'bed_sensor',
        'status' => 'offline',
        'health_status' => 'critical',
    ],
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
    $site->id,
);
$upsertDevice(
    'PW-HC-STALE',
    [
        'name' => 'Playwright stale delivery sensor',
        'category' => 'wellness',
        'config' => [
            'connectivity_state' => 'connected',
            'integration_state' => 'healthy',
            'last_successful_delivery_at' => now()->subHours(3)->toIso8601String(),
            'delivery_stale_after_minutes' => 30,
        ],
    ],
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
    $site->id,
);
$upsertDevice(
    'PW-HC-UNSUPPORTED',
    [
        'name' => 'Playwright unsupported occupancy sensor',
        'category' => 'occupancy',
        'config' => [],
        'meta' => [],
    ],
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
    $site->id,
);

$calibrationDescription = 'Playwright annual calibration';
\\App\\Domain\\SecurityDevices\\Models\\DeviceMaintenanceRecord::query()->updateOrCreate(
    [
        'device_id' => $clientDevice->id,
        'type' => 'calibration',
        'description' => $calibrationDescription,
    ],
    [
        'status' => 'scheduled',
        'scheduled_for' => now()->subDay()->toDateString(),
        'vendor_reference' => 'PW-CAL-100',
    ],
);

$ticket = \\App\\Models\\ItTicket::query()
    ->where('title', 'Playwright restore healthcare delivery')
    ->first();
if (! $ticket) {
    $ticket = \\App\\Models\\ItTicket::createWithReference([
        'title' => 'Playwright restore healthcare delivery',
        'description' => 'Deterministic healthcare workspace browser fixture.',
        'requester_user_id' => $admin->id,
        'category' => 'hardware',
        'priority' => 'high',
        'impact' => 'individual',
        'urgency' => 'high',
        'status' => 'open',
        'source' => 'system',
        'work_type' => 'incident',
    ]);
} else {
    $ticket->forceFill(['status' => 'open'])->save();
}
\\App\\Models\\ItTicketLink::query()->updateOrCreate(
    [
        'ticket_id' => $ticket->id,
        'relationship' => 'affected_device',
        'linkable_type' => \\App\\Domain\\SecurityDevices\\Models\\Device::class,
        'linkable_id' => $clientDevice->id,
    ],
    [
        'created_by_user_id' => $admin->id,
    ],
);

echo json_encode([
    'clientDisplayName' => $client->preferred_name,
    'clientDeviceName' => $clientDevice->name,
    'sharedDeviceName' => $sharedDevice->name,
    'siteName' => $site->name,
    'supportName' => $admin->name,
    'ticketReference' => $ticket->reference,
    'calibrationDescription' => $calibrationDescription,
    'clinicalSentinel' => $clinicalSentinel,
]);
`);
}
