<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Compensation creation Site']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
    ]);
});

test('creating a compensation review persists and redirects to the reviews list', function () {
    // The request must persist and target the canonical reviews route.
    $response = $this->actingAs($this->hr)->post('/hr/compensation/reviews', [
        'title' => 'FY2026 Annual Review',
        'review_cycle' => 'annual',
        'effective_date' => '2026-07-01',
    ]);

    $response->assertRedirect(route('hr.compensation.reviews'));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_compensation_reviews', [
        'title' => 'FY2026 Annual Review',
        'review_cycle' => 'annual',
        'created_by' => $this->hr->id,
    ]);
});

test('creating an application salary band persists successfully', function () {
    $response = $this->actingAs($this->hr)->post('/hr/compensation/bands', [
        'position_role' => 'support_worker',
        'band_name' => 'Band A',
        'min_salary' => 50000,
        'mid_salary' => 60000,
        'max_salary' => 70000,
        'min_hourly' => 25,
        'max_hourly' => 35,
        'effective_from' => '2026-07-01',
    ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_salary_bands', [
        'band_name' => 'Band A',
        'position_role' => 'support_worker',
    ]);
});

test('a user without hr.compensation.manage cannot create a review', function () {
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->actingAs($worker)->post('/hr/compensation/reviews', [
        'title' => 'Sneaky Review',
        'review_cycle' => 'annual',
        'effective_date' => '2026-07-01',
    ])->assertForbidden();

    $this->assertDatabaseMissing('hr_compensation_reviews', ['title' => 'Sneaky Review']);
});
