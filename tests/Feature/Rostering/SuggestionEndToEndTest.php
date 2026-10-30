<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Jobs\GenerateRosterSuggestionsJob;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * End-to-end coverage for the manual-first auto-schedule pipeline over HTTP:
 * generate suggestions -> accept the top suggestion -> bulk apply accepted.
 * Unit tests (SuggestionApplierTest) cover the applier edge cases with the
 * eligibility boundary mocked; this test drives the real routes, permission
 * middleware, feature flag, controller org checks and the applier together.
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(OperationsPermissionsSeeder::class);
    config()->set('features.rostering.auto_schedule', true);

    $this->manager = User::factory()->create([
        'role' => 'coordinator',
        'approved_at' => now(),
    ]);
    $permission = Permission::query()->where('key', 'rostering.autoSchedule')->first();
    expect($permission)->not->toBeNull();
    $this->manager->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
});

it('drives suggest, accept and apply end to end over HTTP', function () {
    $site = Site::factory()->create();
    assignSuggestionUserToSite($this->manager, $site);
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $candidateA = User::factory()->create(['approved_at' => now()]);
    $candidateB = User::factory()->create(['approved_at' => now()]);
    assignSuggestionUserToSite($candidateA, $site);
    assignSuggestionUserToSite($candidateB, $site);

    // Future week so the shift is actionable and generation runs synchronously.
    $weekStart = Carbon::parse(now()->addWeek()->startOfWeek()->toDateString(), 'Pacific/Auckland')->startOfDay();
    $shift = Shift::factory()->unassigned()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $weekStart->copy()->setTime(9, 0)->utc(),
        'ends_at' => $weekStart->copy()->setTime(13, 0)->utc(),
        'status' => 'scheduled',
    ]);

    // 1. Generate.
    $response = $this->actingAs($this->manager)->post(route('operations.rostering.auto_schedule'), [
        'week' => $weekStart->toDateString(),
        'site_id' => $site->id,
    ]);

    $run = RosterSuggestionRun::query()->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(RosterSuggestionRun::STATUS_COMPLETED);
    $response->assertRedirect(route('operations.rostering.suggestions.show', $run));

    $top = $run->suggestions()->where('shift_id', $shift->id)->orderBy('rank')->first();
    $diagnostic = '';
    if ($top === null) {
        $eligibility = app(ShiftStaffEligibilityService::class);
        $diagnostic = json_encode([
            'run_totals' => $run->totals,
            'candidate_pool_ids' => $eligibility->candidatesFor($shift)->pluck('id')->all(),
            'synthetic_candidate_blocks' => collect([$candidateA, $candidateB])->map(fn (User $candidate) => [
                'candidate_id' => $candidate->id,
                'blocking_reasons' => $eligibility->evaluate($shift, $candidate->fresh())->blocking_reasons,
            ])->all(),
        ], JSON_THROW_ON_ERROR);
    }
    $this->assertNotNull($top, $diagnostic);

    // 2. Accept the top suggestion.
    $this->actingAs($this->manager)
        ->post(route('operations.rostering.suggestions.accept', $top))
        ->assertRedirect();

    $top->refresh();
    expect($top->status)->toBe(RosterSuggestion::STATUS_ACCEPTED)
        ->and($top->accepted_by)->toBe($this->manager->id)
        ->and($top->accepted_at)->not->toBeNull();

    // 3. Warning overrides require the separate existing capability.
    $acceptedEligibility = app(ShiftStaffEligibilityService::class)
        ->evaluate($shift->fresh(), $top->candidate()->firstOrFail());
    expect(collect($acceptedEligibility->overrideable_warnings)->pluck('rule')->all())->toContain('availability')
        ->and($this->manager->canDo('shifts.overrideEligibility'))->toBeFalse();
    $this->actingAs($this->manager)
        ->post(route('operations.rostering.suggestions.apply_accepted', $run))
        ->assertForbidden();
    expect($shift->fresh()->user_id)->toBeNull()
        ->and($top->fresh()->status)->toBe(RosterSuggestion::STATUS_ACCEPTED)
        ->and($top->fresh()->applied_by)->toBeNull()
        ->and($top->fresh()->applied_at)->toBeNull()
        ->and(ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->exists())->toBeFalse();

    $this->manager->roles()->attach(Role::query()->where('name', 'coordinator')->firstOrFail());
    $this->manager = $this->manager->fresh();
    expect($this->manager->canDo('shifts.overrideEligibility'))->toBeTrue();

    // 4. Bulk apply accepted with the coordinator's current override authority.
    $applyResponse = $this->actingAs($this->manager)
        ->post(route('operations.rostering.suggestions.apply_accepted', $run));
    if ($applyResponse->getStatusCode() === 404) {
        $acceptedProfile = HrEmployeeProfile::withTrashed()->where('user_id', $top->candidate_user_id)->first();
        $this->fail(json_encode([
            'accepted_candidate_id' => $top->candidate_user_id,
            'run_site_id' => $run->site_id,
            'profile_exists' => $acceptedProfile !== null,
            'profile_deleted' => $acceptedProfile?->trashed(),
            'profile_active' => $acceptedProfile?->is_active,
            'profile_primary_site_id' => $acceptedProfile?->primary_site_id,
            'profile_secondary_site_ids' => $acceptedProfile?->secondary_site_ids,
            'profile_start_date' => $acceptedProfile?->start_date?->toDateString(),
            'profile_end_date' => $acceptedProfile?->end_date?->toDateString(),
        ], JSON_THROW_ON_ERROR));
    }
    $applyResponse->assertRedirect();

    $top->refresh();
    expect($shift->fresh()->user_id)->toBe($top->candidate_user_id)
        ->and($top->status)->toBe(RosterSuggestion::STATUS_APPLIED)
        ->and($top->applied_by)->toBe($this->manager->id)
        ->and($top->applied_at)->not->toBeNull()
        // Attribution from the accept step survives the apply.
        ->and($top->accepted_by)->toBe($this->manager->id)
        ->and($top->accepted_at)->not->toBeNull();

    $override = ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->firstOrFail();
    expect($override->user_id)->toBe($top->candidate_user_id)
        ->and($override->overridden_by)->toBe($this->manager->id)
        ->and($override->rules_overridden)->toContain('availability');
});

it('auto-schedules only current canonical Site staff who remain employed for the future shift', function (bool $queued): void {
    $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
    $this->travelTo(Carbon::parse('2026-10-05 00:30:00', $timezone)->utc());

    try {
        $site = Site::factory()->create();
        $outsideSite = Site::factory()->create();
        $this->manager->update(['name' => 'Valid manager']);
        assignSuggestionUserToSite($this->manager, $site);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $weekStart = Carbon::parse('2026-10-12', $timezone)->startOfDay();
        $shift = Shift::factory()->unassigned()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'created_by' => $this->manager->id,
            'starts_at' => $weekStart->copy()->setTime(9, 0)->utc(),
            'ends_at' => $weekStart->copy()->setTime(13, 0)->utc(),
            'coverage_roles' => ['caregiver'],
            'status' => 'scheduled',
        ]);

        $missingProfile = User::factory()->create([
            'name' => '000 Profile-less otherwise eligible worker',
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $primary = suggestionBoundaryStaffAt($site, 'Valid current primary worker');
        $localDayStart = suggestionBoundaryStaffAt($site, 'Valid local-day worker', ['start_date' => '2026-10-05']);
        $secondaryString = suggestionBoundaryStaffAt($outsideSite, 'Valid string secondary worker', [
            'secondary_site_ids' => [(string) $site->id],
        ]);
        $secondaryInteger = suggestionBoundaryStaffAt($outsideSite, 'Valid integer secondary worker', [
            'secondary_site_ids' => [$site->id],
        ]);
        $endsBeforeShift = suggestionBoundaryStaffAt($site, 'Ends before future shift', ['end_date' => '2026-10-10']);
        $ended = suggestionBoundaryStaffAt($site, 'Ended yesterday locally', ['end_date' => '2026-10-04']);
        $inactive = suggestionBoundaryStaffAt($site, 'Inactive worker', ['is_active' => false]);
        $futureStart = suggestionBoundaryStaffAt($site, 'Future employment start', ['start_date' => '2026-10-06']);
        $wrongSite = suggestionBoundaryStaffAt($outsideSite, 'Wrong Site worker');
        $unapproved = suggestionBoundaryStaffAt($site, 'Unapproved worker', userAttributes: ['approved_at' => null]);
        $deleted = suggestionBoundaryStaffAt($site, 'Deleted profile worker');
        $deleted->hrEmployeeProfile()->firstOrFail()->delete();
        $clientMember = suggestionBoundaryStaffAt($site, 'Client role membership');
        $clientMember->roles()->attach(Role::query()->where('name', 'client')->firstOrFail());
        $kinMember = suggestionBoundaryStaffAt($site, 'Next-of-kin role membership');
        $kinMember->roles()->attach(Role::query()->where('name', 'next_of_kin')->firstOrFail());
        $legacyPortal = suggestionBoundaryStaffAt($site, 'Legacy portal role', userAttributes: ['role' => 'client']);
        $onLeave = suggestionBoundaryStaffAt($site, 'Approved leave worker');
        HrLeaveRequest::factory()->create([
            'user_id' => $onLeave->id,
            'leave_type' => 'annual',
            'starts_at' => $shift->starts_at,
            'ends_at' => $shift->ends_at,
            'hours_requested' => 4,
            'status' => 'approved',
        ]);
        $hardStop = suggestionBoundaryStaffAt($site, 'Hard-stop compliance worker');
        $hardStopRole = Role::query()->create([
            'name' => 'auto_schedule_hard_stop_case',
            'label' => 'Auto-schedule hard-stop test role',
            'level' => 10,
            'type' => 'custom',
        ]);
        $hardStop->roles()->attach($hardStopRole);
        $requirement = HrComplianceRequirement::query()->create([
            'code' => 'AUTO-SCHEDULE-HARD-STOP',
            'name' => 'Auto-schedule required credential',
            'category' => 'Eligibility',
            'check_type' => 'credential',
            'hard_stop' => true,
            'is_active' => true,
            'created_by' => $this->manager->id,
        ]);
        HrComplianceMatrix::query()->create([
            'requirement_id' => $requirement->id,
            'role' => $hardStopRole->name,
            'site_type' => 'all',
            'is_mandatory' => true,
        ]);
        HrStaffComplianceStatus::query()->create([
            'user_id' => $hardStop->id,
            'requirement_id' => $requirement->id,
            'status' => 'expired',
            'expires_at' => '2026-10-04',
        ]);

        $eligibility = app(ShiftStaffEligibilityService::class);
        expect(now()->toDateString())->toBe('2026-10-04')
            ->and(now($timezone)->toDateString())->toBe('2026-10-05')
            ->and($eligibility->evaluate($shift, $missingProfile)->blocking_reasons)->toBe([])
            ->and($eligibility->candidatesFor($shift)->first()->id)->toBe($missingProfile->id)
            ->and(collect($eligibility->evaluate($shift, $onLeave->fresh())->blocking_reasons)->implode(' '))->toContain('Approved')
            ->and(collect($eligibility->evaluate($shift, $hardStop->fresh())->blocking_reasons)->implode(' '))->toContain($requirement->name);

        $currentStaff = collect([$this->manager, $primary, $localDayStart, $secondaryString, $secondaryInteger, $endsBeforeShift, $onLeave, $hardStop]);
        $expectedCandidates = collect([$this->manager, $primary, $localDayStart, $secondaryString, $secondaryInteger]);
        $excluded = collect([$missingProfile, $endsBeforeShift, $ended, $inactive, $futureStart, $wrongSite, $unapproved, $deleted, $clientMember, $kinMember, $legacyPortal, $onLeave, $hardStop]);
        $service = app(RosterSuggestionService::class);
        expect($service->estimateEvaluationCount($this->manager, $weekStart, $weekStart->copy()->addDays(7), $site->id))
            ->toBe($currentStaff->count());

        Queue::fake();
        if ($queued) {
            $run = $service->generateOrQueue($this->manager, $weekStart, $site->id, limitPerShift: 20, queueThreshold: 0);
            expect($run->status)->toBe(RosterSuggestionRun::STATUS_PENDING);
            Queue::assertPushed(GenerateRosterSuggestionsJob::class, fn (GenerateRosterSuggestionsJob $job) => $job->runId === $run->id);
            (new GenerateRosterSuggestionsJob($run->id))->handle($service);
            $run = $run->fresh('suggestions');
        } else {
            $run = $service->generate($this->manager, $weekStart, $site->id, limitPerShift: 20);
            Queue::assertNothingPushed();
        }

        expect($run->status)->toBe(RosterSuggestionRun::STATUS_COMPLETED)
            ->and($run->parameters['estimated_evaluations'])->toBe($currentStaff->count())
            ->and($run->suggestions->pluck('candidate_user_id')->sort()->values()->all())
            ->toBe($expectedCandidates->pluck('id')->sort()->values()->all())
            ->and($run->suggestions->whereIn('candidate_user_id', $excluded->pluck('id'))->count())->toBe(0)
            ->and($run->suggestions->pluck('shift_id')->unique()->all())->toBe([$shift->id]);
    } finally {
        $this->travelBack();
    }
})->with(['synchronous' => false, 'queued' => true]);

it('denies a suggestion run outside the manager Site assignment', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    assignSuggestionUserToSite($this->manager, $accessibleSite);
    $outsideRun = RosterSuggestionRun::factory()->create(['site_id' => $outsideSite->id]);

    $this->actingAs($this->manager)
        ->get(route('operations.rostering.suggestions.show', $outsideRun))
        ->assertForbidden();
});

it('refuses suggestion actions without the autoSchedule permission', function () {
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $suggestion = RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => RosterSuggestionRun::factory()->create()->id,
        'shift_id' => Shift::factory()->unassigned()->create([
            'status' => 'scheduled',
        ])->id,
        'candidate_user_id' => User::factory()->create()->id,
        'rank' => 1,
        'status' => RosterSuggestion::STATUS_SUGGESTED,
    ]);

    $this->actingAs($worker)
        ->post(route('operations.rostering.suggestions.accept', $suggestion))
        ->assertForbidden();
});

function assignSuggestionUserToSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => $user->role ?: 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

function suggestionBoundaryStaffAt(Site $site, string $name, array $profileAttributes = [], array $userAttributes = []): User
{
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userAttributes,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => $user->role ?: 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => '2025-10-01',
        'end_date' => null,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        ...$profileAttributes,
    ]);

    return $user;
}
