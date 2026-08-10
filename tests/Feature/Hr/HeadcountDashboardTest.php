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

    $legacyColumn = 'ten'.'ant_id';
    HrEmployeeProfile::query()->create([
        $legacyColumn => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'department' => 'Care',
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
});

test('the headcount dashboard ships the current prop shape the page reads', function () {
    // Regression: controller shipped `currentHeadcount` but the page reads
    // `current` → Object.entries(undefined.by_department) crashed the page. Also
    // the page read total_fte (service ships fte_total) + treated by_department
    // (an array) as a Record.
    $legacyColumn = 'ten'.'ant_id';
    $secondWorker = User::factory()->create();
    HrEmployeeProfile::query()->create([
        $legacyColumn => 2,
        'user_id' => $secondWorker->id,
        'employee_number' => 'EMP-'.$secondWorker->id,
        'work_email' => $secondWorker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'department' => 'Care',
        'employment_type' => 'full_time',
        'hours_per_week' => 40,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/headcount');
    $response->assertOk();

    expect($response->inertiaProps('current.total'))->toBe(2);
    expect($response->inertiaProps('current.fte_total'))->not->toBeNull();
    expect($response->inertiaProps('current.by_department'))->toBeArray();
    // The old mismatched key is gone.
    expect($response->inertiaProps('currentHeadcount'))->toBeNull();
});

test('a user without hr.analytics.view cannot open the headcount dashboard', function () {
    $this->actingAs($this->worker)
        ->get('/hr/headcount')
        ->assertForbidden();
});
