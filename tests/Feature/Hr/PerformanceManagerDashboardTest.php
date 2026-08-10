<?php

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Performance Manager Site']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    performanceManagerProfile($this->hr, $this->site);
    performanceManagerProfile($this->staff, $this->site);
});

function performanceManagerProfile(User $user, Site $site): HrEmployeeProfile
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

test('performance dashboard returns manager one to one and competency signals', function () {
    HrSupervisionNote::query()->create([
        'employee_user_id' => $this->staff->id,
        'supervisor_user_id' => $this->hr->id,
        'session_date' => now()->subDays(10)->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'General check-in',
        'next_session_date' => now()->subDay()->toDateString(),
        'created_by' => $this->hr->id,
    ]);

    HrSupervisionNote::query()->create([
        'employee_user_id' => $this->staff->id,
        'supervisor_user_id' => $this->hr->id,
        'session_date' => now()->subDays(3)->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Goal progress',
        'next_session_date' => now()->addDays(4)->toDateString(),
        'created_by' => $this->hr->id,
    ]);

    HrPerformanceReview::query()->create([
        'employee_user_id' => $this->staff->id,
        'reviewer_user_id' => $this->hr->id,
        'review_type' => 'annual',
        'review_period_start' => now()->subYear()->toDateString(),
        'review_period_end' => now()->toDateString(),
        'status' => 'scheduled',
        'next_review_date' => now()->subDays(2)->toDateString(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $goal = HrDevelopmentGoal::query()->create([
        'employee_user_id' => $this->staff->id,
        'manager_user_id' => $this->hr->id,
        'title' => 'Improve de-escalation skills',
        'category' => 'capability',
        'competency_area' => 'De-escalation',
        'target_level' => 4,
        'current_level' => 2,
        'status' => 'in_progress',
        'progress_percent' => 30,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Team pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrEngagementActionPlan::query()->create([
        'survey_id' => $survey->id,
        'owner_user_id' => $this->staff->id,
        'title' => 'Reduce weekend overtime',
        'priority' => 'high',
        'status' => 'open',
        'progress_percent' => 20,
        'due_date' => now()->subDay()->toDateString(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/performance');
    $response->assertOk();

    // Unified hub aggregator renders without error and surfaces the signals.
    expect($response->inertiaProps('supervision.overdue_count'))->toBeGreaterThan(0);

    $reviewStatuses = collect($response->inertiaProps('reviews'))->pluck('status')->all();
    expect($reviewStatuses)->toContain('overdue');

    $devIds = collect($response->inertiaProps('development'))->pluck('id')->all();
    expect($devIds)->toContain($goal->id);

    // Hero exposes the clickable stat scaffold.
    expect($response->inertiaProps('hero.stats'))->not->toBeEmpty();
});
