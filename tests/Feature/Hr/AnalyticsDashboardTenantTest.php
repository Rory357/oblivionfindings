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
    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
});

function makeAnalyticsProfile(int $tenantId, int $userId): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'employee_number' => 'EMP-'.$tenantId.'-'.$userId,
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

test('the analytics dashboard scopes headcount to the resolved tenant', function () {
    // tenant 1 (the acting hr user's tenant) has 2 profiles; tenant 2 has 2.
    makeAnalyticsProfile(1, $this->worker->id);
    makeAnalyticsProfile(1, User::factory()->create(['organization_id' => 1])->id);
    makeAnalyticsProfile(2, User::factory()->create()->id);
    makeAnalyticsProfile(2, User::factory()->create()->id);

    $response = $this->actingAs($this->hr)->get('/hr/analytics');
    $response->assertOk();

    // Was $tenantId = null (cross-tenant/whereNull) → wrong count; now tenant 1.
    expect($response->inertiaProps('currentHeadcount'))->toBe(2);
});

test('a user without hr.analytics.view cannot open the analytics dashboard', function () {
    $this->actingAs($this->worker)
        ->get('/hr/analytics')
        ->assertForbidden();
});
