<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    Carbon::setTestNow(Carbon::parse('2026-02-18 07:30:00'));

    $this->site = Site::factory()->create(['name' => 'Wellbeing dashboard site']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->ownerA = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->ownerB = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    foreach ([$this->hr, $this->ownerA, $this->ownerB] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

test('wellbeing dashboard returns action-plan sla metrics and applies action-plan filters', function () {
    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Workload Wellbeing Survey',
        'survey_type' => 'engagement',
        'status' => 'published',
        'is_anonymous' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $openPlan = HrEngagementActionPlan::query()->create([
        'survey_id' => $survey->id,
        'owner_user_id' => $this->ownerA->id,
        'title' => 'Open plan owner A',
        'priority' => 'medium',
        'status' => 'open',
        'progress_percent' => 25,
        'due_date' => now()->addDays(2)->toDateString(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrEngagementActionPlan::query()->create([
        'survey_id' => $survey->id,
        'owner_user_id' => $this->ownerA->id,
        'title' => 'In progress overdue owner A',
        'priority' => 'high',
        'status' => 'in_progress',
        'progress_percent' => 45,
        'due_date' => now()->subDay()->toDateString(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrEngagementActionPlan::query()->create([
        'survey_id' => $survey->id,
        'owner_user_id' => $this->ownerB->id,
        'title' => 'Completed plan owner B',
        'priority' => 'low',
        'status' => 'completed',
        'progress_percent' => 100,
        'due_date' => now()->addDays(10)->toDateString(),
        'completed_at' => now()->subDays(2)->toDateString(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)
        ->get("/hr/wellbeing?status=open&owner={$this->ownerA->id}");

    $response->assertOk();
    $props = $response->inertiaProps();

    expect($props['filters']['status'])->toBe('open');
    expect($props['filters']['owner'])->toBe($this->ownerA->id);

    expect($props['slaSummary']['open_total'])->toBe(2);
    expect($props['slaSummary']['overdue'])->toBe(1);
    expect($props['slaSummary']['completed_last_30_days'])->toBe(1);

    $actionPlanIds = collect($props['actionPlans'])->pluck('id')->all();
    expect($actionPlanIds)->toBe([$openPlan->id]);

    $ownerIds = collect($props['actionPlanOwners'])->pluck('id')->all();
    expect($ownerIds)->toContain($this->ownerA->id);
    expect($ownerIds)->toContain($this->ownerB->id);

    $workloadOwnerIds = collect($props['ownerWorkload'])->pluck('owner_user_id')->all();
    expect($workloadOwnerIds)->toContain($this->ownerA->id);
});
