<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\EvvRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('lists only EVV records whose Shift and Client resolve to an accessible Site', function () {
    $accessibleSite = evvTestSite('Kauri House');
    $outsideSite = evvTestSite('Rimu House');
    $viewer = evvSiteActor($accessibleSite, ['evv.viewAny']);
    $visibleRecord = evvSiteRecord($accessibleSite);
    $outsideRecord = evvSiteRecord($outsideSite);

    $this->actingAs($viewer)
        ->get(route('operations.evv.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/evv/Index')
            ->has('records.data', 1)
            ->where('records.data.0.id', $visibleRecord->id));

    $this->actingAs($viewer)
        ->get(route('operations.evv.show', $outsideRecord))
        ->assertNotFound();
});

it('denies EVV check-in for a Shift outside the worker Site assignment', function () {
    $accessibleSite = evvTestSite('Totara House');
    $outsideSite = evvTestSite('Nikau House');
    $worker = evvSiteActor($accessibleSite, ['evv.record']);
    $outsideClient = Client::factory()->create([
        'site_id' => $outsideSite->id,
        'status' => 'active',
    ]);
    $outsideShift = Shift::factory()->create([
        'site_id' => $outsideSite->id,
        'client_id' => $outsideClient->id,
        'user_id' => $worker->id,
        'created_by' => $worker->id,
    ]);

    $this->actingAs($worker)
        ->post(route('operations.evv.check_in'), [
            'shift_id' => $outsideShift->id,
            'client_id' => $outsideClient->id,
            'latitude' => -36.8485,
            'longitude' => 174.7633,
        ])
        ->assertForbidden();

    expect(EvvRecord::query()->where('shift_id', $outsideShift->id)->exists())->toBeFalse();
});

function evvTestSite(string $name): Site
{
    return Site::factory()->create([
        'name' => $name,
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
}

function evvSiteActor(Site $site, array $permissionKeys): User
{
    $actor = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $role = Role::create([
        'name' => 'evv-site-test-'.uniqid(),
        'label' => 'EVV Site test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $permissions = collect($permissionKeys)->map(fn (string $key) => Permission::firstOrCreate(
        ['key' => $key],
        ['description' => $key, 'group' => 'EVV', 'module' => 'operations'],
    ));
    $role->permissions()->sync($permissions->pluck('id'));
    $actor->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);

    return $actor;
}

function evvSiteRecord(Site $site): EvvRecord
{
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'status' => 'active',
    ]);
    $worker = evvSiteActor($site, []);
    $shift = Shift::factory()->create([
        'site_id' => $site->id,
        'client_id' => $client->id,
        'user_id' => $worker->id,
        'created_by' => $worker->id,
    ]);

    return EvvRecord::query()->create([
        'shift_id' => $shift->id,
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'check_in_time' => now(),
        'verification_status' => 'pending',
    ]);
}
