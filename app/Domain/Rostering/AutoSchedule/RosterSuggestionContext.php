<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\Eligibility\EligibilityResult;
use Closure;
use Illuminate\Support\Collection;

class RosterSuggestionContext
{
    /**
     * @var array<int, Collection<int, User>>
     */
    private array $candidatePools = [];

    /**
     * @var array<int, array<int, EligibilityResult>>
     */
    private array $eligibilityCache = [];

    /**
     * @param  Collection<int, Shift>  $shifts
     */
    public function __construct(
        public readonly RosterSuggestionRun $run,
        public readonly User $actor,
        public readonly Collection $shifts,
    ) {
    }

    /**
     * @param  Collection<int, User>  $candidates
     */
    public function setCandidatePool(Shift $shift, Collection $candidates): void
    {
        $this->candidatePools[$shift->id] = $candidates;
    }

    /**
     * @return Collection<int, User>|null
     */
    public function candidatesFor(Shift $shift): ?Collection
    {
        return $this->candidatePools[$shift->id] ?? null;
    }

    public function eligibilityFor(Shift $shift, User $user, Closure $resolver): EligibilityResult
    {
        $shiftId = (int) $shift->id;
        $userId = (int) $user->id;

        if (! isset($this->eligibilityCache[$shiftId][$userId])) {
            $this->eligibilityCache[$shiftId][$userId] = $resolver();
        }

        return $this->eligibilityCache[$shiftId][$userId];
    }

    public function eligibilityCacheSize(): int
    {
        return collect($this->eligibilityCache)->sum(fn (array $users) => count($users));
    }
}
