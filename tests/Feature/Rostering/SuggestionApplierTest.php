<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function passingRosterEligibility(): EligibilityResult
{
    return new EligibilityResult(true, [], [], [], []);
}

/** Exact current staff/Site evidence, with only the action permission under test. */
function currentRosterApplierParticipant(User $user, Site $site, array $keys = []): User
{
    expect($user->forceFill(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false])->save())->toBeTrue();
    $role = Role::create(['name' => 'applier-fixture-'.Str::uuid(), 'label' => 'Scoped applier fixture', 'level' => 10, 'type' => 'custom']);
    $user->roles()->attach($role);
    foreach ($keys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
        $user->permissionOverrides()->attach($permission, ['allowed' => true]);
    }
    HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null,
        'created_by' => $user->id, 'updated_by' => $user->id]);
    $current = $user->fresh();
    expect($current->canDo('reports.viewAny'))->toBeFalse()
        ->and($current->canDo('shifts.manageAny'))->toBeFalse()
        ->and($current->hrEmployeeProfile->primary_site_id)->toBe($site->id);
    if ($keys !== []) {
        expect($current->canDo('rostering.autoSchedule'))->toBeTrue();
    }

    return $current;
}

function rosterApplierFixtureState(): array
{
    $tables = ['shifts', 'roster_suggestion_runs', 'roster_suggestions', 'shift_eligibility_overrides',
        'timeline_events', 'coverage_reservations', 'shift_tasks', 'audit_logs',
        'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

    return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
}

function rosterApplierFixtureQueueState(): array
{
    return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
        'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
    ], $entries))->all();
}

it('does not bulk apply when accepted suggestions target the same shift', function () {
    Queue::fake();
    $actor = User::factory()->create(['organization_id' => 1]);
    $firstCandidate = User::factory()->create(['organization_id' => 1]);
    $secondCandidate = User::factory()->create(['organization_id' => 1]);
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $actor = currentRosterApplierParticipant($actor, $site, ['rostering.autoSchedule']);
    $firstCandidate = currentRosterApplierParticipant($firstCandidate, $site);
    $secondCandidate = currentRosterApplierParticipant($secondCandidate, $site);
    $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
    $run = RosterSuggestionRun::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'requested_by' => $actor->id,
    ]);
    $shift = Shift::factory()->unassigned()->create([
        'service_context_id' => null,
        'client_id' => $client->id,
        'organization_id' => 1,
        'site_id' => $site->id,
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
        'status' => 'scheduled',
    ]);

    RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => $run->id,
        'shift_id' => $shift->id,
        'candidate_user_id' => $firstCandidate->id,
        'rank' => 1,
        'status' => RosterSuggestion::STATUS_ACCEPTED,
    ]);
    $duplicate = RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => $run->id,
        'shift_id' => $shift->id,
        'candidate_user_id' => $secondCandidate->id,
        'rank' => 2,
        'status' => RosterSuggestion::STATUS_ACCEPTED,
    ]);

    $eligibility = Mockery::mock(ShiftStaffEligibilityService::class);
    $eligibility->shouldReceive('evaluate')->never();
    $lifecycle = Mockery::mock(ShiftLifecycleService::class);
    $lifecycle->shouldReceive('assign')->never();

    $before = rosterApplierFixtureState();
    $queue = rosterApplierFixtureQueueState();
    $results = (new RosterSuggestionApplier($eligibility, $lifecycle))->applyAccepted($run, $actor);

    expect($results)->toMatchArray(['applied' => 0, 'stale' => 1, 'failed' => 0])
        ->and($shift->fresh()->user_id)->toBeNull()
        ->and($duplicate->fresh()->status)->toBe(RosterSuggestion::STATUS_ACCEPTED);
    expect(rosterApplierFixtureState())->toBe($before)
        ->and(rosterApplierFixtureQueueState())->toBe($queue);
});

it('does not bulk apply overlapping accepted suggestions for the same worker', function () {
    Queue::fake();
    $actor = User::factory()->create(['organization_id' => 1]);
    $candidate = User::factory()->create(['organization_id' => 1]);
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $actor = currentRosterApplierParticipant($actor, $site, ['rostering.autoSchedule']);
    $candidate = currentRosterApplierParticipant($candidate, $site);
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'service_context_id' => null,
    ]);
    $run = RosterSuggestionRun::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'requested_by' => $actor->id,
    ]);
    $firstStart = Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland');
    $secondStart = Carbon::parse('2026-05-04 10:00:00', 'Pacific/Auckland');
    $firstShift = Shift::factory()->unassigned()->create([
        'service_context_id' => null,
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $firstStart->copy()->utc(),
        'ends_at' => $firstStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
    ]);
    $secondShift = Shift::factory()->unassigned()->create([
        'service_context_id' => null,
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $secondStart->copy()->utc(),
        'ends_at' => $secondStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
    ]);

    RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => $run->id,
        'shift_id' => $firstShift->id,
        'candidate_user_id' => $candidate->id,
        'rank' => 1,
        'status' => RosterSuggestion::STATUS_ACCEPTED,
    ]);
    $overlap = RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => $run->id,
        'shift_id' => $secondShift->id,
        'candidate_user_id' => $candidate->id,
        'rank' => 1,
        'status' => RosterSuggestion::STATUS_ACCEPTED,
    ]);

    $eligibility = Mockery::mock(ShiftStaffEligibilityService::class);
    $eligibility->shouldReceive('evaluate')->once()->andReturn(passingRosterEligibility());
    $currentLifecycle = app(ShiftLifecycleService::class);
    $lifecycle = Mockery::mock($currentLifecycle);
    $lifecycle->shouldReceive('lockAssignmentBatch')->once()->andReturnUsing(
        fn ($shifts, $actor, $assignees) => $currentLifecycle->lockAssignmentBatch($shifts, $actor, $assignees),
    );
    $lifecycle->shouldReceive('assign')->never();

    $before = rosterApplierFixtureState();
    $queue = rosterApplierFixtureQueueState();
    $results = (new RosterSuggestionApplier($eligibility, $lifecycle))->applyAccepted($run, $actor);

    expect($results)->toMatchArray(['applied' => 0, 'stale' => 1, 'failed' => 0])
        ->and($firstShift->fresh()->user_id)->toBeNull()
        ->and($secondShift->fresh()->user_id)->toBeNull()
        ->and($overlap->fresh()->status)->toBe(RosterSuggestion::STATUS_ACCEPTED);
    expect(rosterApplierFixtureState())->toBe($before)
        ->and(rosterApplierFixtureQueueState())->toBe($queue);
});

it('rejects a single stale suggestion when the shift was assigned before apply', function () {
    Queue::fake();
    $actor = User::factory()->create(['organization_id' => 1]);
    $candidate = User::factory()->create(['organization_id' => 1]);
    $alreadyAssigned = User::factory()->create(['organization_id' => 1]);
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $actor = currentRosterApplierParticipant($actor, $site, ['rostering.autoSchedule']);
    $candidate = currentRosterApplierParticipant($candidate, $site);
    $alreadyAssigned = currentRosterApplierParticipant($alreadyAssigned, $site);
    $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
    $run = RosterSuggestionRun::factory()->create([
        'site_id' => $site->id,
        'requested_by' => $actor->id,
    ]);
    $shift = Shift::factory()->create([
        'service_context_id' => null,
        'client_id' => $client->id,
        'organization_id' => 1,
        'site_id' => $site->id,
        'user_id' => $alreadyAssigned->id,
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
        'status' => 'scheduled',
    ]);
    $suggestion = RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => $run->id,
        'shift_id' => $shift->id,
        'candidate_user_id' => $candidate->id,
        'status' => RosterSuggestion::STATUS_ACCEPTED,
    ]);

    $eligibility = Mockery::mock(ShiftStaffEligibilityService::class);
    $eligibility->shouldReceive('evaluate')->never();
    $currentLifecycle = app(ShiftLifecycleService::class);
    $lifecycle = Mockery::mock($currentLifecycle);
    $lifecycle->shouldReceive('lockAssignmentBatch')->once()->andReturnUsing(
        fn ($shifts, $actor, $assignees) => $currentLifecycle->lockAssignmentBatch($shifts, $actor, $assignees),
    );
    $lifecycle->shouldReceive('assign')->never();

    $before = rosterApplierFixtureState();
    $queue = rosterApplierFixtureQueueState();
    expect(fn () => (new RosterSuggestionApplier($eligibility, $lifecycle))->applyOne($suggestion, $actor))
        ->toThrow(ValidationException::class);

    expect($suggestion->fresh()->status)->toBe(RosterSuggestion::STATUS_ACCEPTED)
        ->and($shift->fresh()->user_id)->toBe($alreadyAssigned->id);
    expect(rosterApplierFixtureState())->toBe($before)
        ->and(rosterApplierFixtureQueueState())->toBe($queue);
});
