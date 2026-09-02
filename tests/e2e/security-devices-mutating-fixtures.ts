import { runLaravelJson } from './helpers';

export type SecurityDevicesMutatingFixture = {
    siteId: number;
    siteName: string;
    operatorEmail: string;
    approverEmail: string;
    monitorDeviceId: number;
    monitorDeviceName: string;
    monitorProfileName: string;
    monitorCidr: string;
    monitorTarget: string;
    doorAId: number;
    doorAName: string;
    doorBId: number;
    doorBName: string;
    collectorUuid: string;
    collectorName: string;
    discoveryScopeSeedName: string;
};

export function seedSecurityDevicesMutatingFixtures() {
    return runLaravelJson<SecurityDevicesMutatingFixture>(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$operatorEmail = 'pw-sd-mutate-op@demo.test';
$approverEmail = 'pw-sd-mutate-approver@demo.test';
$siteName = 'Playwright SD mutating site';
$monitorDeviceUid = 'PW-SD-MUTATE-ICMP';
$doorAUid = 'PW-SD-MUTATE-DOOR-A';
$doorBUid = 'PW-SD-MUTATE-DOOR-B';
$collectorUuid = 'a11cec01-5d00-4c01-8ec7-000000000001';
$collectorName = 'Playwright mutating remote collector';
$profileName = 'Playwright mutating monitoring profile';
$scopeSeedName = 'Playwright mutating command scope';
$monitorCidr = '10.55.0.0/24';
$monitorTarget = '10.55.0.10';
$commandCidr = '10.77.0.0/16';

$site = \\App\\Models\\Site::query()->firstOrCreate(
    ['name' => $siteName],
    ['type' => 'facility', 'is_active' => true, 'archived' => false],
);
$site->forceFill(['is_active' => true, 'archived' => false])->save();

$role = \\App\\Models\\Role::query()->where('name', 'it_manager')->firstOrFail();
$permissionIds = \\App\\Models\\Permission::query()
    ->where('key', 'like', 'securityDevices.%')
    ->pluck('id')
    ->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])
    ->all();

$makeStaff = function (string $email, string $name, string $employeeNumber) use ($admin, $role, $permissionIds, $site) {
    $user = \\App\\Models\\User::query()->updateOrCreate(
        ['email' => $email],
        [
            'name' => $name,
            'password' => \\Illuminate\\Support\\Facades\\Hash::make('password'),
            'email_verified_at' => now(),
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ],
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
    $user->permissionOverrides()->syncWithoutDetaching($permissionIds);
    \\App\\Domain\\Hr\\Models\\HrEmployeeProfile::query()->updateOrCreate(
        ['user_id' => $user->id],
        [
            'employee_number' => $employeeNumber,
            'work_email' => $user->email,
            'position_title' => 'Security devices mutating operator',
            'position_role' => 'it_manager',
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'employment_status' => 'active',
            'employment_type' => 'full_time',
            'start_date' => today()->subYear(),
            'is_active' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ],
    );

    return $user;
};

$operator = $makeStaff($operatorEmail, 'Playwright SD mutate operator', 'PW-SD-MUTATE-OP');
$makeStaff($approverEmail, 'Playwright SD mutate approver', 'PW-SD-MUTATE-APPR');

$upsertDevice = function (string $uid, array $attributes) {
    $device = \\App\\Domain\\SecurityDevices\\Models\\Device::withTrashed()->where('device_uid', $uid)->first();
    if (! $device) {
        $device = new \\App\\Domain\\SecurityDevices\\Models\\Device(['device_uid' => $uid]);
    } elseif ($device->trashed()) {
        $device->restore();
    }
    $device->forceFill($attributes)->save();

    return $device;
};

$assignSite = function ($device) use ($site) {
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::query()->where('device_id', $device->id)->delete();
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);
};

$monitorDevice = $upsertDevice($monitorDeviceUid, [
    'name' => 'Playwright mutating ICMP node',
    'domain' => 'it_infrastructure',
    'category' => 'network',
    'subcategory' => 'managed_switch',
    'manufacturer' => 'Oblivion Demo',
    'model' => 'Mutating ICMP fixture',
    'status' => 'active',
    'health_status' => 'healthy',
    'last_seen_at' => now(),
    'provider' => 'oblivion_native',
    'created_by_user_id' => $operator->id,
]);
$assignSite($monitorDevice);

$doorConfig = [
    'management' => [
        'capabilities' => ['access.door.unlock_timed'],
        'unifi_access' => ['unlock_duration_seconds' => 15],
    ],
];
$doorA = $upsertDevice($doorAUid, [
    'name' => 'Playwright mutating door A',
    'domain' => 'security',
    'category' => 'access_control',
    'subcategory' => 'door_controller',
    'manufacturer' => 'Ubiquiti',
    'model' => 'Mutating Access Door',
    'status' => 'active',
    'health_status' => 'healthy',
    'last_seen_at' => now(),
    'provider' => 'unifi',
    'external_ref' => [
        'provider' => 'unifi',
        'provider_resource_kind' => 'door',
        'provider_door_id' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaa1',
    ],
    'config' => $doorConfig,
    'created_by_user_id' => $operator->id,
]);
$doorB = $upsertDevice($doorBUid, [
    'name' => 'Playwright mutating door B',
    'domain' => 'security',
    'category' => 'access_control',
    'subcategory' => 'door_controller',
    'manufacturer' => 'Ubiquiti',
    'model' => 'Mutating Access Door',
    'status' => 'active',
    'health_status' => 'healthy',
    'last_seen_at' => now(),
    'provider' => 'unifi',
    'external_ref' => [
        'provider' => 'unifi',
        'provider_resource_kind' => 'door',
        'provider_door_id' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbb2',
    ],
    'config' => $doorConfig,
    'created_by_user_id' => $operator->id,
]);
$assignSite($doorA);
$assignSite($doorB);

$profile = \\App\\Domain\\Monitoring\\Models\\MonitoringProfile::query()->firstOrCreate(
    ['name' => $profileName],
    [
        'description' => 'Deterministic mutating monitor fixture',
        'interval_seconds' => 60,
        'failure_confirmations' => 3,
        'recovery_confirmations' => 2,
        'stale_after_seconds' => 300,
        'is_active' => true,
    ],
);
$profile->forceFill(['is_active' => true])->save();

\\App\\Domain\\Monitoring\\Discovery\\Models\\DiscoveryScope::query()->updateOrCreate(
    ['name' => 'Playwright mutating ICMP scope'],
    [
        'site_id' => $site->id,
        'collector_id' => null,
        'cidrs' => [$monitorCidr],
        'seed_hosts' => [],
        'protocols' => ['icmp'],
        'exclusions' => [],
        'port_bounds' => [],
        'max_targets_per_run' => 1024,
        'packets_per_second' => 20,
        'status' => 'active',
    ],
);

\\App\\Domain\\Monitoring\\Discovery\\Models\\DiscoveryScope::query()->updateOrCreate(
    ['name' => $scopeSeedName],
    [
        'site_id' => $site->id,
        'collector_id' => null,
        'cidrs' => [$commandCidr],
        'seed_hosts' => [],
        'protocols' => ['provider'],
        'exclusions' => [],
        'port_bounds' => ['provider' => [12445]],
        'max_targets_per_run' => 1024,
        'packets_per_second' => 20,
        'status' => 'active',
    ],
);

\\App\\Models\\Integration\\IntegrationSiteSecret::query()->updateOrCreate(
    [
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => 'access_api',
    ],
    [
        'base_url' => 'https://10.77.4.5:12445',
        'secret_encrypted' => 'PW-SD-MUTATE-SECRET-NOT-FOR-UI',
        'is_enabled' => true,
        'last_tested_at' => now(),
        'last_error' => null,
    ],
);

\\App\\Domain\\SecurityDevices\\Credentials\\Models\\CredentialReference::query()->updateOrCreate(
    ['reference_key' => 'vault:pw-sd-mutate/'.$site->id.'/unifi-access'],
    [
        'site_id' => $site->id,
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => ['command:access.door.unlock_timed'],
        'secret_manager_reference' => 'secret/data/pw-sd-mutate/'.$site->id.'/unifi-access',
        'secret_manager_reference_hash' => hash('sha256', 'pw-sd-mutate-unifi-'.$site->id),
        'status' => \\App\\Domain\\SecurityDevices\\Credentials\\Enums\\CredentialReferenceStatus::Active,
        'rotation_status' => \\App\\Domain\\SecurityDevices\\Credentials\\Enums\\CredentialRotationStatus::Current,
        'test_status' => \\App\\Domain\\SecurityDevices\\Credentials\\Enums\\CredentialTestStatus::Passed,
        'version' => 1,
        'created_by_user_id' => $admin->id,
        'last_tested_at' => now(),
    ],
);

$collectorPair = sodium_crypto_sign_seed_keypair(str_repeat("\\x41", SODIUM_CRYPTO_SIGN_SEEDBYTES));
$collectorPublic = sodium_crypto_sign_publickey($collectorPair);
$collector = \\App\\Domain\\Monitoring\\Models\\MonitoringCollector::query()->updateOrCreate(
    ['collector_uuid' => $collectorUuid],
    [
        'name' => $collectorName,
        'site_id' => $site->id,
        'public_key' => base64_encode($collectorPublic),
        'public_key_fingerprint' => hash('sha256', $collectorPublic),
        'client_certificate_fingerprint' => hash('sha256', 'pw-sd-mutate-collector-cert'),
        'status' => 'online',
        'last_seen_at' => now(),
        'last_heartbeat_at' => now(),
        'enrolled_at' => now()->subHour(),
        'revoked_at' => null,
    ],
);

echo json_encode([
    'siteId' => $site->id,
    'siteName' => $site->name,
    'operatorEmail' => $operatorEmail,
    'approverEmail' => $approverEmail,
    'monitorDeviceId' => $monitorDevice->id,
    'monitorDeviceName' => $monitorDevice->name,
    'monitorProfileName' => $profile->name,
    'monitorCidr' => $monitorCidr,
    'monitorTarget' => $monitorTarget,
    'doorAId' => $doorA->id,
    'doorAName' => $doorA->name,
    'doorBId' => $doorB->id,
    'doorBName' => $doorB->name,
    'collectorUuid' => $collector->collector_uuid,
    'collectorName' => $collector->name,
    'discoveryScopeSeedName' => $scopeSeedName,
], JSON_THROW_ON_ERROR);
`);
}
