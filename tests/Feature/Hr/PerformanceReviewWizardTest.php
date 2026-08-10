<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrProbationReview;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Performance Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Performance Site']);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->employee->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    performanceReviewProfile($this->hr, $this->site);
    $this->employeeProfile = performanceReviewProfile($this->employee, $this->site);
});

function performanceReviewProfile(User $user, Site $site): HrEmployeeProfile
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
            'employee_signed_off' => true,
            'manager_signed_off' => true,
        ])
        ->assertRedirect();

    $review->refresh();
    expect($review->review_type)->toBe('annual');
    expect((int) $review->overall_rating)->toBe(5);
    expect($review->status)->toBe('draft');
    expect($review->employee_signed_off)->toBeFalse();
    expect($review->manager_signed_off)->toBeFalse();
});

test('the page-based create-review route redirects to the reviews hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/performance/reviews/create')
        ->assertRedirect(route('hr.performance.reviews.index'));
});

test('performance review evidence is concealed from viewers outside the review', function () {
    Storage::fake('private');

    $auditorRole = Role::query()->where('name', 'auditor')->firstOrFail();
    $this->employee->roles()->syncWithoutDetaching([$auditorRole->id]);

    $reviewer = User::factory()->create(['role' => 'auditor', 'approved_at' => now()]);
    $reviewer->roles()->syncWithoutDetaching([$auditorRole->id]);
    performanceReviewProfile($reviewer, $this->site);

    $outsider = User::factory()->create(['role' => 'auditor', 'approved_at' => now()]);
    $outsider->roles()->syncWithoutDetaching([$auditorRole->id]);
    performanceReviewProfile($outsider, $this->site);

    $evidencePath = 'hr/performance-reviews/private-review/evidence.pdf';
    Storage::disk('private')->put($evidencePath, 'private evidence');

    $review = HrPerformanceReview::query()->create([
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $reviewer->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-12-31',
        'status' => 'draft',
        'created_by' => $this->hr->id,
        'evidence_path' => $evidencePath,
    ]);

    $url = route('hr.performance.reviews.evidence.show', $review);

    $this->actingAs($outsider)->get($url)->assertNotFound();
    $this->actingAs($this->employee)->get($url)->assertOk();
    $this->actingAs($reviewer)->get($url)->assertOk();
});

test('review and probation lists writes and direct URLs enforce canonical Site access', function () {
    Storage::fake('private');
    $hiddenEmployee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    performanceReviewProfile($hiddenEmployee, $this->hiddenSite);
    $hiddenReview = HrPerformanceReview::query()->create([
        'employee_user_id' => $hiddenEmployee->id,
        'reviewer_user_id' => $hiddenEmployee->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-12-31',
        'status' => 'draft',
        'created_by' => $hiddenEmployee->id,
    ]);
    $hiddenProbation = HrProbationReview::query()->create([
        'employee_user_id' => $hiddenEmployee->id,
        'reviewer_user_id' => $hiddenEmployee->id,
        'review_number' => 1,
        'review_date' => today(),
        'status' => 'scheduled',
        'created_by' => $hiddenEmployee->id,
    ]);

    $hub = $this->actingAs($this->hr)->get('/hr/performance/reviews')->assertOk();
    expect(collect($hub->inertiaProps('staff'))->pluck('id'))
        ->toContain($this->employee->id)
        ->not->toContain($hiddenEmployee->id);
    expect(collect($hub->inertiaProps('reviews.data'))->pluck('id'))->not->toContain($hiddenReview->id);
    expect(collect($hub->inertiaProps('probationReviews'))->pluck('id'))->not->toContain($hiddenProbation->id);
    expect($hub->inertiaProps('stats.total'))->toBe(0);

    $this->actingAs($this->hr)->post('/hr/performance/reviews', [
        'employee_user_id' => $hiddenEmployee->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-12-31',
    ])->assertNotFound();
    $this->actingAs($this->hr)->post('/hr/performance/probation', [
        'employee_user_id' => $hiddenEmployee->id,
        'review_number' => 2,
        'review_date' => today()->toDateString(),
        'status' => 'scheduled',
    ])->assertNotFound();

    $this->actingAs($this->hr)->get("/hr/performance/reviews/{$hiddenReview->id}")->assertNotFound();
    $this->actingAs($this->hr)->get("/hr/performance/reviews/{$hiddenReview->id}/edit")->assertNotFound();
    $this->actingAs($this->hr)->put("/hr/performance/reviews/{$hiddenReview->id}", [
        'strengths' => 'Forged hidden update.',
    ])->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/performance/reviews/{$hiddenReview->id}/submit")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/performance/reviews/{$hiddenReview->id}/sign-off", [
        'decision' => 'approve',
    ])->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/performance/reviews/{$hiddenReview->id}/evidence", [
        'file' => UploadedFile::fake()->create('hidden.pdf', 20, 'application/pdf'),
    ])->assertNotFound();
    $this->actingAs($this->hr)->get("/hr/performance/reviews/{$hiddenReview->id}/evidence")->assertNotFound();
    $this->actingAs($this->hr)->put("/hr/performance/probation/{$hiddenProbation->id}", [
        'notes' => 'Forged hidden update.',
    ])->assertNotFound();

    expect($hiddenReview->fresh()->status)->toBe('draft')
        ->and($hiddenReview->fresh()->strengths)->toBeNull()
        ->and($hiddenReview->fresh()->evidence_path)->toBeNull()
        ->and($hiddenProbation->fresh()->notes)->toBeNull()
        ->and(HrPerformanceReview::query()->where('employee_user_id', $hiddenEmployee->id)->count())->toBe(1)
        ->and(HrProbationReview::query()->where('employee_user_id', $hiddenEmployee->id)->count())->toBe(1);
});

test('only the current employee can acknowledge a manager signed review', function () {
    $review = HrPerformanceReview::query()->create([
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $this->hr->id,
        'review_type' => 'annual',
        'review_period_start' => '2026-01-01',
        'review_period_end' => '2026-12-31',
        'status' => 'draft',
        'created_by' => $this->hr->id,
    ]);

    $drafts = $this->actingAs($this->employee)->get('/hr/my/reviews')->assertOk();
    expect(collect($drafts->inertiaProps('reviews.data'))->pluck('id'))->not->toContain($review->id);

    $this->actingAs($this->hr)
        ->put("/hr/performance/reviews/{$review->id}", [
            'employee_signed_off' => true,
            'manager_signed_off' => true,
            'status' => 'signed_off',
        ])
        ->assertRedirect();
    expect($review->fresh()->status)->toBe('draft')
        ->and($review->fresh()->employee_signed_off)->toBeFalse()
        ->and($review->fresh()->manager_signed_off)->toBeFalse();

    $this->actingAs($this->employee)
        ->post("/hr/performance/reviews/{$review->id}/acknowledge")
        ->assertUnprocessable();
    $this->actingAs($this->hr)
        ->post("/hr/performance/reviews/{$review->id}/submit")
        ->assertSessionHas('success');
    $this->actingAs($this->hr)
        ->post("/hr/performance/reviews/{$review->id}/sign-off", ['decision' => 'approve'])
        ->assertSessionHas('success');
    $this->actingAs($this->hr)
        ->post("/hr/performance/reviews/{$review->id}/acknowledge")
        ->assertNotFound();
    $this->actingAs($this->employee)
        ->post("/hr/performance/reviews/{$review->id}/acknowledge", [
            'employee_comments' => 'I have reviewed this with my manager.',
        ])
        ->assertSessionHas('success');

    expect($review->fresh()->status)->toBe('signed_off')
        ->and($review->fresh()->manager_signed_off)->toBeTrue()
        ->and($review->fresh()->employee_signed_off)->toBeTrue()
        ->and($review->fresh()->employee_comments)->toBe('I have reviewed this with my manager.');
    $this->actingAs($this->employee)
        ->put("/hr/my/reviews/{$review->id}", ['employee_comments' => 'Changed after acknowledgement.'])
        ->assertUnprocessable();
    expect($review->fresh()->employee_comments)->toBe('I have reviewed this with my manager.');

    $formerReview = HrPerformanceReview::query()->create([
        'employee_user_id' => $this->employee->id,
        'reviewer_user_id' => $this->hr->id,
        'review_type' => 'annual',
        'review_period_start' => '2025-01-01',
        'review_period_end' => '2025-12-31',
        'status' => 'signed_off',
        'manager_signed_off' => true,
        'manager_signed_off_at' => now(),
        'created_by' => $this->hr->id,
    ]);
    $this->employeeProfile->update(['is_active' => false, 'end_date' => today()->subDay()]);

    $this->actingAs($this->employee)->get('/hr/my/reviews')->assertNotFound();
    $this->actingAs($this->employee)
        ->post("/hr/performance/reviews/{$formerReview->id}/acknowledge")
        ->assertNotFound();
    expect($formerReview->fresh()->employee_signed_off)->toBeFalse();
});
