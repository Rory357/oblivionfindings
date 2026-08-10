<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MileageClaim;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('limits managed mileage claims and approval actions to staff at accessible Sites', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = mileageSiteStaff($accessibleSite, ['mileage.viewAny', 'mileage.approve']);
    $visibleWorker = mileageSiteStaff($accessibleSite);
    $outsideWorker = mileageSiteStaff($outsideSite);
    $visibleClaim = mileageSiteClaim($visibleWorker);
    $outsideClaim = mileageSiteClaim($outsideWorker);

    $this->actingAs($manager)
        ->get(route('operations.mileage.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/mileage/Index')
            ->has('claims.data', 1)
            ->where('claims.data.0.id', $visibleClaim->id));

    $this->actingAs($manager)
        ->post(route('operations.mileage.approve', $outsideClaim))
        ->assertNotFound();

    expect($outsideClaim->fresh()->status)->toBe('submitted');
});

it('allows workers to submit only their own draft mileage claim', function () {
    $site = Site::factory()->create();
    $worker = mileageSiteStaff($site, ['mileage.create']);
    $colleague = mileageSiteStaff($site);
    $ownClaim = mileageSiteClaim($worker, 'draft');
    $colleagueClaim = mileageSiteClaim($colleague, 'draft');

    $this->actingAs($worker)
        ->post(route('operations.mileage.submit', $ownClaim))
        ->assertRedirect();
    $this->actingAs($worker)
        ->post(route('operations.mileage.submit', $colleagueClaim))
        ->assertNotFound();

    expect($ownClaim->fresh()->status)->toBe('submitted')
        ->and($colleagueClaim->fresh()->status)->toBe('draft');
});

function mileageSiteStaff(Site $site, array $permissionKeys = []): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    if ($permissionKeys !== []) {
        $role = Role::create([
            'name' => 'mileage-site-test-'.uniqid(),
            'label' => 'Mileage Site test',
            'level' => 10,
            'type' => 'custom',
        ]);
        $permissions = collect($permissionKeys)->map(fn (string $key) => Permission::firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'Mileage', 'module' => 'operations'],
        ));
        $role->permissions()->sync($permissions->pluck('id'));
        $user->roles()->attach($role);
    }

    return $user;
}

function mileageSiteClaim(User $worker, string $status = 'submitted'): MileageClaim
{
    return MileageClaim::query()->create([
        'user_id' => $worker->id,
        'claim_date' => today(),
        'origin' => 'Kauri House',
        'destination' => 'Clinic',
        'distance_km' => 12.5,
        'rate_per_km' => 0.95,
        'amount' => 11.88,
        'purpose' => 'Client appointment',
        'status' => $status,
        'submitted_at' => $status === 'submitted' ? now() : null,
    ]);
}
