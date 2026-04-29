<?php

use App\Domain\Rostering\AutoSchedule\RosterSuggestionContext;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use Illuminate\Support\Collection;

it('caches eligibility results per shift and user for a suggestion run', function () {
    $run = new RosterSuggestionRun;
    $actor = new User(['name' => 'Coordinator']);
    $actor->id = 10;
    $shift = new Shift;
    $shift->id = 20;
    $candidate = new User(['name' => 'Worker']);
    $candidate->id = 30;
    $result = new EligibilityResult(true, [], [], [], []);
    $calls = 0;

    $context = new RosterSuggestionContext($run, $actor, new Collection([$shift]));

    $first = $context->eligibilityFor($shift, $candidate, function () use (&$calls, $result) {
        $calls++;

        return $result;
    });

    $second = $context->eligibilityFor($shift, $candidate, function () use (&$calls, $result) {
        $calls++;

        return $result;
    });

    expect($first)->toBe($result)
        ->and($second)->toBe($result)
        ->and($calls)->toBe(1)
        ->and($context->eligibilityCacheSize())->toBe(1);
});
