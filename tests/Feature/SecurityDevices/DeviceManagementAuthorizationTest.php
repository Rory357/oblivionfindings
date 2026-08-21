<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Tests\Support\AuthoritativeConsentFixture;

function managementBoundaryActor(Site $site, string $role = 'support_worker'): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $actor->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);

    return $actor;
}

function grantManagementBoundaryPermissions(User $actor, array $permissions, bool $allowed = true): void
{
    $ids = Permission::query()->whereIn('key', $permissions)->pluck('id');
    $actor->permissionOverrides()->syncWithoutDetaching(
        $ids->mapWithKeys(fn (int $id): array => [$id => ['allowed' => $allowed]])->all(),
    );
    $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
}

function managementBoundaryDevice(Site $site, array $attributes): Device
{
    $device = Device::factory()->create($attributes);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    return $device;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    config()->set('monitoring.signing.active_key_id', 'management-boundary-test');
    config()->set('monitoring.signing.keys', [
        'management-boundary-test' => base64_encode(str_repeat('M', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
});

it('enforces application Site workspace Device-class and ordered management-level boundaries', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $actor = managementBoundaryActor($site, 'coordinator');
    grantManagementBoundaryPermissions($actor, ['securityDevices.commands.admin']);

    $camera = managementBoundaryDevice($site, [
        'domain' => 'security',
        'category' => 'cctv',
        'subcategory' => 'dome_camera',
        'config' => ['management' => ['capabilities' => [
            'diagnostics.ping',
            'access.door.unlock_timed',
            'tracking.location_refresh',
        ]]],
    ]);
    $outside = managementBoundaryDevice($otherSite, [
        'domain' => 'security',
        'category' => 'cctv',
        'config' => ['management' => ['capabilities' => ['diagnostics.ping']]],
    ]);

    $registry = app(CommandCapabilityRegistry::class);
    $authorization = app(DeviceManagementAuthorizationService::class);

    $higherLevel = $authorization->evaluate($actor, $camera, $registry->definition('diagnostics.ping'), fresh: true);
    $wrongClass = $authorization->evaluate($actor, $camera, $registry->definition('access.door.unlock_timed'), fresh: true);
    $wrongWorkspace = $authorization->evaluate($actor, $camera, $registry->definition('tracking.location_refresh'), fresh: true);
    $outsideSite = $authorization->evaluate($actor, $outside, $registry->definition('diagnostics.ping'), fresh: true);

    expect($higherLevel->allowed)->toBeTrue()
        ->and($wrongClass->allowed)->toBeFalse()
        ->and($wrongClass->code)->toBe('device_class_boundary')
        ->and($wrongClass->concealed)->toBeTrue()
        ->and($wrongWorkspace->allowed)->toBeFalse()
        ->and($wrongWorkspace->code)->toBe('workspace_boundary')
        ->and($outsideSite->allowed)->toBeFalse()
        ->and($outsideSite->code)->toBe('target_not_found');

    grantManagementBoundaryPermissions($actor, ['securityDevices.commands.operate'], false);
    $explicitDeny = $authorization->evaluate($actor, $camera, $registry->definition('diagnostics.ping'), fresh: true);
    expect($explicitDeny->allowed)->toBeFalse()
        ->and($explicitDeny->code)->toBe('management_level_required');

    $actor->forceFill(['approved_at' => null])->save();
    $applicationDenied = $authorization->evaluate($actor->fresh(), $camera, $registry->definition('diagnostics.ping'), fresh: true);
    expect($applicationDenied->allowed)->toBeFalse()
        ->and($applicationDenied->code)->toBe('application_access_required');
});

it('conceals CCTV management from the UI and direct path until media sensitivity access is present', function () {
    $site = Site::factory()->create();
    $actor = managementBoundaryActor($site);
    grantManagementBoundaryPermissions($actor, [
        'securityDevices.commands.observe',
        'securityDevices.commands.control',
    ]);
    $camera = managementBoundaryDevice($site, [
        'name' => 'North Wing Privacy Camera',
        'domain' => 'security',
        'category' => 'cctv',
        'subcategory' => 'dome_camera',
        'provider' => 'contract-test',
        'last_seen_at' => now(),
        'config' => ['management' => ['capabilities' => ['camera.privacy.enable']]],
    ]);
    $payload = [
        'capability' => 'camera.privacy.enable',
        'parameters' => [],
        'reason' => 'Protect the approved private area during the scheduled care activity.',
        'idempotency_key' => 'camera-privacy-'.$camera->id,
        'impact_acknowledged' => true,
    ];

    $this->actingAs($actor)
        ->get("/security-devices/devices/{$camera->id}?section=management")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $management = $page->toArray()['props']['profile']['management'];
            expect($management['actions'])->toBe([])
                ->and($management['history'])->toBe([]);
        });
    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$camera->id}/commands", $payload)
        ->assertNotFound();
    expect(DeviceCommandRequest::query()->count())->toBe(0);

    grantManagementBoundaryPermissions($actor, ['securityDevices.cctv.media.view']);
    $cameraDecision = app(DeviceManagementAuthorizationService::class)->evaluate(
        $actor->fresh(),
        $camera,
        app(CommandCapabilityRegistry::class)->definition('camera.privacy.enable'),
        fresh: true,
    );
    expect($cameraDecision->code)->toBe('allowed')
        ->and($cameraDecision->allowed)->toBeTrue();
    $this->actingAs($actor->fresh())
        ->get("/security-devices/devices/{$camera->id}?section=management")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $actions = $page->toArray()['props']['profile']['management']['actions'];
            expect($actions)->toHaveCount(1)
                ->and($actions[0]['key'])->toBe('camera.privacy.enable')
                ->and($actions[0]['sensitivity'])->toBe('cctv_media');
        });
    $this->actingAs($actor->fresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$camera->id}/commands", $payload)
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
    expect(DeviceCommandRequest::query()->sole()->status)->toBe(CommandStatus::AwaitingApproval);
});

it('requires active purpose consent audience and source access for personal tracking management', function () {
    $site = Site::factory()->create();
    $actor = managementBoundaryActor($site, 'coordinator');
    grantManagementBoundaryPermissions($actor, [
        'securityDevices.commands.operate',
        'assets.telemetry.view',
        'clients.viewAny',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $consentType = ConsentType::factory()->create([
        'name' => 'Personal location tracking',
        'purpose' => 'Client personal safety tracking',
        'active' => true,
    ]);
    $consent = AuthoritativeConsentFixture::manualSelf($client, $consentType, $actor, [
        'status' => 'given',
        'given_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);
    $tracker = Device::factory()->tracking()->create([
        'provider' => 'contract-test',
        'config' => ['management' => ['capabilities' => ['tracking.location_refresh']]],
    ]);
    $assignment = DeviceAssignment::query()->create([
        'device_id' => $tracker->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
        'consent_id' => $consent->id,
    ]);

    $capability = app(CommandCapabilityRegistry::class)->definition('tracking.location_refresh');
    $authorization = app(DeviceManagementAuthorizationService::class);
    $activeDecision = $authorization->evaluate($actor, $tracker, $capability, fresh: true);
    expect($activeDecision->code)->toBe('allowed')
        ->and($activeDecision->allowed)->toBeTrue();

    $assignment->forceFill([
        'collection_stopped_at' => now(),
        'collection_stop_reason' => 'consent_withdrawn',
    ])->save();
    $blocked = $authorization->evaluate($actor, $tracker, $capability, fresh: true);
    expect($blocked->allowed)->toBeFalse()
        ->and($blocked->code)->toBe('personal_tracking_privacy_blocked')
        ->and($blocked->concealed)->toBeTrue();

    $this->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$tracker->id}/commands", [
            'capability' => 'tracking.location_refresh',
            'parameters' => [],
            'reason' => 'Refresh the authorised safety location after the welfare escalation.',
            'idempotency_key' => 'tracking-refresh-'.$tracker->id,
        ])
        ->assertNotFound();
    expect(DeviceCommandRequest::query()->count())->toBe(0);
});

it('requires canonical Client source access before healthcare Device control', function () {
    $site = Site::factory()->create();
    $actor = managementBoundaryActor($site, 'it_manager');
    $client = Client::factory()->create(['site_id' => $site->id]);
    $device = Device::factory()->iotHealthcare()->create([
        'provider' => 'contract-test',
        'config' => ['management' => ['capabilities' => ['healthcare.calibration_override']]],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $client->id,
        'assigned_at' => now(),
    ]);

    $capability = app(CommandCapabilityRegistry::class)->definition('healthcare.calibration_override');
    $authorization = app(DeviceManagementAuthorizationService::class);
    $withoutClientSource = $authorization->evaluate($actor, $device, $capability, fresh: true);
    expect($withoutClientSource->allowed)->toBeFalse()
        ->and($withoutClientSource->code)->toBe('target_not_found')
        ->and($withoutClientSource->concealed)->toBeTrue();

    grantManagementBoundaryPermissions($actor, ['clients.viewAny']);
    $withBothSources = $authorization->evaluate($actor->fresh(), $device, $capability, fresh: true);
    expect($withBothSources->allowed)->toBeTrue()
        ->and($withBothSources->workspace)->toBe('healthcare')
        ->and($withBothSources->sensitivity)->toBe('healthcare_technical');
});

it('revalidates sensitive source permission before approval and dispatch', function () {
    $site = Site::factory()->create();
    $requester = managementBoundaryActor($site, 'it_manager');
    $reviewer = managementBoundaryActor($site, 'it_manager');
    $camera = managementBoundaryDevice($site, [
        'name' => 'Governed Evidence Camera',
        'domain' => 'security',
        'category' => 'cctv',
        'subcategory' => 'dome_camera',
        'provider' => 'contract-test',
        'last_seen_at' => now(),
        'config' => ['management' => ['capabilities' => ['camera.privacy.enable']]],
    ]);

    $this->actingAs($requester)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/security-devices/devices/{$camera->id}/commands", [
            'capability' => 'camera.privacy.enable',
            'parameters' => [],
            'reason' => 'Protect the approved private area during the scheduled care activity.',
            'idempotency_key' => 'camera-revalidate-'.$camera->id,
            'impact_acknowledged' => true,
        ])
        ->assertSessionDoesntHaveErrors();
    $command = DeviceCommandRequest::query()->sole();

    grantManagementBoundaryPermissions($reviewer, ['securityDevices.cctv.media.view'], false);
    $this->actingAs($reviewer->fresh())
        ->post("/security-devices/commands/{$command->id}/decision", [
            'decision' => 'approved',
            'comment' => 'Attempt a decision after the sensitive source access was removed.',
        ])
        ->assertNotFound();
    expect($command->fresh()->status)->toBe(CommandStatus::AwaitingApproval);

    grantManagementBoundaryPermissions($reviewer, ['securityDevices.cctv.media.view']);
    $this->actingAs($reviewer->fresh())
        ->post("/security-devices/commands/{$command->id}/decision", [
            'decision' => 'approved',
            'comment' => 'The scope and privacy impact match the approved operating procedure.',
        ])
        ->assertSessionDoesntHaveErrors();
    expect($command->fresh()->status)->toBe(CommandStatus::Ready);

    grantManagementBoundaryPermissions($requester, ['securityDevices.cctv.media.view'], false);
    $this->actingAs($requester->fresh())
        ->post("/security-devices/commands/{$command->id}/dispatch")
        ->assertSessionHasErrors('command');

    $command->refresh();
    expect($command->status)->toBe(CommandStatus::Blocked)
        ->and($command->blocked_reason_code)->toBe('requester_authorisation_revoked')
        ->and($command->attempts()->count())->toBe(0);
});
