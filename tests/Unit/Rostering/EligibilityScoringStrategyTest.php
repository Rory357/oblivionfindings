<?php

use App\Domain\Rostering\AutoSchedule\RosterSuggestionContext;
use App\Domain\Rostering\AutoSchedule\Strategies\EligibilityScoringStrategy;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftAssignmentRecommendationService;
use Illuminate\Support\Collection;

afterEach(function () {
    Mockery::close();
});

it('maps eligible recommendation results into persisted suggestion payloads', function () {
    $run = new RosterSuggestionRun;
    $actor = new User(['name' => 'Coordinator']);
    $actor->id = 1;
    $shift = new Shift;
    $shift->id = 2;
    $candidate = new User(['name' => 'Worker']);
    $candidate->id = 3;
    $candidatePool = new Collection([$candidate]);
    $resolverWasInvoked = false;

    $context = new RosterSuggestionContext($run, $actor, new Collection([$shift]));
    $context->setCandidatePool($shift, $candidatePool);

    $recommendations = Mockery::mock(ShiftAssignmentRecommendationService::class);
    $recommendations
        ->shouldReceive('forShift')
        ->once()
        ->withArgs(function (
            Shift $receivedShift,
            User $receivedActor,
            int $limit,
            array $bypassPermissions,
            Collection $receivedPool,
            Closure $eligibilityResolver,
        ) use ($shift, $actor, $candidatePool, $candidate, &$resolverWasInvoked): bool {
            $resolverWasInvoked = $eligibilityResolver(
                $candidate,
                fn () => new EligibilityResult(true, [], [], [], []),
            ) instanceof EligibilityResult;

            return $receivedShift === $shift
                && $receivedActor === $actor
                && $limit === 2
                && $bypassPermissions === []
                && $receivedPool === $candidatePool;
        })
        ->andReturn([
            [
                'id' => 3,
                'recommended_score' => 42,
                'is_eligible' => true,
                'blocked_reasons' => [],
                'warning_reasons' => [],
                'weekly_hours' => 12.5,
                'site_familiarity' => 2,
                'client_consistency' => 1,
            ],
            [
                'id' => 4,
                'recommended_score' => 99,
                'is_eligible' => false,
                'blocked_reasons' => ['Blocked'],
                'warning_reasons' => [],
            ],
        ]);

    $suggestions = (new EligibilityScoringStrategy($recommendations))->suggest($context, 2);

    expect($resolverWasInvoked)->toBeTrue()
        ->and($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['shift'])->toBe($shift)
        ->and($suggestions[0]['candidate_user_id'])->toBe(3)
        ->and($suggestions[0]['rank'])->toBe(1)
        ->and($suggestions[0]['score'])->toBe(42.0)
        ->and($suggestions[0]['eligibility_snapshot']['is_eligible'])->toBeTrue();
});
