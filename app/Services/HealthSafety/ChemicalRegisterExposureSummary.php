<?php

namespace App\Services\HealthSafety;

use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use App\Models\User;

/**
 * The chemical register's exposure numbers (Stephan, 26 Oct: "Anyone who sees the list", as on main before the H&S
 * merge): exposures in the period, those still awaiting a medical outcome, and exposure H&S events awaiting a
 * WorkSafe notification. Counts only, at the Sites the register shows the viewer; no worker, chemical or clinical
 * detail. The link to the private exposure list is offered only to people who can read exposures.
 */
final class ChemicalRegisterExposureSummary
{
    public function __construct(private readonly ChemicalExposureAccessService $exposures) {}

    /**
     * @param  list<int>  $visibleSiteIds  the Sites the register admitted for this viewer
     * @return array{exposures: int, awaiting_outcome: int, worksafe_awaiting: int, period_days: int, exposures_href: ?string}
     */
    public function forViewer(User $viewer, array $visibleSiteIds, ?int $siteId, int $periodDays): array
    {
        $sites = $siteId !== null ? array_values(array_intersect($visibleSiteIds, [$siteId])) : $visibleSiteIds;
        $since = now()->subDays($periodDays);
        $exposures = fn () => SubstanceExposureRecord::query()->whereIn('site_id', $sites)->where('exposed_at', '>=', $since);
        $worksafe = HsEvent::query()->where('event_category', HsEvent::CATEGORY_EXPOSURE)->whereIn('site_id', $sites)
            ->where('worksafe_notifiable', true)
            ->where(fn ($pending) => $pending->whereNull('worksafe_status')->orWhere('worksafe_status', HsEvent::WORKSAFE_PENDING));

        return [
            'exposures' => $sites === [] ? 0 : $exposures()->count(),
            'awaiting_outcome' => $sites === [] ? 0 : $exposures()->where('medical_attention_sought', true)->whereNull('medical_outcome')->count(),
            'worksafe_awaiting' => $sites === [] ? 0 : $worksafe->count(),
            'period_days' => $periodDays,
            'exposures_href' => $this->exposures->canRead($viewer) ? '/health-safety/chemical-exposures' : null,
        ];
    }
}
