<?php

namespace App\Listeners;

use App\Events\CoverageSupplyAdded;
use App\Models\CoverageGapAcknowledgement;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftSignalService;
use Illuminate\Support\Carbon;

class ResolveCoverageAlertForAddedSupply
{
    public function __construct(
        protected ShiftCoverageService $coverage,
        protected SignalProcessingService $signals,
    ) {
    }

    public function handle(CoverageSupplyAdded $event): void
    {
        $window = $this->coverage->findCoverageWindow(
            $event->siteId,
            Carbon::parse($event->windowStartsAt),
            Carbon::parse($event->windowEndsAt),
            $event->coverageRequirementId,
        );

        if (! $window || ! $this->coverageWindowIsResolved($window)) {
            return;
        }

        $this->signals->resolveShiftCoverageAlert(
            $event->coverageWindowKey,
            'Coverage-gap alert resolved because the current window no longer shows an actionable deficit.',
            ShiftSignalService::RESOLUTION_SOURCE_COVERAGE,
            [
                'coverage_window_key' => $event->coverageWindowKey,
                'actor_user_id' => $event->actorId,
                'shift_id' => $event->shiftId,
                'series_id' => $event->seriesId,
                'action' => $event->action,
                'coverage_status' => [
                    'site_id' => $window['site_id'] ?? null,
                    'rule_id' => $window['rule_id'] ?? null,
                    'starts_at' => $window['starts_at'] ?? null,
                    'ends_at' => $window['ends_at'] ?? null,
                    'assigned_staff' => $window['assigned_staff'] ?? null,
                    'required_staff' => $window['required_staff'] ?? null,
                    'role_shortages' => $window['role_shortages'] ?? [],
                    'partial_window_uncovered_slices' => $window['partial_window_uncovered_slices'] ?? [],
                ],
            ],
        );

        CoverageGapAcknowledgement::query()
            ->where('coverage_window_key', $event->coverageWindowKey)
            ->whereNull('cleared_at')
            ->update(['cleared_at' => now()]);
    }

    protected function coverageWindowIsResolved(array $window): bool
    {
        if (($window['has_actionable_gap'] ?? false) || ($window['has_actionable_imbalance'] ?? false)) {
            return false;
        }

        if (($window['missing_staff'] ?? 0) > 0) {
            return false;
        }

        foreach (($window['role_shortages'] ?? []) as $shortage) {
            if (($shortage['missing'] ?? 0) > 0) {
                return false;
            }
        }

        if (! empty($window['partial_window_uncovered_slices'] ?? [])) {
            return false;
        }

        return ! in_array('partial_window_undercoverage', $window['contradictions'] ?? [], true);
    }
}
