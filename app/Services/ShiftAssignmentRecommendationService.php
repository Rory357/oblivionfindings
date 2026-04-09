<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\User;

class ShiftAssignmentRecommendationService
{
    public function __construct(
        protected ShiftStaffEligibilityService $eligibility,
        protected ShiftCoverageService $coverage,
    ) {
    }

    public function forShift(Shift $shift, ?User $viewer = null, int $limit = 8): array
    {
        $shift->loadMissing(['client:id,first_name,last_name,site_id']);
        $coverage = $this->coverage->coverageStatusForShift($shift);
        $coveragePriority = $coverage && ($coverage['coverage_state'] ?? null) === 'under'
            ? (int) (($coverage['missing_staff'] ?? 0) * 10) + (int) (($coverage['unfilled_after_open_shifts'] ?? 0) * 15)
            : 0;
        $roleGapPriority = $coverage && (! empty($coverage['has_planned_role_gap']) || ! empty($coverage['has_role_gap']))
            ? collect($coverage['planned_role_shortages'] ?? $coverage['role_shortages'] ?? [])
                ->sum(fn (array $role) => (int) ($role['missing'] ?? 0) * 12)
            : 0;

        $staffQuery = User::staff()->orderBy('name');

        if ($viewer?->organization_id) {
            $staffQuery->where('organization_id', $viewer->organization_id);
        }

        $candidates = $staffQuery->get(['id', 'name', 'email']);

        $results = $candidates->map(function (User $user) use ($shift, $coveragePriority, $roleGapPriority) {
            $siteId = $shift->site_id ?: $shift->client?->site_id;
            $weeklyMinutes = Shift::query()
                ->where('user_id', $user->id)
                ->where('status', '!=', 'cancelled')
                ->whereBetween('starts_at', [
                    ($shift->starts_at ?? now())->copy()->startOfWeek(),
                    ($shift->starts_at ?? now())->copy()->endOfWeek(),
                ])
                ->get()
                ->sum(function (Shift $existingShift) {
                    if (! $existingShift->starts_at || ! $existingShift->ends_at) {
                        return 0;
                    }

                    return max(0, $existingShift->ends_at->diffInMinutes($existingShift->starts_at));
                });

            $eligibility = $this->eligibility->evaluate($shift, $user)->toArray();
            $roleCoverageBonus = collect($eligibility['matched_roles'] ?? [])
                ->sum(fn (array $role) => (int) ($role['minimum'] ?? 1) * ($roleGapPriority > 0 ? 12 : 6));
            $siteFamiliarity = $siteId
                ? Shift::query()
                    ->where('user_id', $user->id)
                    ->where('site_id', $siteId)
                    ->where('starts_at', '>=', ($shift->starts_at ?? now())->copy()->subDays(60))
                    ->count()
                : 0;
            $clientConsistency = Shift::query()
                ->where('user_id', $user->id)
                ->where('client_id', $shift->client_id)
                ->where('starts_at', '>=', ($shift->starts_at ?? now())->copy()->subDays(60))
                ->count();
            $coverageFitBonus = $coveragePriority > 0
                ? ($siteFamiliarity * 3) + ($clientConsistency * 4)
                : 0;
            $recommendedScore = ($siteFamiliarity * 2)
                + ($clientConsistency * 3)
                + $coverageFitBonus
                + $roleCoverageBonus
                + $coveragePriority
                + $roleGapPriority
                - (int) round($weeklyMinutes / 60)
                - (! empty($eligibility['has_tight_turnaround']) ? 8 : 0);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'weekly_hours' => round($weeklyMinutes / 60, 2),
                'site_familiarity' => $siteFamiliarity,
                'client_consistency' => $clientConsistency,
                'coverage_priority' => $coveragePriority,
                'role_gap_priority' => $roleGapPriority,
                'coverage_fit_bonus' => $coverageFitBonus,
                'role_coverage_bonus' => $roleCoverageBonus,
                'resolves_missing_staff' => $coveragePriority > 0 && empty($shift->user_id),
                'resolves_role_gap' => $roleGapPriority > 0 && count($eligibility['matched_roles'] ?? []) > 0,
                'recommended_score' => $recommendedScore,
                'is_eligible' => $eligibility['is_eligible'],
                'blocked_reasons' => $eligibility['blocked_reasons'],
                'warning_reasons' => $eligibility['warning_reasons'],
                'required_roles' => $eligibility['required_roles'] ?? [],
                'matched_roles' => $eligibility['matched_roles'] ?? [],
                'missing_roles' => $eligibility['missing_roles'] ?? [],
                'has_time_off' => $eligibility['has_time_off'],
                'has_staff_conflict' => $eligibility['has_staff_conflict'],
                'has_compliance_block' => $eligibility['has_compliance_block'],
                'has_tight_turnaround' => $eligibility['has_tight_turnaround'],
            ];
        });

        return $results
            ->sortBy([
                ['is_eligible', 'desc'],
                ['recommended_score', 'desc'],
                ['weekly_hours', 'asc'],
                ['name', 'asc'],
            ])
            ->take($limit)
            ->values()
            ->all();
    }
}
