<?php

use App\Domain\Hr\Jobs\SendEngagementActionPlanRemindersJob;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Notifications\EngagementActionPlanDueNotification;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    Carbon::setTestNow(Carbon::parse('2026-02-18 07:30:00'));

    $this->manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->owner = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->externalOwner = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->manager->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $workerRole = Role::query()->where('name', 'support_worker')->first();
    if ($workerRole) {
        $this->owner->roles()->syncWithoutDetaching([$workerRole->id]);
        $this->externalOwner->roles()->syncWithoutDetaching([$workerRole->id]);
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

test('engagement action-plan reminder job sends upcoming and overdue notifications once per trigger', function () {
    $survey = HrEngagementSurvey::query()->create([
        'tenant_id' => 1,
        'title' => 'Quarterly Pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $upcoming = HrEngagementActionPlan::query()->create([
        'survey_id' => $survey->id,
        'tenant_id' => 1,
        'owner_user_id' => $this->owner->id,
        'title' => 'Workload rebalance',
        'priority' => 'medium',
        'status' => 'open',
        'progress_percent' => 20,
        'due_date' => now()->addDays(3)->toDateString(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $overdue = HrEngagementActionPlan::query()->create([
        'survey_id' => $survey->id,
        'tenant_id' => 1,
        'owner_user_id' => $this->owner->id,
        'title' => 'Roster recovery plan',
        'priority' => 'high',
        'status' => 'in_progress',
        'progress_percent' => 40,
        'due_date' => now()->subDay()->toDateString(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    (new SendEngagementActionPlanRemindersJob(1))->handle();

    $rows = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->get();

    expect($rows)->toHaveCount(4);

    $upcomingOwnerNotification = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->where('notifiable_id', $this->owner->id)
        ->where('data->action_plan_id', $upcoming->id)
        ->where('data->reminder_kind', 'upcoming')
        ->where('data->days_until_due', 3)
        ->exists();

    $overdueManagerNotification = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->where('notifiable_id', $this->manager->id)
        ->where('data->action_plan_id', $overdue->id)
        ->where('data->reminder_kind', 'overdue')
        ->where('data->days_until_due', -1)
        ->exists();

    expect($upcomingOwnerNotification)->toBeTrue();
    expect($overdueManagerNotification)->toBeTrue();

    (new SendEngagementActionPlanRemindersJob(1))->handle();

    $countAfterSecondRun = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->count();

    expect($countAfterSecondRun)->toBe(4);
});

test('engagement action-plan reminder job respects tenant scoping', function () {
    $tenantOneSurvey = HrEngagementSurvey::query()->create([
        'tenant_id' => 1,
        'title' => 'Tenant 1 Pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $tenantTwoManager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    HrEngagementActionPlan::query()->create([
        'survey_id' => $tenantOneSurvey->id,
        'tenant_id' => 1,
        'owner_user_id' => $this->owner->id,
        'title' => 'Tenant one plan',
        'priority' => 'medium',
        'status' => 'open',
        'progress_percent' => 10,
        'due_date' => now()->addDays(1)->toDateString(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $tenantTwoSurvey = HrEngagementSurvey::query()->create([
        'tenant_id' => 2,
        'title' => 'Tenant 2 Pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'created_by' => $tenantTwoManager->id,
        'updated_by' => $tenantTwoManager->id,
    ]);

    HrEngagementActionPlan::query()->create([
        'survey_id' => $tenantTwoSurvey->id,
        'tenant_id' => 2,
        'owner_user_id' => $this->externalOwner->id,
        'title' => 'Tenant two plan',
        'priority' => 'medium',
        'status' => 'open',
        'progress_percent' => 15,
        'due_date' => now()->addDays(1)->toDateString(),
        'created_by' => $tenantTwoManager->id,
        'updated_by' => $tenantTwoManager->id,
    ]);

    (new SendEngagementActionPlanRemindersJob(1))->handle();

    $ownerHasNotification = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->where('notifiable_id', $this->owner->id)
        ->exists();

    $externalOwnerHasNotification = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->where('notifiable_id', $this->externalOwner->id)
        ->exists();

    expect($ownerHasNotification)->toBeTrue();
    expect($externalOwnerHasNotification)->toBeFalse();
});
