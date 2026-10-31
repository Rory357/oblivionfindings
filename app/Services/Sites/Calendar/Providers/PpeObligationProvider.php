<?php

namespace App\Services\Sites\Calendar\Providers;

use Illuminate\Support\Carbon;

/**
 * PPE & Equipment obligations on the Site Calendar: inspection-due and expiry dates from the PPE register
 * (/health-safety/ppe stays the single write surface). The dates come through the viewer's PPE source read
 * (SiteCalendarAggregator::ppeForViewer → PpeSiteReadService::obligationsForViewer).
 */
class PpeObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'ppe';
    }

    /**
     * The legacy viewer-less form has no one to check PPE access for, so it returns nothing rather than every
     * Site's PPE dates.
     */
    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        return [];
    }
}
