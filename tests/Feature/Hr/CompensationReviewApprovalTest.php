<?php

use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrEmployeeProfile;
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

    $this->employee = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->employee->id,
        'employee_number' => 'EMP-COMP-'.$this->employee->id,
        'work_email' => 'comp'.$this->employee->id.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'annual_salary' => 60000,
        'hourly_rate' => 28,
        'is_active' => true,
    ]);
});

function makePlanningReview(int $hrId, int $profileId): HrCompensationReview
{
    $review = HrCompensationReview::query()->create([
        'tenant_id' => 1,
        'title' => 'FY2026 Annual Review',
        'review_cycle' => 'annual',
        'effective_date' => '2026-07-01',
        'status' => 'planning',
        'created_by' => $hrId,
    ]);

    $review->items()->create([
        'employee_profile_id' => $profileId,
        'current_salary' => 60000,
        'proposed_salary' => 66000,
        'change_percentage' => 10,
        'status' => 'pending',
    ]);

    return $review;
}

test('approving a planning review flips it and its pending items to approved', function () {
    $review = makePlanningReview($this->hr->id, $this->profile->id);
    $item = $review->items()->first();

    $this->actingAs($this->hr)
        ->post("/hr/compensation/reviews/{$review->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($review->fresh()->status)->toBe('approved');
    expect($item->fresh()->status)->toBe('approved');
    expect($item->fresh()->approved_by)->toBe($this->hr->id);
});

test('approve unlocks apply end-to-end: applying then updates the employee salary', function () {
    $review = makePlanningReview($this->hr->id, $this->profile->id);

    // Approve first (the previously-missing transition).
    $this->actingAs($this->hr)
        ->post("/hr/compensation/reviews/{$review->id}/approve")
        ->assertSessionHas('success');

    // Now the existing apply pipeline becomes reachable.
    $this->actingAs($this->hr)
        ->post("/hr/compensation/reviews/{$review->id}/apply")
        ->assertSessionHas('success');

    expect($review->fresh()->status)->toBe('applied');
    expect((float) $this->profile->fresh()->annual_salary)->toBe(66000.0);

    $this->assertDatabaseHas('hr_compensation_history', [
        'employee_profile_id' => $this->profile->id,
        'change_type' => 'review',
    ]);
});

test('applying a review that has not been approved surfaces an error instead of a 500', function () {
    $review = makePlanningReview($this->hr->id, $this->profile->id);

    $this->actingAs($this->hr)
        ->post("/hr/compensation/reviews/{$review->id}/apply")
        ->assertSessionHas('error');

    expect($review->fresh()->status)->toBe('planning');
    expect((float) $this->profile->fresh()->annual_salary)->toBe(60000.0);
});

test('a user without hr.compensation.manage cannot approve a review', function () {
    $review = makePlanningReview($this->hr->id, $this->profile->id);

    $this->actingAs($this->employee)
        ->post("/hr/compensation/reviews/{$review->id}/approve")
        ->assertForbidden();

    expect($review->fresh()->status)->toBe('planning');
});
