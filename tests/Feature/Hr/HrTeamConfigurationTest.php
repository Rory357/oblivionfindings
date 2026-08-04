<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->manager->roles()->firstOrFail()->permissions()->syncWithoutDetaching([
        Permission::query()->where('key', 'rostering.viewAny')->firstOrFail()->id,
    ]);
    $this->allowedSite = Site::factory()->create(['name' => 'Team Configuration Allowed Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Team Configuration Hidden Site']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->manager->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
        'start_date' => now()->subYear()->toDateString(),
    ]);
});

test('a manager can assign a canonical team when creating an employee', function () {
    HrEmployeeProfile::factory()->create([
        'team' => 'Clinical Support',
        'primary_site_id' => $this->allowedSite->id,
    ]);

    $this->actingAs($this->manager)->post('/hr/people', [
        'name' => 'Team Create Test',
        'email' => 'team-create@example.test',
        'role' => 'support_worker',
        'position_title' => 'Support Worker',
        'team' => '  clinical   support  ',
        'primary_site_id' => $this->allowedSite->id,
        'start_onboarding' => false,
    ])->assertRedirect();

    expect(User::query()->where('email', 'team-create@example.test')->firstOrFail()
        ->hrEmployeeProfile->team)->toBe('Clinical Support');
});

test('a manager can change and clear a profile team with whitespace normalised', function () {
    $profile = HrEmployeeProfile::factory()->create([
        'team' => 'Old Team',
        'primary_site_id' => $this->allowedSite->id,
    ]);

    $this->actingAs($this->manager)
        ->put("/hr/people/{$profile->id}", ['team' => '  Community   Living  '])
        ->assertRedirect();

    expect($profile->fresh()->team)->toBe('Community Living');

    $this->actingAs($this->manager)
        ->put("/hr/people/{$profile->id}", ['team' => '   '])
        ->assertRedirect();

    expect($profile->fresh()->team)->toBeNull();
});

test('employee edit dates are rendered as native date input values', function () {
    $profile = HrEmployeeProfile::factory()->create([
        'start_date' => '2025-11-03',
        'end_date' => '2026-11-03',
        'probation_end_date' => '2026-02-03',
        'visa_expires_at' => '2027-11-03',
        'primary_site_id' => $this->allowedSite->id,
    ]);

    $this->actingAs($this->manager)
        ->get("/hr/people/{$profile->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/edit')
            ->where('profile.start_date', '2025-11-03')
            ->where('profile.end_date', '2026-11-03')
            ->where('profile.probation_end_date', '2026-02-03')
            ->where('profile.visa_expires_at', '2027-11-03'));
});

test('team changes enforce maximum length and canonical Site access', function () {
    $foreign = HrEmployeeProfile::factory()->create([
        'user_id' => User::factory()->create()->id,
        'team' => 'Foreign Team',
        'primary_site_id' => $this->hiddenSite->id,
    ]);

    $this->actingAs($this->manager)
        ->put("/hr/people/{$foreign->id}", ['team' => 'Changed Team'])
        ->assertNotFound();

    expect($foreign->fresh()->team)->toBe('Foreign Team');

    $local = HrEmployeeProfile::factory()->create([
        'primary_site_id' => $this->allowedSite->id,
    ]);
    $this->actingAs($this->manager)
        ->put("/hr/people/{$local->id}", ['team' => str_repeat('x', 256)])
        ->assertSessionHasErrors('team');
});

test('calendar team options are canonical active and Site scoped', function () {
    HrEmployeeProfile::factory()->create([
        'team' => 'Clinical   Support',
        'is_active' => true,
        'primary_site_id' => $this->allowedSite->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'team' => 'clinical support',
        'is_active' => true,
        'primary_site_id' => $this->allowedSite->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'team' => 'Inactive Team',
        'is_active' => false,
        'primary_site_id' => $this->allowedSite->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => User::factory()->create()->id,
        'team' => 'Foreign Team',
        'is_active' => true,
        'primary_site_id' => $this->hiddenSite->id,
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
