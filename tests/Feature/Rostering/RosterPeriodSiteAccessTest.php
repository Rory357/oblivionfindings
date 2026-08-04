<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterPeriod;
use App\Models\Site;
use App\Models\User;

it('denies a publish review for a roster period outside the manager Site assignment', function () {
    config()->set('features.rostering.publish', true);

    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = User::factory()->create(['approved_at' => now()]);
    $permission = Permission::firstOrCreate(
        ['key' => 'rostering.publish'],
        ['description' => 'Publish rosters', 'group' => 'Rostering', 'module' => 'operations'],
    );
    $role = Role::create([
        'name' => 'roster-period-site-test-'.uniqid(),
        'label' => 'Roster period Site test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $role->permissions()->sync([$permission->id]);
    $manager->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $manager->id,
        'primary_site_id' => $accessibleSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $outsidePeriod = RosterPeriod::factory()->create(['site_id' => $outsideSite->id]);

    $this->actingAs($manager)
        ->get(route('operations.rostering.periods.review.show', $outsidePeriod))
        ->assertForbidden();
});
