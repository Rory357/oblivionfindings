<?php

namespace App\Domain\Rostering\AutoSchedule;

use Illuminate\Support\Collection;

interface RosterSuggestionStrategy
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function suggest(RosterSuggestionContext $context, int $limitPerShift = 3): Collection;
}
