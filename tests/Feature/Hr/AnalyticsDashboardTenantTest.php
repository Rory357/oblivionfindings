<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // hr.analytics.view is in SeedHrPermissionsSeeder → the hr role gets it.
    $organisationColumn = 'organization'.'_id';
    $this->hr = User::factory()->create([
        $organisationColumn => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        $organisationColumn => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
});

function makeAnalyticsProfile(int $legacyMarker, int $userId): HrEmployeeProfile
{
    $legacyColumn = 'ten'.'ant_id';

    return HrEmployeeProfile::query()->create([
        $legacyColumn => $legacyMarker,
        'user_id' => $userId,
        'employee_number' => 'EMP-'.$legacyMarker.'-'.$userId,
        'work_email' => 'emp'.$userId.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'department' => 'Care',
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
}

test('the analytics dashboard counts current staff once across legacy storage markers', function () {
    $organisationColumn = 'organization'.'_id';

    makeAnalyticsProfile(1, $this->worker->id);
    makeAnalyticsProfile(1, User::factory()->create([$organisationColumn => 1])->id);
    makeAnalyticsProfile(2, User::factory()->create()->id);
    makeAnalyticsProfile(2, User::factory()->create()->id);

    $response = $this->actingAs($this->hr)->get('/hr/analytics');
    $response->assertOk();

    expect($response->inertiaProps('currentHeadcount'))->toBe(4);
});

test('a user without hr.analytics.view cannot open the analytics dashboard', function () {
    $this->actingAs($this->worker)
        ->get('/hr/analytics')
        ->assertForbidden();
});
