<?php

use App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Models\Client;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

function passingRosterEligibility(): EligibilityResult
{
    return new EligibilityResult(true, [], [], [], []);
}

it('does not bulk apply when accepted suggestions target the same shift', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    $firstCandidate = User::factory()->create(['organization_id' => 1]);
    $secondCandidate = User::factory()->create(['organization_id' => 1]);
    $site = Site::factory()->create();
    $run = RosterSuggestionRun::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'requested_by' => $actor->id,
    ]);
    $shift = Shift::factory()->unassigned()->create([
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
    $eligibility->shouldReceive('evaluate')->once()->andReturn(passingRosterEligibility());
    $lifecycle = Mockery::mock(ShiftLifecycleService::class);
    $lifecycle->shouldReceive('assign')->never();

    $results = (new RosterSuggestionApplier($eligibility, $lifecycle))->applyAccepted($run, $actor);

    expect($results)->toMatchArray(['applied' => 0, 'stale' => 1, 'failed' => 0])
        ->and($shift->fresh()->user_id)->toBeNull()
        ->and($duplicate->fresh()->status)->toBe(RosterSuggestion::STATUS_ACCEPTED);
});

it('does not bulk apply overlapping accepted suggestions for the same worker', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    $candidate = User::factory()->create(['organization_id' => 1]);
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $run = RosterSuggestionRun::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'requested_by' => $actor->id,
    ]);
    $firstStart = Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland');
    $secondStart = Carbon::parse('2026-05-04 10:00:00', 'Pacific/Auckland');
    $firstShift = Shift::factory()->unassigned()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $firstStart->copy()->utc(),
        'ends_at' => $firstStart->copy()->addHours(4)->utc(),
        'status' => 'scheduled',
    ]);
    $secondShift = Shift::factory()->unassigned()->create([
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
    $lifecycle = Mockery::mock(ShiftLifecycleService::class);
    $lifecycle->shouldReceive('assign')->never();

    $results = (new RosterSuggestionApplier($eligibility, $lifecycle))->applyAccepted($run, $actor);

    expect($results)->toMatchArray(['applied' => 0, 'stale' => 1, 'failed' => 0])
        ->and($firstShift->fresh()->user_id)->toBeNull()
        ->and($secondShift->fresh()->user_id)->toBeNull()
        ->and($overlap->fresh()->status)->toBe(RosterSuggestion::STATUS_ACCEPTED);
});

it('rejects a single stale suggestion when the shift was assigned before apply', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    $candidate = User::factory()->create(['organization_id' => 1]);
    $alreadyAssigned = User::factory()->create(['organization_id' => 1]);
    $site = Site::factory()->create();
    $run = RosterSuggestionRun::factory()->create([
        'site_id' => $site->id,
        'requested_by' => $actor->id,
    ]);
    $shift = Shift::factory()->create([
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
    $lifecycle = Mockery::mock(ShiftLifecycleService::class);
    $lifecycle->shouldReceive('assign')->never();

    expect(fn () => (new RosterSuggestionApplier($eligibility, $lifecycle))->applyOne($suggestion, $actor))
        ->toThrow(ValidationException::class);

    expect($suggestion->fresh()->status)->toBe(RosterSuggestion::STATUS_ACCEPTED);
});
