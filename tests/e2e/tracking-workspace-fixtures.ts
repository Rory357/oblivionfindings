import { runLaravelPhp } from './helpers';

export function seedTrackingWorkspaceReadinessFixtures() {
    type Fixture = {
        activeClientName: string;
        activeClientProfileName: string;
        activeClientId: number;
        activeDeviceName: string;
        activeDeviceId: number;
        withdrawnClientName: string;
        withdrawnDeviceName: string;
        vehicleName: string;
        vehicleDeviceName: string;
        assetName: string;
        assetDeviceName: string;
        geofenceName: string;
        historyEventLabel: string;
        rawSentinel: string;
    };

    const output = runLaravelPhp(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$site = \\App\\Models\\Site::query()
    ->where('archived', false)
    ->orderBy('id')
    ->firstOrFail();

$upsertClient = function (string $firstName, string $preferredName) use ($site, $admin) {
    $client = \\App\\Models\\Client::withTrashed()
        ->where('site_id', $site->id)
        ->where('first_name', $firstName)
        ->where('last_name', 'Tracking')
        ->first();
    if (! $client) {
        $client = new \\App\\Models\\Client();
    } elseif ($client->trashed()) {
        $client->restore();
    }

    $client->forceFill([
        'site_id' => $site->id,
        'first_name' => $firstName,
        'last_name' => 'Tracking',
        'preferred_name' => $preferredName,
        'date_of_birth' => '1990-01-01',
        'status' => 'active',
        'key_worker_id' => $admin->id,
    ])->save();

    return $client;
};

$activeClient = $upsertClient('Playwright Active', 'Mere Active');
$withdrawnClient = $upsertClient('Playwright Withdrawn', 'Ria Withdrawn');

$consentType = \\App\\Models\\ConsentType::withTrashed()
    ->where('name', 'Asset Location Tracking (Safety)')
    ->first();
if (! $consentType) {
    $consentType = new \\App\\Models\\ConsentType();
} elseif ($consentType->trashed()) {
    $consentType->restore();
}
$consentType->forceFill([
    'name' => 'Asset Location Tracking (Safety)',
    'category' => 'privacy',
    'description' => 'Personal location tracking used for safety.',
    'purpose' => 'Personal safety location tracking',
    'legal_basis' => 'consent',
    'allows_withdrawal' => true,
    'active' => true,
])->save();

$upsertConsent = function ($client, string $status) use ($consentType, $admin) {
    $consent = \\App\\Models\\ClientConsent::withTrashed()
        ->where('client_id', $client->id)
        ->where('consent_type_id', $consentType->id)
        ->first();
    if (! $consent) {
        $consent = new \\App\\Models\\ClientConsent();
    } elseif ($consent->trashed()) {
        $consent->restore();
    }

    $consent->forceFill([
        'client_id' => $client->id,
        'consent_type_id' => $consentType->id,
        'status' => $status,
        'given_at' => now()->subDay(),
        'given_by_user_id' => $admin->id,
        'given_method' => 'written',
        'withdrawn_at' => $status === 'withdrawn' ? now()->subHour() : null,
        'withdrawn_by_user_id' => $status === 'withdrawn' ? $admin->id : null,
        'expires_at' => now()->addMonth(),
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ])->save();

    return $consent;
};

$activeConsent = $upsertConsent($activeClient, 'given');
$withdrawnConsent = $upsertConsent($withdrawnClient, 'withdrawn');

$upsertAsset = function (string $assetTag, string $name, string $category) use ($site, $admin) {
    return \\App\\Models\\Asset::query()->updateOrCreate(
        ['asset_tag' => $assetTag],
        [
            'site_id' => $site->id,
            'client_id' => null,
            'name' => $name,
            'category' => $category,
            'status' => 'active',
            'risk_level' => 'low',
            'location' => 'Playwright tracking fixture',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ],
    );
};

$vehicle = $upsertAsset('PW-TRACK-VEH', 'Playwright community van', 'Vehicle');
$asset = $upsertAsset('PW-TRACK-ASSET', 'Playwright emergency generator', 'Safety Equipment');
$clientAsset = \\App\\Models\\Asset::query()->updateOrCreate(
    ['asset_tag' => 'PW-TRACK-CLIENT'],
    [
        'site_id' => $site->id,
        'client_id' => $activeClient->id,
        'name' => 'Mere safety pendant asset',
        'category' => 'personal_tracker',
        'status' => 'active',
        'risk_level' => 'low',
        'location' => 'Assigned to Mere Active',
        'created_by_user_id' => $admin->id,
        'updated_by_user_id' => $admin->id,
    ],
);

$upsertDevice = function (string $uid, string $name, string $category, array $attributes = []) use ($admin) {
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
        'name' => $name,
        'domain' => 'tracking',
        'category' => $category,
        'subcategory' => 'playwright_tracker',
        'manufacturer' => 'Oblivion Native',
        'provider' => 'oblivion_native',
        'status' => 'active',
        'health_status' => 'healthy',
        'last_seen_at' => now(),
        'battery_level' => 78,
        'battery_updated_at' => now(),
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'created_by_user_id' => $admin->id,
    ], $attributes))->save();

    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::query()
        ->where('device_id', $device->id)
        ->delete();
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssetLink::query()
        ->where('device_id', $device->id)
        ->delete();

    return $device;
};

$assign = function ($device, string $type, int $id, ?int $consentId = null) {
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => $type,
        'assignable_id' => $id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'consent_id' => $consentId,
    ]);
};
$link = function ($device, $asset, string $type = 'primary') use ($admin) {
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssetLink::create([
        'device_id' => $device->id,
        'asset_id' => $asset->id,
        'link_type' => $type,
        'linked_at' => now(),
        'linked_by_user_id' => $admin->id,
    ]);
};

$rawSentinel = 'PW-RAW-TRACKING-PROVIDER-ENVELOPE-MUST-NOT-RENDER';
$activeDevice = $upsertDevice('PW-TRACK-ACTIVE', 'Playwright active safety pendant', 'personal_tracker', [
    'meta' => ['private_location_envelope' => $rawSentinel],
]);
$assign($activeDevice, \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_CLIENT, $activeClient->id, $activeConsent->id);
$link($activeDevice, $clientAsset);

$withdrawnDevice = $upsertDevice('PW-TRACK-WITHDRAWN', 'Playwright withdrawn safety pendant', 'personal_tracker', [
    'latitude' => -36.86,
    'longitude' => 174.77,
]);
$assign($withdrawnDevice, \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_CLIENT, $withdrawnClient->id, $withdrawnConsent->id);

$vehicleDevice = $upsertDevice('PW-TRACK-VEHICLE', 'Playwright van telematics', 'vehicle_tracker');
$assign($vehicleDevice, \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_VEHICLE, $vehicle->id);
$link($vehicleDevice, $vehicle, 'installed_in');

$assetDevice = $upsertDevice('PW-TRACK-ASSET', 'Playwright generator tag', 'asset_tracker');
$link($assetDevice, $asset);

$geofenceName = 'Playwright fleet depot';
\\App\\Models\\AssetGeofence::query()->updateOrCreate(
    ['asset_id' => $vehicle->id, 'name' => $geofenceName],
    [
        'site_id' => $site->id,
        'type' => 'circle',
        'scope' => 'vehicle',
        'shape' => ['lat' => -36.85, 'lng' => 174.76, 'radius_m' => 200],
        'breach_type' => 'both',
        'is_active' => true,
    ],
);

$legacyTracker = \\App\\Models\\AssetTracker::query()->updateOrCreate(
    ['vendor' => 'queclink', 'device_uid' => 'PW-TRACK-LEGACY'],
    [
        'asset_id' => $vehicle->id,
        'status' => 'paired',
        'paired_at' => now()->subDay(),
        'last_seen_at' => now(),
    ],
);
$historyEventLabel = 'playwright_location_report';
\\App\\Models\\FleetTelemetryEvent::query()->updateOrCreate(
    ['idempotency_key' => hash('sha256', 'PW-TRACK-HISTORY')],
    [
        'asset_id' => $vehicle->id,
        'asset_tracker_id' => $legacyTracker->id,
        'device_id' => $vehicleDevice->id,
        'vendor' => 'queclink',
        'occurred_at' => now()->subMinute(),
        'received_at' => now()->subMinute(),
        'latitude' => -36.8485,
        'longitude' => 174.7633,
        'battery_pct' => 78,
        'event_type' => $historyEventLabel,
        'raw_payload' => ['private' => $rawSentinel],
        'consent_blocked' => false,
    ],
);

echo json_encode([
    'activeClientName' => $activeClient->preferred_name,
    'activeClientProfileName' => $activeClient->full_name,
    'activeClientId' => $activeClient->id,
    'activeDeviceName' => $activeDevice->name,
    'activeDeviceId' => $activeDevice->id,
    'withdrawnClientName' => $withdrawnClient->preferred_name,
    'withdrawnDeviceName' => $withdrawnDevice->name,
    'vehicleName' => $vehicle->name,
    'vehicleDeviceName' => $vehicleDevice->name,
    'assetName' => $asset->name,
    'assetDeviceName' => $assetDevice->name,
    'geofenceName' => $geofenceName,
    'historyEventLabel' => $historyEventLabel,
    'rawSentinel' => $rawSentinel,
]);
`);

    const jsonStart = output.lastIndexOf('{"activeClientName"');
    if (jsonStart === -1) {
        throw new Error(output.trim());
    }

    return JSON.parse(output.slice(jsonStart)) as Fixture;
}
