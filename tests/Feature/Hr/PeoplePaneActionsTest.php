<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->manager = User::factory()->create([
        'name' => 'HR Manager',
        'role' => 'admin',
        'approved_at' => now(),
    ]);
    $adminRole = Role::query()->where('name', 'admin')->first();
    if ($adminRole) {
        $this->manager->roles()->syncWithoutDetaching([$adminRole->id]);
    }
});

function makeStaffProfile(string $name, array $overrides = []): HrEmployeeProfile
{
    $staff = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug() . '@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    return HrEmployeeProfile::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'employee_number' => 'EMP-' . $staff->id,
        'work_email' => $staff->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ], $overrides));
}

test('setActive deactivates then reactivates an employee profile', function () {
    $profile = makeStaffProfile('Toggle Target');

    $this->actingAs($this->manager)
        ->patch("/hr/people/{$profile->id}/active", ['is_active' => false])
        ->assertRedirect();

    expect($profile->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->manager)
        ->patch("/hr/people/{$profile->id}/active", ['is_active' => true])
        ->assertRedirect();

    expect($profile->fresh()->is_active)->toBeTrue();
});

test('setActive is forbidden for users without employees.manage', function () {
    $profile = makeStaffProfile('Protected Target');

    $viewer = User::factory()->create([
        'name' => 'Plain Support Worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->patch("/hr/people/{$profile->id}/active", ['is_active' => false])
        ->assertForbidden();

    expect($profile->fresh()->is_active)->toBeTrue();
});

test('people index sorts by name in the requested direction', function () {
    makeStaffProfile('Aaron Aardvark');
    makeStaffProfile('Zelda Zephyr');

    $names = collect(
        $this->actingAs($this->manager)
            ->get('/hr/people?sort=name&dir=desc')
            ->inertiaProps('profiles.data')
    )->pluck('user.name');

    $aaron = $names->search('Aaron Aardvark');
    $zelda = $names->search('Zelda Zephyr');

    expect($zelda)->toBeLessThan($aaron);
});

test('people index sorts by start date ascending', function () {
    makeStaffProfile('Recent Hire', ['start_date' => now()->subDays(2)->toDateString()]);
    makeStaffProfile('Veteran Hire', ['start_date' => now()->subYears(3)->toDateString()]);

    $names = collect(
        $this->actingAs($this->manager)
            ->get('/hr/people?sort=start&dir=asc')
            ->inertiaProps('profiles.data')
    )->pluck('user.name');

    $veteran = $names->search('Veteran Hire');
    $recent = $names->search('Recent Hire');

    expect($veteran)->toBeLessThan($recent);
});

test('bulk deactivate sets selected profiles inactive', function () {
    $a = makeStaffProfile('Bulk One');
    $b = makeStaffProfile('Bulk Two');

    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'deactivate',
            'ids' => [$a->id, $b->id],
        ])
        ->assertRedirect();

    expect($a->fresh()->is_active)->toBeFalse();
    expect($b->fresh()->is_active)->toBeFalse();
});

test('bulk assign_site moves selected profiles to the chosen site', function () {
    $site = Site::factory()->create(['name' => 'Rata House']);
    $a = makeStaffProfile('Movable Worker');

    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'assign_site',
            'ids' => [$a->id],
            'site_id' => $site->id,
        ])
        ->assertRedirect();

    expect($a->fresh()->primary_site_id)->toBe($site->id);
});

test('bulk actions write an audit-log row per profile', function () {
    // Regression: bulkAction used a mass query update, which skips Eloquent
    // events — AuditableChanges never fired, so bulk changes were invisible
    // in the audit log.
    $a = makeStaffProfile('Audited One');
    $b = makeStaffProfile('Audited Two');

    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'deactivate',
            'ids' => [$a->id, $b->id],
        ])
        ->assertRedirect();

    foreach ([$a, $b] as $profile) {
        expect(\App\Models\AuditLog::query()
            ->where('action', 'hremployeeprofile.update')
            ->where('auditable_id', $profile->id)
            ->exists())->toBeTrue();
    }
});

test('bulk action is forbidden without employees.manage', function () {
    $a = makeStaffProfile('Guarded Worker');
    $viewer = User::factory()->create([
        'name' => 'Viewer Only',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->post('/hr/people/bulk', [
            'action' => 'deactivate',
            'ids' => [$a->id],
        ])
        ->assertForbidden();

    expect($a->fresh()->is_active)->toBeTrue();
});
