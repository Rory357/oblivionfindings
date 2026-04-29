<?php

namespace App\Domain\Rostering\AutoSchedule\Strategies;

use App\Domain\Rostering\AutoSchedule\RosterSuggestionContext;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionStrategy;
use App\Models\User;
use Closure;
use App\Services\ShiftAssignmentRecommendationService;
use Illuminate\Support\Collection;

class EligibilityScoringStrategy implements RosterSuggestionStrategy
{
    public function __construct(
        private readonly ShiftAssignmentRecommendationService $recommendations,
    ) {
    }

    public function suggest(RosterSuggestionContext $context, int $limitPerShift = 3): Collection
    {
        return $context->shifts
            ->flatMap(function ($shift) use ($context, $limitPerShift) {
                return collect($this->recommendations->forShift(
                    $shift,
                    $context->actor,
                    $limitPerShift,
                    [],
                    $context->candidatesFor($shift),
                    fn (User $candidate, Closure $evaluate) => $context->eligibilityFor($shift, $candidate, $evaluate),
                ))
                    ->filter(fn (array $candidate) => (bool) ($candidate['is_eligible'] ?? false))
                    ->values()
                    ->map(function (array $candidate, int $index) use ($shift) {
                        return [
                            'shift' => $shift,
                            'candidate_user_id' => (int) $candidate['id'],
                            'rank' => $index + 1,
                            'score' => (float) ($candidate['recommended_score'] ?? 0),
                            'reasons' => [
                                'weekly_hours' => $candidate['weekly_hours'] ?? null,
                                'site_familiarity' => $candidate['site_familiarity'] ?? 0,
                                'client_consistency' => $candidate['client_consistency'] ?? 0,
                                'coverage_priority' => $candidate['coverage_priority'] ?? 0,
                                'role_gap_priority' => $candidate['role_gap_priority'] ?? 0,
                                'role_coverage_bonus' => $candidate['role_coverage_bonus'] ?? 0,
                            ],
                            'eligibility_snapshot' => [
                                'is_eligible' => $candidate['is_eligible'] ?? false,
                                'blocked_reasons' => $candidate['blocked_reasons'] ?? [],
                                'warning_reasons' => $candidate['warning_reasons'] ?? [],
                                'required_roles' => $candidate['required_roles'] ?? [],
                                'matched_roles' => $candidate['matched_roles'] ?? [],
                                'missing_roles' => $candidate['missing_roles'] ?? [],
                            ],
                        ];
                    });
            })
            ->values();
    }
}
