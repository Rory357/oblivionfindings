<?php

use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Manual-first audit trail: accepting or dismissing a roster suggestion is a
 * manager decision about paid work, so the actor and time must be recorded on
 * the suggestion row. (Applying a suggestion already writes a shift timeline
 * event via ShiftLifecycleService::assign; accept/dismiss are planning-stage
 * decisions audited here, on the suggestion itself.)
 */

function makeSuggestionForRun(array $runAttributes = []): RosterSuggestion
{
    $run = RosterSuggestionRun::factory()->create(array_merge([
        'organization_id' => 1,
    ], $runAttributes));

    $shift = Shift::factory()->unassigned()->create([
        'organization_id' => 1,
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
        'status' => 'scheduled',
    ]);

    return RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => $run->id,
        'shift_id' => $shift->id,
        'candidate_user_id' => User::factory()->create(['organization_id' => 1])->id,
        'rank' => 1,
        'status' => RosterSuggestion::STATUS_SUGGESTED,
    ]);
}

it('records who accepted a suggestion and when', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    $suggestion = makeSuggestionForRun();

    $accepted = app(RosterSuggestionService::class)->accept($suggestion, $actor);

    expect($accepted->status)->toBe(RosterSuggestion::STATUS_ACCEPTED)
        ->and($accepted->accepted_by)->toBe($actor->id)
        ->and($accepted->accepted_at)->not->toBeNull()
        ->and($accepted->dismissed_by)->toBeNull()
        ->and($accepted->dismissed_at)->toBeNull();
});

it('records who dismissed a suggestion and when', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    $suggestion = makeSuggestionForRun();

    $dismissed = app(RosterSuggestionService::class)->dismiss($suggestion, $actor);

    expect($dismissed->status)->toBe(RosterSuggestion::STATUS_DISMISSED)
        ->and($dismissed->dismissed_by)->toBe($actor->id)
        ->and($dismissed->dismissed_at)->not->toBeNull();
});

it('re-accepting after a dismissal clears the dismissal attribution', function () {
    $service = app(RosterSuggestionService::class);
    $dismisser = User::factory()->create(['organization_id' => 1]);
    $accepter = User::factory()->create(['organization_id' => 1]);
    $suggestion = makeSuggestionForRun();

    $service->dismiss($suggestion, $dismisser);
    $accepted = $service->accept($suggestion->fresh(), $accepter);

    expect($accepted->status)->toBe(RosterSuggestion::STATUS_ACCEPTED)
        ->and($accepted->accepted_by)->toBe($accepter->id)
        ->and($accepted->dismissed_by)->toBeNull()
        ->and($accepted->dismissed_at)->toBeNull();
});

it('refuses to accept a suggestion from an expired run and marks it stale', function () {
    $actor = User::factory()->create(['organization_id' => 1]);
    $suggestion = makeSuggestionForRun([
        'expires_at' => now()->subHour(),
    ]);

    try {
        app(RosterSuggestionService::class)->accept($suggestion, $actor);
        $this->fail('Expected accepting an expired suggestion to abort with 422.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }

    expect($suggestion->fresh()->status)->toBe(RosterSuggestion::STATUS_STALE)
        ->and($suggestion->fresh()->accepted_by)->toBeNull();
});
