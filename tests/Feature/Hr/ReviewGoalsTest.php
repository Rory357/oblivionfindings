<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrReviewGoal;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Review Goals Site']);

    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->manager->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $this->employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    reviewGoalsProfile($this->manager, $this->site);
    reviewGoalsProfile($this->employee, $this->site);
});

function reviewGoalsProfile(User $user, Site $site): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

test('creating a review with goals writes structured hr_review_goals rows', function () {
    $this->actingAs($this->manager)->post('/hr/performance/reviews', [
        'employee_user_id' => $this->employee->id,
        'review_type' => 'annual',
        'review_period_start' => now()->subYear()->toDateString(),
        'review_period_end' => now()->toDateString(),
        'goals' => ['Lift medication competency', 'Complete leadership course'],
    ])->assertRedirect();

    $review = HrPerformanceReview::latest('id')->first();

    expect($review->reviewGoals()->count())->toBe(2);
    expect($review->reviewGoalList())->toBe(['Lift medication competency', 'Complete leadership course']);
    // Legacy JSON column is dual-written during the transition.
    expect($review->fresh()->goals)->toBe(['Lift medication competency', 'Complete leadership course']);
});

test('updating a review resyncs its structured goals', function () {
    $review = HrPerformanceReview::create([
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $this->manager->id,
        'review_type' => 'annual',
        'review_period_start' => now()->subYear(),
        'review_period_end' => now(),
        'status' => 'draft',
        'goals' => ['Old goal'],
        'created_by' => $this->manager->id,
    ]);
    $review->syncReviewGoals(['Old goal']);

    $this->actingAs($this->manager)->put("/hr/performance/reviews/{$review->id}", [
        'goals' => ['New goal A', 'New goal B'],
    ])->assertRedirect();

    expect($review->reviewGoals()->count())->toBe(2);
    expect($review->reviewGoalList())->toBe(['New goal A', 'New goal B']);
});

test('reviewGoalList falls back to the legacy JSON blob when no child rows exist', function () {
    $review = HrPerformanceReview::create([
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $this->manager->id,
        'review_type' => 'annual',
        'review_period_start' => now()->subYear(),
        'review_period_end' => now(),
        'status' => 'draft',
        'goals' => ['Legacy A', 'Legacy B'],
        'created_by' => $this->manager->id,
    ]);

    expect(HrReviewGoal::where('performance_review_id', $review->id)->count())->toBe(0);
    expect($review->reviewGoalList())->toBe(['Legacy A', 'Legacy B']);
});
