<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->manager = User::factory()->create([
        'role' => 'hr',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->manager->roles()->firstOrFail()->permissions()->syncWithoutDetaching([
        Permission::query()->where('key', 'rostering.viewAny')->firstOrFail()->id,
    ]);
});

test('a manager can assign a canonical team when creating an employee', function () {
    HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'team' => 'Clinical Support']);

    $this->actingAs($this->manager)->post('/hr/people', [
        'name' => 'Team Create Test',
        'email' => 'team-create@example.test',
        'role' => 'support_worker',
        'position_title' => 'Support Worker',
        'team' => '  clinical   support  ',
        'start_onboarding' => false,
    ])->assertRedirect();

    expect(User::query()->where('email', 'team-create@example.test')->firstOrFail()
        ->hrEmployeeProfile->team)->toBe('Clinical Support');
});

test('a manager can change and clear a profile team with whitespace normalised', function () {
    $profile = HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'team' => 'Old Team']);

    $this->actingAs($this->manager)
        ->put("/hr/people/{$profile->id}", ['team' => '  Community   Living  '])
        ->assertRedirect();

    expect($profile->fresh()->team)->toBe('Community Living');

    $this->actingAs($this->manager)
        ->put("/hr/people/{$profile->id}", ['team' => '   '])
        ->assertRedirect();

    expect($profile->fresh()->team)->toBeNull();
});

test('team changes enforce maximum length and tenant isolation', function () {
    $foreign = HrEmployeeProfile::factory()->create([
        'tenant_id' => 2,
        'user_id' => User::factory()->create(['organization_id' => 2])->id,
        'team' => 'Foreign Team',
    ]);

    $this->actingAs($this->manager)
        ->put("/hr/people/{$foreign->id}", ['team' => 'Changed Team'])
        ->assertNotFound();

    expect($foreign->fresh()->team)->toBe('Foreign Team');

    $local = HrEmployeeProfile::factory()->create(['tenant_id' => 1]);
    $this->actingAs($this->manager)
        ->put("/hr/people/{$local->id}", ['team' => str_repeat('x', 256)])
        ->assertSessionHasErrors('team');
});

test('calendar team options are canonical active and tenant scoped', function () {
    HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'team' => 'Clinical   Support', 'is_active' => true]);
    HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'team' => 'clinical support', 'is_active' => true]);
    HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'team' => 'Inactive Team', 'is_active' => false]);
    HrEmployeeProfile::factory()->create([
        'tenant_id' => 2,
        'user_id' => User::factory()->create(['organization_id' => 2])->id,
        'team' => 'Foreign Team',
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->get('/hr/calendar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/calendar/index')
            ->where('teams', ['Clinical Support']));
});

test('calendar explains how to configure teams when none are available', function () {
    $this->actingAs($this->manager)
        ->get('/hr/calendar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('teams', []));

    $source = file_get_contents(resource_path('js/components/hr/calendar/event-wizard-dialog.tsx'));

    expect($source)
        ->toContain('disabled: teams.length === 0')
        ->toContain('No teams are configured')
        ->toContain('/hr/people');
});
