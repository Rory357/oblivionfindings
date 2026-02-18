<?php

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->setAttribute('tenant_id', 1);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->staff->setAttribute('tenant_id', 1);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
});

test('performance dashboard returns manager one to one and competency signals', function () {
    HrSupervisionNote::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->staff->id,
        'supervisor_user_id' => $this->hr->id,
        'session_date' => now()->subDays(10)->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'General check-in',
        'next_session_date' => now()->subDay()->toDateString(),
        'created_by' => $this->hr->id,
    ]);

    HrSupervisionNote::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->staff->id,
        'supervisor_user_id' => $this->hr->id,
        'session_date' => now()->subDays(3)->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Goal progress',
        'next_session_date' => now()->addDays(4)->toDateString(),
        'created_by' => $this->hr->id,
    ]);

    HrPerformanceReview::query()->create([
        'tenant_id' => 1,
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
        'tenant_id' => 1,
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
        'tenant_id' => 1,
        'title' => 'Team pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrEngagementActionPlan::query()->create([
        'survey_id' => $survey->id,
        'tenant_id' => 1,
        'owner_user_id' => $this->staff->id,
        'title' => 'Reduce weekend overtime',
        'priority' => 'high',
        'status' => 'open',
        'progress_percent' => 20,
        'due_date' => now()->subDay()->toDateString(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)->get("/hr/performance?staff_id={$this->staff->id}");
    $response->assertOk();

    expect($response->inertiaProps('oneToOneSla.overdue_count'))->toBeGreaterThan(0);
    expect($response->inertiaProps('engagementActionPlanSla.overdue'))->toBeGreaterThan(0);

    $gapIds = collect($response->inertiaProps('competencyGaps'))->pluck('id')->all();
    expect($gapIds)->toContain($goal->id);
});
