<?php

namespace App\Services\Overview;

use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\Operations\OperationsDashboardScopeService;
use App\Services\ShiftCoverageService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Read-only projection of the existing staffing calculation; never a publication or safety decision. */
final class OverviewCoverage
{
    public function read(User $user, array $sites, array $siteIds): array
    {
        $now = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'));
        // The operational day runs 07:00–07:00. Before 07:00 the night shift on
        // the floor belongs to yesterday's day, so it stays in view. setTime keeps
        // 07:00 on daylight-saving days, where adding 7 hours would not.
        $today = ($now->hour < 7 ? $now->subDay() : $now)->startOfDay();
        $start = $today->setTime(7, 0);
        $end = $start->addDay();
        $shifts = app(OperationsDashboardScopeService::class)->shifts($user)->whereIn('site_id', $siteIds)
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc());
        $visibleIds = (clone $shifts)->pluck('id')->flip();
        $rules = SiteCoverageRequirement::query()->whereIn('site_id', $siteIds)->active()
            ->where(fn (Builder $query) => $query->whereNull('preferred_client_id')->orWhereHas('preferredClient', fn (Builder $client) => $client->whereColumn('clients.site_id', 'site_coverage_requirements.site_id')))
            ->pluck('id')->flip();
        $engine = app(ShiftCoverageService::class);
        $rows = [];
        $required = $assigned = $gaps = $unknown = $windows = 0;
        foreach (collect($sites)->whereIn('id', $siteIds) as $site) {
            $cells = [[], [], []];
            // The engine is queried per approved Site. Only its staffing fields leave this projection.
            foreach ($engine->buildRangeCoverage($start, $end, $site['id']) as $window) {
                $safe = isset($rules[$window['rule_id']]) && collect($window['contributing_shifts'] ?? [])->every(fn ($shift) => isset($visibleIds[(int) $shift['id']]));
                $date = CarbonImmutable::parse($window['starts_at'])->setTimezone($today->timezone);
                $slot = $date->hour >= 7 && $date->hour < 15 ? 0 : ($date->hour >= 15 && $date->hour < 23 ? 1 : 2);
                $need = (int) $window['required_staff'];
                $cover = $safe ? (int) $window['assigned_staff'] : null;
                $gap = $safe && (($window['missing_staff'] ?? 0) > 0 || ($window['has_role_gap'] ?? false));
                $cells[$slot][] = ['id' => $window['rule_id'].':'.$window['starts_at'], 'starts' => $window['starts_at'], 'ends' => $window['ends_at'], 'assigned' => $cover, 'required' => $need, 'gap' => $gap, 'roleGap' => $safe && ($window['has_role_gap'] ?? false), 'href' => $this->href($user, $site['id'], $today)];
                $windows++;
                if (! $safe) {
                    $unknown++;

                    continue;
                }
                $required += $need;
                $assigned += min($cover, $need);
                $gaps += $gap ? 1 : 0;
            }
            $rows[] = ['id' => $site['id'], 'name' => $site['name'], 'cells' => $cells];
        }

        return ['date' => $today->toDateString(), 'rows' => $rows, 'required' => $unknown ? null : $required, 'assigned' => $unknown ? null : $assigned, 'gaps' => $gaps, 'unassessed' => $unknown, 'windows' => $windows];
    }

    public function href(User $user, int $siteId, CarbonImmutable $date): string
    {
        if ($user->canDo('rostering.viewAny')) {
            return route('operations.rostering.index', ['tab' => 'shifts', 'site_id' => [$siteId], 'week' => $date->startOfWeek()->toDateString()], false);
        }

        return $user->canDo('shifts.viewAny') || $user->canDo('shifts.manageAny') ? '/operations/shifts?site_id='.$siteId : '/operations';
    }
}
