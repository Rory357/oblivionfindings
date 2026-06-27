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

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
});

function reviewWithPendingItem(int $createdBy): array
{
    $profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => User::factory()->create(['organization_id' => 1, 'approved_at' => now()])->id,
        'employee_number' => 'EMP-REV-'.fake()->unique()->numberBetween(1000, 999999),
        'work_email' => 'rev'.fake()->unique()->numberBetween(1000, 999999).'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $review = HrCompensationReview::query()->create([
        'tenant_id' => 1,
        'title' => 'FY26 Annual',
        'review_cycle' => 'annual',
        'effective_date' => '2026-07-01',
        'status' => 'planning',
        'created_by' => $createdBy,
    ]);

    $item = $review->items()->create([
        'employee_profile_id' => $profile->id,
        'current_salary' => 50000,
        'proposed_salary' => 55000,
        'change_percentage' => 10,
        'status' => 'pending',
    ]);

    return [$review, $item];
}

test('a manager can approve a single review line-item', function () {
    [$review, $item] = reviewWithPendingItem($this->hr->id);

    $this->actingAs($this->hr)
        ->post("/hr/compensation/reviews/{$review->id}/items/{$item->id}/approve")
        ->assertSessionHas('success');

    $item->refresh();
    expect($item->status)->toBe('approved');
    expect($item->approved_by)->toBe($this->hr->id);
});

test('a manager can reject a single line-item with a reason', function () {
    [$review, $item] = reviewWithPendingItem($this->hr->id);

    $this->actingAs($this->hr)
        ->post("/hr/compensation/reviews/{$review->id}/items/{$item->id}/reject", [
            'reason' => 'Out of budget this cycle',
        ])
        ->assertSessionHas('success');

    $item->refresh();
    expect($item->status)->toBe('rejected');
    expect($item->justification)->toContain('Out of budget this cycle');
});

test('approving an item via the wrong review 404s', function () {
    [, $item] = reviewWithPendingItem($this->hr->id);
    [$otherReview] = reviewWithPendingItem($this->hr->id);

    $this->actingAs($this->hr)
        ->post("/hr/compensation/reviews/{$otherReview->id}/items/{$item->id}/approve")
        ->assertNotFound();

    expect($item->fresh()->status)->toBe('pending');
});

test('a non-manager cannot approve a line-item', function () {
    [$review, $item] = reviewWithPendingItem($this->hr->id);

    $this->actingAs($this->worker)
        ->post("/hr/compensation/reviews/{$review->id}/items/{$item->id}/approve")
        ->assertForbidden();

    expect($item->fresh()->status)->toBe('pending');
});
