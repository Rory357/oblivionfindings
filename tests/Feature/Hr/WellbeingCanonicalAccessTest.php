<?php

use App\Domain\Hr\Jobs\CalculateWellbeingIndicatorsJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use App\Domain\Hr\Services\WellbeingIndicatorService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\StaffFatigueAlertNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();

    $this->site = Site::factory()->create(['name' => 'Wellbeing Home']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Wellbeing Home']);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->owner = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $this->stranger = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->hiddenEmployee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->hiddenOwner = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);

    wellbeingCanonicalProfile($this->hr, $this->site);
    wellbeingCanonicalProfile($this->employee, $this->site);
    wellbeingCanonicalProfile($this->owner, $this->site);
    wellbeingCanonicalProfile($this->stranger, $this->site);
    wellbeingCanonicalProfile($this->hiddenEmployee, $this->hiddenSite);
    wellbeingCanonicalProfile($this->hiddenOwner, $this->hiddenSite);
});

function wellbeingCanonicalProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
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

function wellbeingCanonicalSurvey(Site $site, User $creator, array $overrides = []): HrEngagementSurvey
{
    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Site wellbeing pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'audience_type' => 'site',
        'audience_site_ids' => [$site->id],
        'published_by' => $creator->id,
        'published_at' => now()->subMinute(),
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
        ...$overrides,
    ]);
    $survey->questions()->create([
        'question_type' => 'scale',
        'question_text' => 'How supported do you feel?',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    return $survey;
}

function wellbeingCanonicalPlan(User $owner, ?User $subject, User $creator, array $overrides = []): HrEngagementActionPlan
{
    return HrEngagementActionPlan::query()->create([
        'owner_user_id' => $owner->id,
        'staff_user_id' => $subject?->id,
        'source_type' => $subject ? 'flag' : 'manual',
        'source_id' => $subject?->id,
        'title' => 'Wellbeing follow-up',
        'priority' => 'high',
        'status' => 'open',
        'progress_percent' => 0,
        'due_date' => today()->addWeek(),
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
        ...$overrides,
    ]);
}

test('wellbeing dashboard counts rows pickers plans and signals share one Site boundary', function () {
    $visibleSurvey = wellbeingCanonicalSurvey($this->site, $this->hr);
    $hiddenSurvey = wellbeingCanonicalSurvey($this->hiddenSite, $this->hr);
    $visiblePlan = wellbeingCanonicalPlan($this->owner, $this->employee, $this->hr);
    $hiddenPlan = wellbeingCanonicalPlan($this->hiddenOwner, $this->hiddenEmployee, $this->hr);

    HrWellbeingIndicator::query()->create([
        'user_id' => $this->employee->id,
        'period_start' => today()->subWeeks(4),
        'period_end' => today(),
        'flag_level' => 'red',
        'overtime_hours' => 22,
        'calculated_at' => now(),
    ]);
    HrWellbeingIndicator::query()->create([
        'user_id' => $this->hiddenEmployee->id,
        'period_start' => today()->subWeeks(4),
        'period_end' => today(),
        'flag_level' => 'red',
        'overtime_hours' => 25,
        'calculated_at' => now(),
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/wellbeing')->assertOk();

    expect(collect($response->inertiaProps('surveys'))->pluck('id'))
        ->toContain($visibleSurvey->id)
        ->not->toContain($hiddenSurvey->id);
    expect(collect($response->inertiaProps('actionPlans'))->pluck('id'))
        ->toContain($visiblePlan->id)
        ->not->toContain($hiddenPlan->id);
    expect(collect($response->inertiaProps('flaggedStaff'))->pluck('user_id'))
        ->toContain($this->employee->id)
        ->not->toContain($this->hiddenEmployee->id);
    expect(collect($response->inertiaProps('staffOptions'))->pluck('id'))
        ->toContain($this->employee->id, $this->owner->id)
        ->not->toContain($this->hiddenEmployee->id, $this->hiddenOwner->id);
    expect(collect($response->inertiaProps('sites'))->pluck('id'))
        ->toContain($this->site->id)
        ->not->toContain($this->hiddenSite->id);
    expect($response->inertiaProps('wellbeingSummary.total_staff'))->toBe(1)
        ->and($response->inertiaProps('wellbeingSummary.flagged_red'))->toBe(1)
        ->and($response->inertiaProps('slaSummary.open_total'))->toBe(1);
});

test('hidden Site surveys are concealed across every management direct route', function () {
    $survey = wellbeingCanonicalSurvey($this->hiddenSite, $this->hr, ['status' => 'draft', 'published_at' => null]);

    $this->actingAs($this->hr)->get("/hr/wellbeing/surveys/{$survey->id}")->assertNotFound();
    $this->actingAs($this->hr)->put("/hr/wellbeing/surveys/{$survey->id}", ['title' => 'Leaked'])->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/wellbeing/surveys/{$survey->id}/publish")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/wellbeing/surveys/{$survey->id}/close")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/wellbeing/surveys/{$survey->id}/duplicate")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/wellbeing/surveys/{$survey->id}/nudge")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/wellbeing/surveys/{$survey->id}/archive")->assertNotFound();
    $this->actingAs($this->hr)->delete("/hr/wellbeing/surveys/{$survey->id}")->assertNotFound();
    $this->actingAs($this->hr)->get("/hr/wellbeing/surveys/{$survey->id}/export")->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/wellbeing/surveys/{$survey->id}/action-plans", [
        'owner_user_id' => $this->owner->id,
        'title' => 'Leaked plan',
        'priority' => 'high',
    ])->assertNotFound();

    expect($survey->fresh()->title)->toBe('Site wellbeing pulse')
        ->and(HrEngagementActionPlan::query()->where('survey_id', $survey->id)->exists())->toBeFalse();
});

test('survey respondents never receive management plans owner rosters or response identities', function () {
    $survey = wellbeingCanonicalSurvey($this->site, $this->hr, ['is_anonymous' => false]);
    $question = $survey->questions()->firstOrFail();
    wellbeingCanonicalPlan($this->owner, null, $this->hr, [
        'survey_id' => $survey->id,
        'source_type' => 'survey',
        'source_id' => $survey->id,
    ]);

    $response = $this->actingAs($this->employee)
        ->get("/hr/wellbeing/surveys/{$survey->id}")
        ->assertOk();
    expect($response->inertiaProps('survey.action_plans'))->toBe([])
        ->and($response->inertiaProps('actionPlanOwners'))->toBe([])
        ->and($response->inertiaProps('responses'))->toBe([])
        ->and($response->inertiaProps('summary'))->toBeNull()
        ->and($response->inertiaProps('can.manage'))->toBeFalse();

    $this->actingAs($this->employee)
        ->post("/hr/wellbeing/surveys/{$survey->id}/responses", [
            'answers' => [(string) $question->id => 4],
        ])
        ->assertSessionHas('success');
    $this->actingAs($this->stranger)
        ->get("/hr/wellbeing/surveys/{$survey->id}")
        ->assertOk();

    $outsider = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    wellbeingCanonicalProfile($outsider, $this->hiddenSite);
    $this->actingAs($outsider)->get("/hr/wellbeing/surveys/{$survey->id}")->assertNotFound();
    $this->actingAs($outsider)->post("/hr/wellbeing/surveys/{$survey->id}/responses", [
        'answers' => [(string) $question->id => 2],
    ])->assertNotFound();
});

test('application-wide and Site survey audiences require the managers real Site scope', function () {
    $payload = [
        'title' => 'Scoped pulse',
        'survey_type' => 'pulse',
        'is_anonymous' => true,
        'questions' => [[
            'question_type' => 'scale',
            'question_text' => 'How are you?',
            'is_required' => true,
        ]],
    ];

    $this->actingAs($this->hr)
        ->post('/hr/wellbeing/surveys', [...$payload, 'audience_type' => 'all'])
        ->assertSessionHasErrors('audience_type');
    $this->actingAs($this->hr)
        ->post('/hr/wellbeing/surveys', [
            ...$payload,
            'audience_type' => 'site',
            'audience_site_ids' => [$this->hiddenSite->id],
        ])
        ->assertSessionHasErrors('audience_site_ids');
    $this->actingAs($this->hr)
        ->post('/hr/wellbeing/surveys', [
            ...$payload,
            'audience_type' => 'site',
            'audience_site_ids' => [$this->site->id],
        ])
        ->assertRedirect();

    $survey = HrEngagementSurvey::query()->where('title', 'Scoped pulse')->firstOrFail();
    expect($survey->audience_site_ids)->toBe([$this->site->id]);
});

test('signals checkins EAP and standalone plans reject hidden Site subjects before validation', function () {
    $hiddenCheckin = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->hiddenEmployee->id,
        'manager_user_id' => $this->hiddenOwner->id,
        'type' => 'welfare',
        'notes' => 'Hidden notes',
        'is_private' => false,
    ]);
    $hiddenPlan = wellbeingCanonicalPlan($this->hiddenOwner, $this->hiddenEmployee, $this->hiddenOwner);

    $this->actingAs($this->hr)->post("/hr/wellbeing/signals/{$this->hiddenEmployee->id}/acknowledge")->assertNotFound();
    $this->actingAs($this->hr)->post('/hr/wellbeing/checkins', [
        'staff_user_id' => $this->hiddenEmployee->id,
        'type' => 'welfare',
    ])->assertNotFound();
    $this->actingAs($this->hr)->patch("/hr/wellbeing/checkins/{$hiddenCheckin->id}", ['notes' => 'Leaked'])->assertNotFound();
    $this->actingAs($this->hr)->post('/hr/wellbeing/eap-referrals', [
        'staff_user_id' => $this->hiddenEmployee->id,
        'reason_category' => 'wellbeing',
        'consent_given' => true,
    ])->assertNotFound();
    $this->actingAs($this->hr)->post('/hr/wellbeing/action-plans', [
        'owner_user_id' => $this->hiddenOwner->id,
        'staff_user_id' => $this->hiddenEmployee->id,
        'source_type' => 'flag',
        'title' => 'Leaked plan',
        'priority' => 'high',
    ])->assertNotFound();
    $this->actingAs($this->hr)->put("/hr/wellbeing/action-plans/{$hiddenPlan->id}", ['status' => 'completed'])->assertNotFound();
    $this->actingAs($this->hr)->post("/hr/wellbeing/action-plans/{$hiddenPlan->id}/notes", ['body' => 'Leaked'])->assertNotFound();

    expect($hiddenCheckin->fresh()->notes)->toBe('Hidden notes')
        ->and($hiddenPlan->fresh()->status)->toBe('open');
});

test('only the exact current employee acknowledges shared checkins and exact owners update visible plans', function () {
    $shared = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->employee->id,
        'manager_user_id' => $this->hr->id,
        'type' => 'welfare',
        'notes' => 'Shared follow-up',
        'is_private' => false,
    ]);
    $private = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->employee->id,
        'manager_user_id' => $this->hr->id,
        'type' => 'welfare',
        'notes' => 'Manager-only note',
        'is_private' => true,
    ]);
    $plan = wellbeingCanonicalPlan($this->owner, $this->employee, $this->hr);

    $this->actingAs($this->stranger)->post("/hr/wellbeing/checkins/{$shared->id}/acknowledge")->assertNotFound();
    $this->actingAs($this->employee)->post("/hr/wellbeing/checkins/{$private->id}/acknowledge")->assertNotFound();
    $this->actingAs($this->employee)
        ->post("/hr/wellbeing/checkins/{$shared->id}/acknowledge")
        ->assertSessionHas('success');
    expect($shared->fresh()->acknowledged_at)->not->toBeNull();

    $this->actingAs($this->stranger)
        ->put("/hr/wellbeing/action-plans/{$plan->id}", ['status' => 'in_progress'])
        ->assertNotFound();
    $this->actingAs($this->owner)
        ->put("/hr/wellbeing/action-plans/{$plan->id}", [
            'status' => 'in_progress',
            'progress_percent' => 30,
        ])
        ->assertSessionHas('success');
    $this->actingAs($this->owner)
        ->post("/hr/wellbeing/action-plans/{$plan->id}/notes", ['body' => 'First follow-up completed.'])
        ->assertSessionHas('success');

    expect($plan->fresh()->status)->toBe('in_progress')
        ->and($plan->notes()->where('body', 'First follow-up completed.')->exists())->toBeTrue();
});

test('background fatigue escalation selects only a current manager who can access the staff Site', function () {
    $hiddenManager = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
    $visibleManager = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
    $providerManagerRole = Role::query()->where('name', 'provider_manager')->firstOrFail();
    $hiddenManager->roles()->syncWithoutDetaching([$providerManagerRole->id]);
    $visibleManager->roles()->syncWithoutDetaching([$providerManagerRole->id]);
    wellbeingCanonicalProfile($hiddenManager, $this->hiddenSite);
    wellbeingCanonicalProfile($visibleManager, $this->site);

    $indicators = Mockery::mock(WellbeingIndicatorService::class);
    $indicators->shouldReceive('calculateAllIndicators')->once()->andReturn(1);
    $indicators->shouldReceive('getApplicationFlaggedStaff')->once()->with('red')->andReturn(collect([[
        'user_id' => $this->employee->id,
        'name' => $this->employee->name,
        'triggered_rules' => ['Excessive overtime'],
    ]]));
    app()->instance(WellbeingIndicatorService::class, $indicators);

    (new CalculateWellbeingIndicatorsJob)->handle();

    Notification::assertSentTo($visibleManager, StaffFatigueAlertNotification::class);
    Notification::assertNotSentTo($hiddenManager, StaffFatigueAlertNotification::class);
});
