<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\EmergencyDrill;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\Sites\Calendar\CalendarItem;
use App\Support\HealthSafety\EmergencyDrillReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Source obligations are read through the drill's current staff and Site boundary. */
class DrillObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'drill';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        return $this->obligationsForViewer($siteIds, $start, $end, request()->user());
    }

    public function obligationsForViewer(array $siteIds, Carbon $start, Carbon $end, mixed $viewer): array
    {
        if ($siteIds === [] || ! $viewer instanceof User) {
            return [];
        }
        $access = app(EmergencyDrillAccessService::class);
        try {
            return $access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $allowed) use ($access, $siteIds, $start, $end) {
                if (! $current->canDo('hazards.view')) {
                    return [];
                }
                $drills = $reads->query($access->scopeForSites(EmergencyDrill::query(), array_values(array_intersect($siteIds, $allowed))))
                    ->where('status', 'scheduled')->with('site:id,name,type')->orderBy('scheduled_at')->orderBy('id')->get();
                $items = [];
                foreach ($drills as $drill) {
                    // Drills are scheduled instants, not date-only obligations. Preserve
                    // the caller's exact offset-aware range across NZ midnight/DST.
                    $due = $drill->scheduled_at;
                    if (! $due || $due->lt($start) || $due->gt($end)) {
                        continue;
                    }
                    $label = $drill->title ?: Str::headline($drill->drill_type ?: 'Emergency drill');
                    $items[] = new CalendarItem(id: 'drill-'.$drill->id, source: 'drill', group: 'auto', title: $label.' — drill',
                        start: $this->isoDate($due), allDay: false, status: $due->isPast() ? 'overdue' : 'scheduled',
                        ref: 'DR-'.$drill->id, site: $this->siteArray($drill->site),
                        link: EmergencyDrillReturn::href($drill->id, '/sites/'.$drill->site_id.'/calendar'));
                }

                return $items;
            });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return [];
        }
    }
}
