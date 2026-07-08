<?php

use App\Domain\Hr\Models\HrPerformanceReview;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\GovernanceTestHelpers;

uses(GovernanceTestHelpers::class);

/**
 * Seam S13 — HR staff performance ↔ Governance board performance.
 *
 * These are TWO DISTINCT, both-live domains, not a fork of one concept:
 *   - HR staff reviews: App\Domain\Hr\Models\HrPerformanceReview -> hr_performance_reviews,
 *     routed /hr/performance/reviews + /hr/reviews (manager↔employee, probation,
 *     sign-off, acknowledge). Positive write proven by PerformanceReviewWizardTest.
 *   - Governance board reviews: App\Domain\Governance\Models\PerformanceReview ->
 *     performance_reviews, routed /governance/performance (board_decision,
 *     approval_resolution, CEO milestones). Positive write proven by
 *     GovernancePerformanceReviewTest.
 *
 * D-7 CORRECTION: the ledger's D-7 premise ("the governance performance_reviews is
 * unrouted / inert, a dormant twin to retire or consolidate") is STALE — the
 * governance model is a fully-routed live board-governance module. So the seam
 * question is not "merge the dormant twin" but "does either LIVE workflow leak
 * into the other's table?". These tests prove it does not: one owner per fact,
 * no dual-write, no silent fork — the decisive invariant neither existing test
 * asserts. (Whether board-exec reviews should cross-surface into HR stays a
 * product decision for Chane, not a data-integrity issue.)
 */
test('S13 seam: the HR staff-review workflow writes hr_performance_reviews and never the governance performance_reviews table', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);
    $employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);

    $this->actingAs($hr)
        ->post('/hr/performance/reviews', [
            'employee_user_id' => $employee->id,
            'review_type' => 'annual',
            'review_period_start' => '2026-01-01',
            'review_period_end' => '2026-12-31',
            'overall_rating' => 4,
            'strengths' => 'Reliable and calm under pressure.',
            'goals' => ['Lead the intake roster'],
        ])
        ->assertRedirect();

    // The HR owner wrote its own table…
    expect(HrPerformanceReview::query()->where('employee_user_id', $employee->id)->count())->toBe(1);
    // …and never forked into the governance board-review table (one owner per fact).
    expect(DB::table('performance_reviews')->count())->toBe(0);
});

test('S13 seam: the governance board-review workflow writes performance_reviews and never the HR hr_performance_reviews table', function () {
    $this->seedGovernance();
    $admin = $this->createAdminUser();
    $reviewee = $this->createAdminUser(['email' => 'reviewee.seam@example.test']);

    $this->actingAs($admin)
        ->post('/governance/performance', [
            'reviewee_id' => $reviewee->id,
            'review_cycle' => now()->year.'-Annual',
            'review_type' => 'annual',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
        ])
        ->assertRedirect();

    // The governance owner wrote its own table…
    expect(DB::table('performance_reviews')->count())->toBe(1);
    // …and never forked into the HR staff-review table (one owner per fact).
    expect(HrPerformanceReview::query()->count())->toBe(0);
});
