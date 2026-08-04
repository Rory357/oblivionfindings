<?php

use App\Domain\Hr\Jobs\SendEngagementActionPlanRemindersJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Notifications\EngagementActionPlanDueNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    Carbon::setTestNow(Carbon::parse('2026-02-18 07:30:00'));

    $this->site = Site::factory()->create(['name' => 'Action plan reminder site']);
    $this->otherSite = Site::factory()->create(['name' => 'Other action plan reminder site']);

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

    engagementReminderProfile($this->manager, $this->site);
    engagementReminderProfile($this->owner, $this->site);
    engagementReminderProfile($this->externalOwner, $this->otherSite);
});

afterEach(function () {
    Carbon::setTestNow();
});

function engagementReminderProfile(User $user, Site $site): HrEmployeeProfile
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

test('engagement action-plan reminder job sends upcoming and overdue notifications once per trigger', function () {
    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Quarterly Pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'audience_type' => 'site',
        'audience_site_ids' => [$this->site->id],
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $upcoming = HrEngagementActionPlan::query()->create([
        'survey_id' => $survey->id,
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
        'owner_user_id' => $this->owner->id,
        'title' => 'Roster recovery plan',
        'priority' => 'high',
        'status' => 'in_progress',
        'progress_percent' => 40,
        'due_date' => now()->subDay()->toDateString(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    (new SendEngagementActionPlanRemindersJob)->handle();

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

    (new SendEngagementActionPlanRemindersJob)->handle();

    $countAfterSecondRun = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->count();

    expect($countAfterSecondRun)->toBe(4);
});

test('engagement action-plan reminder job processes every Site in the application', function () {
    $siteSurvey = HrEngagementSurvey::query()->create([
        'title' => 'Site pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'audience_type' => 'site',
        'audience_site_ids' => [$this->site->id],
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $otherManager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    engagementReminderProfile($otherManager, $this->otherSite);

    HrEngagementActionPlan::query()->create([
        'survey_id' => $siteSurvey->id,
        'owner_user_id' => $this->owner->id,
        'title' => 'First Site plan',
        'priority' => 'medium',
        'status' => 'open',
        'progress_percent' => 10,
        'due_date' => now()->addDays(1)->toDateString(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $otherSiteSurvey = HrEngagementSurvey::query()->create([
        'title' => 'Other Site pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'audience_type' => 'site',
        'audience_site_ids' => [$this->otherSite->id],
        'created_by' => $otherManager->id,
        'updated_by' => $otherManager->id,
    ]);

    HrEngagementActionPlan::query()->create([
        'survey_id' => $otherSiteSurvey->id,
        'owner_user_id' => $this->externalOwner->id,
        'title' => 'Other Site plan',
        'priority' => 'medium',
        'status' => 'open',
        'progress_percent' => 15,
        'due_date' => now()->addDays(1)->toDateString(),
        'created_by' => $otherManager->id,
        'updated_by' => $otherManager->id,
    ]);

    (new SendEngagementActionPlanRemindersJob)->handle();

    $ownerHasNotification = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->where('notifiable_id', $this->owner->id)
        ->exists();

    $externalOwnerHasNotification = DB::table('notifications')
        ->where('type', EngagementActionPlanDueNotification::class)
        ->where('notifiable_id', $this->externalOwner->id)
        ->exists();

    expect($ownerHasNotification)->toBeTrue();
    expect($externalOwnerHasNotification)->toBeTrue();
    expect(DB::table('notifications')->where('type', EngagementActionPlanDueNotification::class)->count())->toBe(4);
});
