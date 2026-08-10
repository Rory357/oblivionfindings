<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CanonicalProbeScopeResolver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

if (getenv('MONITORING_USE_PREBUILT_TEST_DATABASE') === '1') {
    $appEnvironment = getenv('APP_ENV');
    $databaseConnection = getenv('DB_CONNECTION');
    $databasePath = getenv('DB_DATABASE');

    if ($appEnvironment !== 'testing'
        || $databaseConnection !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || ! is_file($databasePath)
    ) {
        throw new RuntimeException(
            'MONITORING_USE_PREBUILT_TEST_DATABASE requires APP_ENV=testing, DB_CONNECTION=sqlite, and an existing file-backed database.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

final class TaskFourApprovedScopeProvider implements ApprovedProbeScopeProvider
{
    /** @var list<array{int, int}> */
    public array $calls = [];

    /** @param Closure(int, int): ProbeScope $provide */
    public function __construct(private readonly Closure $provide) {}

    public function forDeviceAtSite(int $siteId, int $deviceId): ProbeScope
    {
        $this->calls[] = [$siteId, $deviceId];

        return ($this->provide)($siteId, $deviceId);
    }
}

function taskFourCanonicalResolver(?Closure $provide = null): array
{
    $provider = new TaskFourApprovedScopeProvider($provide ?? fn (int $siteId, int $deviceId) => new ProbeScope(
        $siteId,
        $deviceId,
        ['10.44.0.0/16'],
        [443],
    ));

    return [new CanonicalProbeScopeResolver($provider, app(CanonicalDeviceSiteResolver::class)), $provider];
}

function taskFourAssign(Device $device, string $type, int $targetId, bool $released = false): DeviceAssignment
{
    return DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => $type,
        'assignable_id' => $targetId,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'released_at' => $released ? now() : null,
    ]);
}

it('resolves every canonical active assignment shape to one site before asking for network authority', function () {
    $site = Site::factory()->create();
    [$resolver, $provider] = taskFourCanonicalResolver();

    $direct = Device::factory()->create();
    taskFourAssign($direct, DeviceAssignment::TARGET_SITE, $site->id);
    $offline = Device::factory()->create(['status' => DeviceStatus::Offline]);
    taskFourAssign($offline, DeviceAssignment::TARGET_SITE, $site->id);

    $roomModel = new SiteRoom;
    $requiredContextKey = collect($roomModel->getFillable())
        ->first(fn (string $key): bool => str_ends_with($key, 'ant_id'));
    $room = SiteRoom::forceCreate([
        $requiredContextKey => $site->getAttribute($requiredContextKey),
        'site_id' => $site->id,
        'name' => 'Network cabinet',
    ]);
    $inRoom = Device::factory()->create();
    taskFourAssign($inRoom, DeviceAssignment::TARGET_ROOM, $room->id);

    $client = Client::withoutEvents(fn () => Client::forceCreate([
        'first_name' => 'Canonical',
        'last_name' => 'Client',
        'site_id' => $site->id,
        'status' => 'active',
    ]));
    $withClient = Device::factory()->create();
    taskFourAssign($withClient, DeviceAssignment::TARGET_CLIENT, $client->id);

    $staff = User::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'end_date' => null,
    ]);
    $withStaff = Device::factory()->create();
    taskFourAssign($withStaff, DeviceAssignment::TARGET_STAFF, $staff->id);

    $vehicle = Asset::factory()->vehicle()->create([
        'site_id' => $site->id,
        'home_site_id' => $site->id,
        'status' => 'active',
    ]);
    $withVehicle = Device::factory()->create();
    taskFourAssign($withVehicle, DeviceAssignment::TARGET_VEHICLE, $vehicle->id);

    foreach ([$direct, $offline, $inRoom, $withClient, $withStaff, $withVehicle] as $device) {
        expect($resolver->resolve($site->id, $device->id))
            ->siteId->toBe($site->id)
            ->deviceId->toBe($device->id);
    }

    expect($provider->calls)->toBe([
        [$site->id, $direct->id],
        [$site->id, $offline->id],
        [$site->id, $inRoom->id],
        [$site->id, $withClient->id],
        [$site->id, $withStaff->id],
        [$site->id, $withVehicle->id],
    ]);
});

it('fails before the provider when a device has zero or conflicting canonical sites', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    [$resolver, $provider] = taskFourCanonicalResolver();

    $unassigned = Device::factory()->create();
    $released = Device::factory()->create();
    taskFourAssign($released, DeviceAssignment::TARGET_SITE, $site->id, released: true);
    $future = Device::factory()->create();
    DeviceAssignment::create([
        'device_id' => $future->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now()->addDay(),
        'released_at' => null,
    ]);
    $conflicting = Device::factory()->create();
    taskFourAssign($conflicting, DeviceAssignment::TARGET_SITE, $site->id);
    $conflictingAsset = Asset::factory()->create([
        'site_id' => $otherSite->id,
        'home_site_id' => $otherSite->id,
        'status' => 'active',
    ]);
    DeviceAssetLink::create([
        'device_id' => $conflicting->id,
        'asset_id' => $conflictingAsset->id,
        'link_type' => 'primary',
        'linked_at' => now(),
    ]);
    $conflictingVehicle = Asset::factory()->vehicle()->create([
        'site_id' => $site->id,
        'home_site_id' => $otherSite->id,
        'status' => 'active',
    ]);
    $vehicleDevice = Device::factory()->create();
    taskFourAssign($vehicleDevice, DeviceAssignment::TARGET_VEHICLE, $conflictingVehicle->id);

    foreach ([$unassigned, $released, $future, $conflicting, $vehicleDevice] as $device) {
        expect(fn () => $resolver->resolve($site->id, $device->id))
            ->toThrow(EgressDenied::class, 'one canonical active site');
    }

    expect($provider->calls)->toBe([]);
});

it('fails before the provider for a mismatched requested site or inactive canonical target', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    [$resolver, $provider] = taskFourCanonicalResolver();

    $mismatched = Device::factory()->create();
    taskFourAssign($mismatched, DeviceAssignment::TARGET_SITE, $site->id);

    $inactiveSite = Site::factory()->create(['is_active' => false]);
    $atInactiveSite = Device::factory()->create();
    taskFourAssign($atInactiveSite, DeviceAssignment::TARGET_SITE, $inactiveSite->id);

    $deletedClient = Client::withoutEvents(fn () => Client::forceCreate([
        'first_name' => 'Deleted',
        'last_name' => 'Client',
        'site_id' => $site->id,
        'status' => 'active',
    ]));
    $deletedClient->delete();
    $withDeletedClient = Device::factory()->create();
    taskFourAssign($withDeletedClient, DeviceAssignment::TARGET_CLIENT, $deletedClient->id);

    $endedStaff = User::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $endedStaff->id,
        'primary_site_id' => $site->id,
        'is_active' => false,
    ]);
    $withEndedStaff = Device::factory()->create();
    taskFourAssign($withEndedStaff, DeviceAssignment::TARGET_STAFF, $endedStaff->id);

    $futureStaff = User::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $futureStaff->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'start_date' => today()->addDay(),
        'end_date' => null,
    ]);
    $withFutureStaff = Device::factory()->create();
    taskFourAssign($withFutureStaff, DeviceAssignment::TARGET_STAFF, $futureStaff->id);

    expect(fn () => $resolver->resolve($otherSite->id, $mismatched->id))
        ->toThrow(EgressDenied::class, 'canonical site mismatch')
        ->and(fn () => $resolver->resolve($inactiveSite->id, $atInactiveSite->id))
        ->toThrow(EgressDenied::class)
        ->and(fn () => $resolver->resolve($site->id, $withDeletedClient->id))
        ->toThrow(EgressDenied::class)
        ->and(fn () => $resolver->resolve($site->id, $withEndedStaff->id))
        ->toThrow(EgressDenied::class)
        ->and(fn () => $resolver->resolve($site->id, $withFutureStaff->id))
        ->toThrow(EgressDenied::class)
        ->and($provider->calls)->toBe([]);
});

it('accepts every canonical vehicle category and site evidence path but rejects conflicts', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $vehicleCategory = AssetCategory::query()->create([
        'name' => 'Vehicle',
        'slug' => 'vehicle',
        'default_risk_level' => 'low',
        'requires_inspection_default' => false,
        'requires_maintenance_default' => false,
    ]);
    $client = Client::withoutEvents(fn () => Client::forceCreate([
        'first_name' => 'Vehicle',
        'last_name' => 'Client',
        'site_id' => $site->id,
        'status' => 'active',
    ]));
    $otherClient = Client::withoutEvents(fn () => Client::forceCreate([
        'first_name' => 'Conflicting',
        'last_name' => 'Client',
        'site_id' => $otherSite->id,
        'status' => 'active',
    ]));
    [$resolver, $provider] = taskFourCanonicalResolver();

    $relationVehicle = Asset::factory()->create([
        'category' => 'IT Equipment',
        'asset_category_id' => $vehicleCategory->id,
        'site_id' => $site->id,
        'home_site_id' => null,
        'status' => 'active',
    ]);
    $relationDevice = Device::factory()->create();
    taskFourAssign($relationDevice, DeviceAssignment::TARGET_VEHICLE, $relationVehicle->id);

    $clientVehicle = Asset::factory()->vehicle()->create([
        'site_id' => null,
        'home_site_id' => null,
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $clientDevice = Device::factory()->create();
    taskFourAssign($clientDevice, DeviceAssignment::TARGET_VEHICLE, $clientVehicle->id);

    $conflictingVehicle = Asset::factory()->vehicle()->create([
        'site_id' => $site->id,
        'home_site_id' => $site->id,
        'client_id' => $otherClient->id,
        'status' => 'active',
    ]);
    $conflictingDevice = Device::factory()->create();
    taskFourAssign($conflictingDevice, DeviceAssignment::TARGET_VEHICLE, $conflictingVehicle->id);

    expect($resolver->resolve($site->id, $relationDevice->id)->siteId)->toBe($site->id)
        ->and($resolver->resolve($site->id, $clientDevice->id)->siteId)->toBe($site->id)
        ->and(fn () => $resolver->resolve($site->id, $conflictingDevice->id))
        ->toThrow(EgressDenied::class, 'one canonical active site')
        ->and($provider->calls)->toBe([
            [$site->id, $relationDevice->id],
            [$site->id, $clientDevice->id],
        ]);
});

it('rejects forged authority returned by the trusted scope provider', function () {
    $site = Site::factory()->create();
    $device = Device::factory()->create();
    taskFourAssign($device, DeviceAssignment::TARGET_SITE, $site->id);
    [$resolver] = taskFourCanonicalResolver(
        fn (int $siteId, int $deviceId) => new ProbeScope($siteId + 1, $deviceId, ['0.0.0.0/0'], [443]),
    );

    expect(fn () => $resolver->resolve($site->id, $device->id))
        ->toThrow(EgressDenied::class, 'approved scope mismatch');
});

it('rejects unavailable devices archived sites and missing assignment targets before provider access', function () {
    $site = Site::factory()->create();
    [$resolver, $provider] = taskFourCanonicalResolver();

    $retired = Device::factory()->decommissioned()->create();
    taskFourAssign($retired, DeviceAssignment::TARGET_SITE, $site->id);

    $deleted = Device::factory()->create();
    taskFourAssign($deleted, DeviceAssignment::TARGET_SITE, $site->id);
    $deleted->delete();

    $archivedSite = Site::factory()->create(['archived' => true, 'archived_at' => now()]);
    $atArchivedSite = Device::factory()->create();
    taskFourAssign($atArchivedSite, DeviceAssignment::TARGET_SITE, $archivedSite->id);

    $missingRoom = Device::factory()->create();
    taskFourAssign($missingRoom, DeviceAssignment::TARGET_ROOM, 999_999);

    expect(fn () => $resolver->resolve($site->id, $retired->id))
        ->toThrow(EgressDenied::class, 'canonical device is unavailable')
        ->and(fn () => $resolver->resolve($site->id, $deleted->id))
        ->toThrow(EgressDenied::class, 'canonical device is unavailable')
        ->and(fn () => $resolver->resolve($archivedSite->id, $atArchivedSite->id))
        ->toThrow(EgressDenied::class, 'canonical site is unavailable')
        ->and(fn () => $resolver->resolve($site->id, $missingRoom->id))
        ->toThrow(EgressDenied::class, 'one canonical active site')
        ->and($provider->calls)->toBe([]);
});

it('does not silently drop a missing target beside an otherwise valid assignment', function () {
    $site = Site::factory()->create();
    $device = Device::factory()->create();
    $validAsset = Asset::factory()->create([
        'site_id' => $site->id,
        'home_site_id' => $site->id,
        'status' => 'active',
    ]);
    DeviceAssetLink::create([
        'device_id' => $device->id,
        'asset_id' => $validAsset->id,
        'link_type' => 'primary',
        'linked_at' => now(),
    ]);
    taskFourAssign($device, DeviceAssignment::TARGET_ROOM, 999_999);
    [$resolver, $provider] = taskFourCanonicalResolver();

    expect(fn () => $resolver->resolve($site->id, $device->id))
        ->toThrow(EgressDenied::class, 'one canonical active site')
        ->and($provider->calls)->toBe([]);
});

it('wraps every provider failure without exposing infrastructure detail', function (Throwable $failure) {
    $site = Site::factory()->create();
    $device = Device::factory()->create();
    taskFourAssign($device, DeviceAssignment::TARGET_SITE, $site->id);
    [$resolver] = taskFourCanonicalResolver(function () use ($failure): never {
        throw $failure;
    });

    $denial = null;
    try {
        $resolver->resolve($site->id, $device->id);
    } catch (EgressDenied $exception) {
        $denial = $exception;
    }

    expect($denial)->toBeInstanceOf(EgressDenied::class)
        ->and($denial?->getMessage())->toBe('approved probe scope is unavailable')
        ->and($denial?->getPrevious())->toBeNull();
})->with([
    'infrastructure exception' => new RuntimeException('database credentials leaked'),
    'provider policy exception' => new EgressDenied('internal scope row 928 leaked'),
]);
