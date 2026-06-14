<?php

use App\Domain\Hr\Models\HrPerformanceReview;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
});

test('the reviews hub ships the review-wizard staff and types', function () {
    $response = $this->actingAs($this->hr)->get('/hr/performance/reviews');
    $response->assertOk();

    expect(collect($response->inertiaProps('reviewTypes'))->pluck('value'))
        ->toContain('annual');
    expect($response->inertiaProps('staff'))->not->toBeNull();
});

test('a performance review can be created via the wizard endpoint', function () {
    $this->actingAs($this->hr)
        ->post('/hr/performance/reviews', [
            'employee_user_id' => $this->employee->id,
            'review_type' => 'annual',
            'review_period_start' => '2026-01-01',
            'review_period_end' => '2026-12-31',
            'overall_rating' => 4,
            'strengths' => 'Reliable and calm under pressure.',
            'goals' => ['Lead the intake roster', 'Med competency'],
            'training_recommendations' => ['First aid refresher'],
        ])
        ->assertRedirect();

    $review = HrPerformanceReview::query()
        ->where('employee_user_id', $this->employee->id)
        ->first();

    expect($review)->not->toBeNull();
    expect($review->reviewer_user_id)->toBe($this->hr->id);
    expect($review->status)->toBe('draft');
    expect($review->goals)->toHaveCount(2);
});

test('a performance review can be edited via the wizard endpoint', function () {
    $review = HrPerformanceReview::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $this->hr->id,
        'review_type' => 'quarterly',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-03-31',
        'status' => 'draft',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/performance/reviews/{$review->id}", [
            'review_type' => 'annual',
            'overall_rating' => 5,
            'status' => 'completed',
        ])
        ->assertRedirect();

    $review->refresh();
    expect($review->review_type)->toBe('annual');
    expect((int) $review->overall_rating)->toBe(5);
    expect($review->status)->toBe('completed');
});

test('the page-based create-review route redirects to the reviews hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/performance/reviews/create')
        ->assertRedirect(route('hr.performance.reviews.index'));
});
