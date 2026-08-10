<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Wellbeing Site']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
    wellbeingOwnerProfile($this->hr, $this->site);
});

function wellbeingOwnerProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$overrides,
    ]);
}

test('wellbeing survey page exposes only current action-plan owner options', function () {
    $activeOwner = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $inactiveOwner = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $endedOwner = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::query()->create([
        'user_id' => $activeOwner->id,
        'employee_number' => 'EMP99101',
        'work_email' => "active-{$activeOwner->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $this->site->id,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrEmployeeProfile::query()->create([
        'user_id' => $inactiveOwner->id,
        'employee_number' => 'EMP99102',
        'work_email' => "inactive-{$inactiveOwner->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $this->site->id,
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => false,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrEmployeeProfile::query()->create([
        'user_id' => $endedOwner->id,
        'employee_number' => 'EMP99103',
        'work_email' => "ended-{$endedOwner->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $this->site->id,
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->subDay()->toDateString(),
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Survey owners check',
        'survey_type' => 'pulse',
        'status' => 'draft',
        'is_anonymous' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($this->hr)->get("/hr/wellbeing/surveys/{$survey->id}");
    $response->assertOk();

    $owners = collect($response->inertiaProps('actionPlanOwners'));
    $ownerIds = $owners->pluck('id')->all();

    expect($ownerIds)->toContain($activeOwner->id);
    expect($ownerIds)
        ->not->toContain($inactiveOwner->id)
        ->not->toContain($endedOwner->id);
});

test('current staff outside a surveys Site audience cannot show or submit it', function () {
    $viewerSite = Site::factory()->create();
    $audienceSite = Site::factory()->create();
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $viewer->id,
        'employee_number' => 'SURVEY-'.$viewer->id,
        'work_email' => "survey-{$viewer->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $viewerSite->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Hidden Site pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => false,
        'audience_type' => 'site',
        'audience_site_ids' => [$audienceSite->id],
        'published_at' => now()->subMinute(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $question = $survey->questions()->create([
        'question_type' => 'scale',
        'question_text' => 'How supported do you feel?',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($viewer)->get("/hr/wellbeing/surveys/{$survey->id}")->assertNotFound();
    $this->actingAs($viewer)->post("/hr/wellbeing/surveys/{$survey->id}/responses", [
        'answers' => [(string) $question->id => 4],
    ])->assertNotFound();

    $this->assertDatabaseMissing('hr_engagement_survey_responses', [
        'survey_id' => $survey->id,
        'user_id' => $viewer->id,
    ]);
});

test('current staff in a surveys Site audience can show and submit it', function () {
    $site = Site::factory()->create();
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $viewer->id,
        'employee_number' => 'SURVEY-'.$viewer->id,
        'work_email' => "survey-{$viewer->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Visible Site pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => false,
        'audience_type' => 'site',
        'audience_site_ids' => [$site->id],
        'published_at' => now()->subMinute(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $question = $survey->questions()->create([
        'question_type' => 'scale',
        'question_text' => 'How supported do you feel?',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($viewer)->get("/hr/wellbeing/surveys/{$survey->id}")->assertOk();
    $this->actingAs($viewer)->post("/hr/wellbeing/surveys/{$survey->id}/responses", [
        'answers' => [(string) $question->id => 4],
    ])->assertRedirect();

    $this->assertDatabaseHas('hr_engagement_survey_responses', [
        'survey_id' => $survey->id,
        'user_id' => $viewer->id,
    ]);
});

test('the wellbeing employee view excludes surveys for another Site', function () {
    $viewerSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $viewer->permissionOverrides()->attach(
        Permission::query()->where('key', 'hr.wellbeing.view')->firstOrFail()->id,
        ['allowed' => true],
    );
    HrEmployeeProfile::query()->create([
        'user_id' => $viewer->id,
        'employee_number' => 'SURVEY-HUB-'.$viewer->id,
        'work_email' => "survey-hub-{$viewer->id}@example.test",
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $viewerSite->id,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $visible = HrEngagementSurvey::query()->create([
        'title' => 'Visible wellbeing pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'audience_type' => 'site',
        'audience_site_ids' => [$viewerSite->id],
        'published_at' => now()->subMinute(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
    $hidden = HrEngagementSurvey::query()->create([
        'title' => 'Hidden wellbeing pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'audience_type' => 'site',
        'audience_site_ids' => [$otherSite->id],
        'published_at' => now()->subMinute(),
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $response = $this->actingAs($viewer)->get('/hr/wellbeing')->assertOk();

    foreach (['surveys', 'my.surveys'] as $prop) {
        expect(collect($response->inertiaProps($prop))->pluck('id'))
            ->toContain($visible->id)
            ->not->toContain($hidden->id);
    }
});
