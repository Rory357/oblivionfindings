<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('creating a compensation review resolves the tenant and redirects to the reviews list', function () {
    // hr_compensation_reviews.tenant_id is NOT NULL — a null write (from the
    // old $user->tenant_id, which is always null) would fail the insert, and the
    // old redirect targeted a non-existent route name (reviews.index).
    $response = $this->actingAs($this->hr)->post('/hr/compensation/reviews', [
        'title' => 'FY2026 Annual Review',
        'review_cycle' => 'annual',
        'effective_date' => '2026-07-01',
    ]);

    $response->assertRedirect(route('hr.compensation.reviews'));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_compensation_reviews', [
        'tenant_id' => 1,
        'title' => 'FY2026 Annual Review',
        'review_cycle' => 'annual',
        'created_by' => $this->hr->id,
    ]);
});

test('creating a salary band resolves the tenant', function () {
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
        'tenant_id' => 1,
        'band_name' => 'Band A',
        'position_role' => 'support_worker',
    ]);
});

test('a user without hr.compensation.manage cannot create a review', function () {
    $worker = User::factory()->create([
        'organization_id' => 1,
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
