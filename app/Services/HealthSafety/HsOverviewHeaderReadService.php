<?php

namespace App\Services\HealthSafety;

use App\Models\ClientIncident;
use App\Models\HsClosureException;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\SiteHazard;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\HsLabels;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonInterface as Carbon;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * G2 (B2.3, K2; Stephan's approved header, 31 Oct): the Overview's four header boxes for this viewer.
 *
 * Every box counts only records the viewer can open now, with the same scopes as the Overview's lists (H&S event scope,
 * incident source scope, Site ids). A box's trend and weekly bars are rebuilt from the records' own dates: the count at
 * a past moment is shown only when those dates prove it. Two checks make that honest:
 *  - a record whose change can't be dated (an action returned for rework, a reopened incident, an accepted review with
 *    no acceptance time) makes that moment unprovable, so it gets no number;
 *  - the count rebuilt for now must equal the live count, otherwise the whole history is withheld ("No trend yet").
 */
final class HsOverviewHeaderReadService
{
    public const SET_QUEUE = 'queue';          // admin, H&S officer, team lead

    public const SET_COMPLIANCE = 'compliance'; // compliance lead

    public const SET_INCIDENTS = 'incidents';   // provider manager (Stephan's image)

    public const SET_HAZARDS = 'hazards';       // maintenance coordinator: no incident read

    /** Weekly bars: the count at the end of each of the last 8 weeks, the last bar being now. */
    public const WEEKS = 8;

    public const TREND_DAYS = 30;

    /**
     * Which four boxes this person gets. The approved board picks them per role (provider managers get Stephan's own
     * four), so the role decides the layout only; every count is still limited by the person's own permissions and Sites.
     */
    public static function setFor(User $viewer): string
    {
        if ($viewer->hasRole('provider_manager')) {
            return self::SET_INCIDENTS;
        }
        if ($viewer->canDo('hazards.manage')) {
            return self::SET_QUEUE;
        }
        if ($viewer->canDo('healthSafety.closureExceptions.approve')) {
            return self::SET_COMPLIANCE;
        }

        return $viewer->canDo('incidents.viewAny') || $viewer->canDo('incidents.viewAssigned') ? self::SET_INCIDENTS : self::SET_HAZARDS;
    }

    /**
     * @param  array<int, int>|null  $siteIds  the Overview's resolved Site ids (null = every Site the viewer may see)
     * @return array{set: string, since: string, boxes: list<array<string, mixed>>}
     */
    public function boxes(User $viewer, ?array $siteIds, Carbon $now, HealthSafetyReadContext $context, int $checksDue = 0, int $drillsDue = 0): array
    {
        $set = self::setFor($viewer);
        $since = $now->copy()->subDays(self::TREND_DAYS);
        $moments = [$since];
        for ($k = self::WEEKS - 1; $k >= 0; $k--) {
            $moments[] = $now->copy()->subDays(7 * $k);
        }

        return HealthSafetyReadMaterialisationProof::within($context, function (HealthSafetyReadMaterialisationProof $proof) use ($viewer, $siteIds, $now, $context, $set, $moments, $since, $checksDue, $drillsDue): array {
            $events = fn (): Builder => $this->events($viewer, $siteIds, $context, $proof);
            $actions = fn (): Builder => $proof->protect(HsCorrectiveAction::query()
                ->whereIn('hs_corrective_actions.hs_event_id', $events()->select('hs_events.id')));
            // Each box opens its list, keeping a single chosen Site (the registers read site_id).
            $link = fn (string $path, array $query = []): string => $path.(($query = [...$query, ...($siteIds !== null && count($siteIds) === 1 ? ['site_id' => $siteIds[0]] : [])]) === [] ? '' : '?'.http_build_query($query));

            $box = fn (string $key) => match ($key) {
                'need_owner' => $this->box('need_owner', 'Need an owner', 'alert', $link('/health-safety/events', ['tab' => 'needs_owner']),
                    $events()->where('hs_events.handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE)->where('hs_events.status', '!=', HsEvent::STATUS_CLOSED)->count(),
                    $this->history($events(), $moments,
                        "hs_events.created_at <= {t} AND hs_events.handover_status IN ('".HsEvent::HANDOVER_AWAITING_ACCEPTANCE."', '".HsEvent::HANDOVER_ACCEPTED."')"
                        ." AND (hs_events.accepted_at IS NULL OR hs_events.accepted_at > {t}) AND (hs_events.closed_at IS NULL OR hs_events.closed_at > {t})",
                        "hs_events.created_at <= {t} AND ((hs_events.handover_status = '".HsEvent::HANDOVER_ACCEPTED."' AND hs_events.accepted_at IS NULL)"
                        ." OR (hs_events.status = '".HsEvent::STATUS_CLOSED."' AND hs_events.closed_at IS NULL))"), $since),
                // The WorkSafe flags and severity carry no dates, so this box never shows a trend.
                'worksafe' => $this->box('worksafe', 'WorkSafe: decide or tell', 'alert', $link('/health-safety/events', ['tab' => 'decide_or_tell']),
                    $events()->worksafeDecideOrTell()->count(), null, $since),
                'actions_overdue' => $this->box('actions_overdue', 'Actions overdue', 'alert', $link('/health-safety/corrective-actions', ['tab' => 'overdue']),
                    $actions()->overdue()->count(),
                    $this->history($actions(), $moments,
                        'hs_corrective_actions.created_at <= {t} AND hs_corrective_actions.due_date < DATE(CONVERT_TZ({t}, \'+00:00\', \''.$this->workerOffset($now).'\'))'
                        .' AND (hs_corrective_actions.verified_at IS NULL OR hs_corrective_actions.verified_at > {t})'
                        .' AND (hs_corrective_actions.closed_at IS NULL OR hs_corrective_actions.closed_at > {t})',
                        "hs_corrective_actions.created_at <= {t} AND hs_corrective_actions.status IN ('verified', 'closed')"
                        .' AND hs_corrective_actions.verified_at IS NULL AND hs_corrective_actions.closed_at IS NULL'), $since),
                'actions_to_check' => $this->box('actions_to_check', 'Actions to check', null, $link('/health-safety/corrective-actions', ['tab' => 'awaiting_verification']),
                    $this->checkableBy($actions(), $viewer)->where('hs_corrective_actions.status', HsCorrectiveAction::STATUS_COMPLETED)->count(),
                    $this->history($this->checkableBy($actions(), $viewer), $moments,
                        'hs_corrective_actions.completed_at <= {t} AND (hs_corrective_actions.verified_at IS NULL OR hs_corrective_actions.verified_at > {t})'
                        .' AND (hs_corrective_actions.closed_at IS NULL OR hs_corrective_actions.closed_at > {t})',
                        // Returned for rework: when it went back isn't recorded.
                        "hs_corrective_actions.completed_at <= {t} AND hs_corrective_actions.status IN ('open', 'in_progress')"), $since),
                'open_reviews' => $this->box('open_reviews', 'Open safety reviews', null, $link('/health-safety/events', ['tab' => 'not_closed']),
                    $events()->where('hs_events.status', '!=', HsEvent::STATUS_CLOSED)->count(),
                    $this->history($events(), $moments,
                        'hs_events.created_at <= {t} AND (hs_events.closed_at IS NULL OR hs_events.closed_at > {t})',
                        "hs_events.created_at <= {t} AND hs_events.status = '".HsEvent::STATUS_CLOSED."' AND hs_events.closed_at IS NULL"), $since),
                'close_anyway' => $this->box('close_anyway', 'Close-anyway requests', 'alert', $link('/health-safety/events', ['tab' => 'close_anyway']),
                    $proof->protect(HsClosureException::query()->whereIn('hs_closure_exceptions.hs_event_id', $events()->select('hs_events.id'))
                        ->where('hs_closure_exceptions.status', HsClosureException::STATUS_PENDING))->count(), null, $since),
                'open_incidents' => $this->box('open_incidents', 'Open incidents', null, $link('/incidents', ['tab' => 'open']),
                    $this->incidents($siteIds, $context, $proof)->whereNotIn('client_incidents.status', ['draft', 'closed'])->count(),
                    $this->history($this->incidents($siteIds, $context, $proof), $moments,
                        'client_incidents.submitted_at <= {t} AND (client_incidents.closed_at IS NULL OR client_incidents.closed_at > {t})',
                        "(client_incidents.reopened_at IS NOT NULL AND client_incidents.reopened_at > {t} AND client_incidents.created_at <= {t})"
                        ." OR (client_incidents.status <> 'draft' AND client_incidents.submitted_at IS NULL AND client_incidents.created_at <= {t})"
                        ." OR (client_incidents.status = 'closed' AND client_incidents.closed_at IS NULL AND client_incidents.created_at <= {t})"), $since),
                // Whether someone could own an incident on a past date isn't recorded, so no trend.
                'incidents_need_owner' => $this->box('incidents_need_owner', 'Incidents need an owner', 'alert', $link('/incidents', ['owner' => 'needs_owner']),
                    $this->incidentsNeedingOwner($siteIds, $context), null, $since),
                'open_hazards' => $this->box('open_hazards', 'Open hazards', null, $link('/compliance/hazards', ['tab' => 'active']),
                    $this->hazards($siteIds)->whereIn('site_hazards.status', ['open', 'in_progress'])->count(),
                    $this->history($this->hazards($siteIds), $moments,
                        "site_hazards.created_at <= {t} AND site_hazards.status IN ('open', 'in_progress')",
                        // A status change after the moment (other than the one at creation) means the status then isn't known.
                        'site_hazards.created_at <= {t} AND site_hazards.status_changed_at > {t}'
                        .' AND site_hazards.status_changed_at > DATE_ADD(site_hazards.created_at, INTERVAL 1 MINUTE)'), $since),
                // Due dates on checks and drills move when they're done, so these never show a trend.
                'checks_due' => $this->box('checks_due', 'Checks due', 'alert', $link('/health-safety/registers'), $checksDue, null, $since),
                'drills_due' => $this->box('drills_due', 'Drills due', 'alert', $link('/health-safety/drills'), $drillsDue, null, $since),
            };

            $keys = match ($set) {
                self::SET_QUEUE => ['need_owner', 'worksafe', 'actions_overdue', 'actions_to_check'],
                self::SET_COMPLIANCE => ['close_anyway', 'worksafe', 'actions_overdue', 'open_reviews'],
                self::SET_INCIDENTS => ['incidents_need_owner', 'open_incidents', 'actions_overdue', 'open_hazards'],
                default => ['open_hazards', 'checks_due', 'actions_overdue', 'drills_due'],
            };

            return ['set' => $set, 'since' => $since->toDateString(), 'boxes' => array_map($box, $keys)];
        });
    }

    /**
     * G2: one of the header's four charts, with the same scopes as the boxes. People who can't open incidents (the
     * hazards set) get the hazard version of each chart. Every count is a count of records this person can open.
     *
     * @param  'severity'|'over_time'|'due'|'types'  $chart
     */
    public function chart(string $chart, User $viewer, ?array $siteIds, Carbon $now, HealthSafetyReadContext $context): array
    {
        $kind = self::setFor($viewer) === self::SET_HAZARDS ? 'hazards' : 'incidents';

        return HealthSafetyReadMaterialisationProof::within($context, function (HealthSafetyReadMaterialisationProof $proof) use ($chart, $kind, $viewer, $siteIds, $now, $context): array {
            $source = fn (): Builder => $kind === 'hazards' ? $this->hazards($siteIds) : $this->incidents($siteIds, $context, $proof);
            $openNow = fn (): Builder => $kind === 'hazards'
                ? $source()->whereIn('site_hazards.status', ['open', 'in_progress'])
                : $source()->whereNotIn('client_incidents.status', ['draft', 'closed']);
            $table = $kind === 'hazards' ? 'site_hazards' : 'client_incidents';

            return match ($chart) {
                'severity' => ['kind' => $kind, 'items' => $this->tally(
                    $openNow(), $kind === 'hazards' ? 'site_hazards.risk_rating' : 'client_incidents.severity', ['critical', 'high', 'medium', 'low'])],
                'types' => ['kind' => $kind, ...$this->topTypes($openNow(), $kind === 'hazards' ? 'site_hazards.hazard_type' : 'client_incidents.type')],
                'over_time' => $this->overTime($source(), $kind === 'hazards' ? "{$table}.created_at" : 'COALESCE(client_incidents.submitted_at, client_incidents.created_at)', $now, $kind,
                    $kind === 'hazards' ? null : "client_incidents.status <> 'draft'"),
                'due' => $kind === 'hazards'
                    ? $this->dueDays($this->hazards($siteIds)->whereIn('site_hazards.status', ['open', 'in_progress']), 'site_hazards.due_date', $now, 'hazards')
                    : $this->dueDays($proof->protect(HsCorrectiveAction::query()
                        ->whereIn('hs_corrective_actions.hs_event_id', $this->events($viewer, $siteIds, $context, $proof)->select('hs_events.id'))
                        ->whereNotIn('hs_corrective_actions.status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED])),
                        'hs_corrective_actions.due_date', $now, 'actions'),
            };
        });
    }

    /**
     * B5.2/K9: attention counts for the Incidents and safety reviews rail (view key => count), every Site this person may
     * see. Incidents need incident read; without it that tab gets no count rather than a 0.
     *
     * @return array<string, int|null>
     */
    public function railCounts(User $viewer, HealthSafetyReadContext $context): array
    {
        return HealthSafetyReadMaterialisationProof::within($context, function (HealthSafetyReadMaterialisationProof $proof) use ($viewer, $context): array {
            $events = fn (): Builder => $this->events($viewer, null, $context, $proof);
            $readsIncidents = $viewer->canDo('incidents.viewAny') || $viewer->canDo('incidents.viewAssigned');

            return [
                'incidents' => $readsIncidents ? $this->incidentsNeedingOwner(null, $context) : null,
                'reviews' => $events()->where('hs_events.handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
                    ->where('hs_events.status', '!=', HsEvent::STATUS_CLOSED)->count(),
                'actions' => $proof->protect(HsCorrectiveAction::query()
                    ->whereIn('hs_corrective_actions.hs_event_id', $events()->select('hs_events.id')))->overdue()->count(),
                'notifications' => $events()->worksafeDecideOrTell()->count(),
            ];
        });
    }

    /** Counts for fixed keys, in order (missing keys count 0; other values are left out). */
    private function tally(Builder $query, string $column, array $keys): array
    {
        $counts = (clone $query)->withoutEagerLoads()->reorder()->toBase()
            ->selectRaw("{$column} AS k, COUNT(*) AS n")->groupBy('k')->pluck('n', 'k')->all();

        return array_map(fn (string $key): array => ['key' => $key, 'label' => ucfirst($key), 'count' => (int) ($counts[$key] ?? 0)], $keys);
    }

    /** The four most common types, then everything else as "Other". */
    private function topTypes(Builder $query, string $column): array
    {
        $rows = (clone $query)->withoutEagerLoads()->reorder()->toBase()
            ->selectRaw("{$column} AS k, COUNT(*) AS n")->groupBy('k')->orderByDesc('n')->orderBy('k')->get();
        $top = $rows->take(4)->map(fn ($row): array => ['key' => (string) $row->k, 'label' => HsLabels::humanise((string) $row->k), 'count' => (int) $row->n])->values()->all();

        return ['items' => $top, 'other' => (int) $rows->slice(4)->sum('n')];
    }

    /** Reported each day for the last 30 days (worker calendar), the total, and the change on the 30 days before. */
    private function overTime(Builder $query, string $dateSql, Carbon $now, string $kind, ?string $where): array
    {
        $zone = $this->workerOffset($now);
        $start = $now->copy()->subDays(60);
        $q = (clone $query)->withoutEagerLoads()->reorder()->toBase();
        if ($where !== null) {
            $q->whereRaw($where);
        }
        $counts = $q->whereRaw("{$dateSql} >= ?", [$start->copy()->utc()->format('Y-m-d H:i:s')])
            ->selectRaw("DATE(CONVERT_TZ({$dateSql}, '+00:00', '{$zone}')) AS d, COUNT(*) AS n")->groupBy('d')->pluck('n', 'd')->all();
        $today = $now->copy()->setTimezone((string) (config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC'))->startOfDay();
        $days = [];
        $previous = 0;
        for ($i = 59; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();
            $n = (int) ($counts[$date] ?? 0);
            if ($i >= 30) {
                $previous += $n;
            } else {
                $days[] = ['date' => $date, 'count' => $n];
            }
        }
        $total = array_sum(array_column($days, 'count'));

        return ['kind' => $kind, 'days' => $days, 'total' => $total, 'previous_total' => $previous,
            'change_pct' => $previous > 0 ? (int) round(($total - $previous) / $previous * 100) : null];
    }

    /** Due each day for the next 30 days (worker calendar), from today. */
    private function dueDays(Builder $query, string $column, Carbon $now, string $kind): array
    {
        $today = $now->copy()->setTimezone((string) (config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC'))->startOfDay();
        $counts = (clone $query)->withoutEagerLoads()->reorder()->toBase()
            ->whereBetween($column, [$today->toDateString(), $today->copy()->addDays(29)->toDateString()])
            ->selectRaw("DATE({$column}) AS d, COUNT(*) AS n")->groupBy('d')->pluck('n', 'd')->all();
        $days = [];
        for ($i = 0; $i < 30; $i++) {
            $date = $today->copy()->addDays($i)->toDateString();
            $days[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }

        return ['kind' => $kind, 'days' => $days, 'total' => array_sum(array_column($days, 'count'))];
    }

    /** The same H&S event scope as the Overview's lists (HsDashboardService::hsEventQuery). */
    private function events(User $viewer, ?array $siteIds, HealthSafetyReadContext $context, HealthSafetyReadMaterialisationProof $proof): Builder
    {
        $query = HsEvent::query();
        app(UserSiteAccessService::class)->applyHsEventScope($query, $viewer, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
        $this->applySiteIds($query, $siteIds);

        return $proof->protect(app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $viewer, $context));
    }

    private function incidents(?array $siteIds, HealthSafetyReadContext $context, HealthSafetyReadMaterialisationProof $proof): Builder
    {
        return $proof->protect($context->applyIncidentSourceScope($this->applySiteIds(ClientIncident::query(), $siteIds)));
    }

    private function hazards(?array $siteIds): Builder
    {
        return $this->applySiteIds(SiteHazard::query(), $siteIds);
    }

    /** The incident register's own "Needs an owner" filter, in the same current reads. */
    private function incidentsNeedingOwner(?array $siteIds, HealthSafetyReadContext $context): int
    {
        return $context->consumeSourceViewer([], function (User $current, CurrentAuthorizationReads $reads) use ($siteIds): int {
            try {
                return app(IncidentOwnershipService::class)
                    ->applyOwnerFilter($this->applySiteIds(ClientIncident::query(), $siteIds), $current, 'needs_owner', $reads)->count();
            } catch (HttpExceptionInterface $denial) {
                if ($denial->getStatusCode() !== 403) {
                    throw $denial;
                }

                return 0;
            }
        });
    }

    /**
     * G2 (M3): the incident register's own "Needs an owner" rows, oldest first, in the same current reads. Titles come
     * from incidents this person can open, so nothing hidden is shown.
     *
     * @return array{count: int, rows: list<array<string, mixed>>}|null  null without incident read
     */
    public function incidentsNeedingOwnerQueue(User $viewer, ?array $siteIds, HealthSafetyReadContext $context, int $limit = 5): ?array
    {
        if (! $viewer->canDo('incidents.viewAny') && ! $viewer->canDo('incidents.viewAssigned')) {
            return null;
        }

        return $context->consumeSourceViewer([], function (User $current, CurrentAuthorizationReads $reads) use ($siteIds, $limit): array {
            try {
                $query = app(IncidentOwnershipService::class)
                    ->applyOwnerFilter($this->applySiteIds(ClientIncident::query(), $siteIds), $current, 'needs_owner', $reads)
                    ->with('site:id,name');
                $count = (clone $query)->count();
                $rows = $query->orderBy('client_incidents.occurred_at')->limit($limit)->get()->map(fn (ClientIncident $incident): array => [
                    'id' => $incident->id,
                    'reference' => $incident->reference_number,
                    'title' => $incident->title ?: HsLabels::humanise((string) $incident->type),
                    'type' => $incident->type,
                    'severity' => $incident->severity,
                    'site' => $incident->site?->name,
                    'occurred_at' => $incident->occurred_at?->toIso8601String(),
                ])->values()->all();

                return ['count' => $count, 'rows' => $rows];
            } catch (HttpExceptionInterface $denial) {
                if ($denial->getStatusCode() !== 403) {
                    throw $denial;
                }

                return ['count' => 0, 'rows' => []];
            }
        });
    }

    /** Pending close-anyway requests on reviews this person can open. */
    public function closeAnywayCount(User $viewer, ?array $siteIds, HealthSafetyReadContext $context): int
    {
        return HealthSafetyReadMaterialisationProof::within($context, fn (HealthSafetyReadMaterialisationProof $proof): int => $proof->protect(
            HsClosureException::query()->whereIn('hs_closure_exceptions.hs_event_id', $this->events($viewer, $siteIds, $context, $proof)->select('hs_events.id'))
                ->where('hs_closure_exceptions.status', HsClosureException::STATUS_PENDING))->count());
    }

    /** B2.3: "Actions to check" counts only actions this viewer may check: not one they added, own or did. */
    private function checkableBy(Builder $actions, User $viewer): Builder
    {
        foreach (['created_by', 'assigned_to_user_id', 'completed_by_user_id'] as $column) {
            $actions->where(fn (Builder $q) => $q->whereNull('hs_corrective_actions.'.$column)->orWhere('hs_corrective_actions.'.$column, '!=', $viewer->id));
        }

        return $actions;
    }

    /**
     * One query: for each moment, how many records the dates say were counted then, and how many make it unprovable.
     *
     * @param  list<Carbon>  $moments  [trend start, week 1 … week 8 (now)]
     * @return list<int|null> one value per moment; null where it can't be proven
     */
    private function history(Builder $scoped, array $moments, string $counted, string $unprovable): array
    {
        $selects = [];
        $bindings = [];
        foreach ($moments as $i => $moment) {
            $at = $moment->copy()->utc()->format('Y-m-d H:i:s');
            foreach (['c' => $counted, 'u' => $unprovable] as $prefix => $sql) {
                $selects[] = 'COALESCE(SUM(CASE WHEN ('.str_replace('{t}', '?', $sql).') THEN 1 ELSE 0 END), 0) AS '.$prefix.$i;
                array_push($bindings, ...array_fill(0, substr_count($sql, '{t}'), $at));
            }
        }
        $row = (clone $scoped)->withoutEagerLoads()->reorder()->toBase()->selectRaw(implode(', ', $selects), $bindings)->first();

        return array_map(fn (int $i): ?int => (int) $row->{'u'.$i} > 0 ? null : (int) $row->{'c'.$i}, array_keys($moments));
    }

    /** @param  list<int|null>|null  $history  [trend start, 8 weekly values ending now] */
    private function box(string $key, string $label, ?string $tone, string $href, int $value, ?array $history, Carbon $since): array
    {
        // The count rebuilt for now must match the live count, or the dates don't tell the whole story.
        $trusted = $history !== null && end($history) === $value;
        $weeks = $trusted ? array_slice($history, 1) : array_fill(0, self::WEEKS, null);
        $start = $trusted ? $history[0] : null;

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'tone' => $value > 0 ? $tone : null,
            'href' => $href,
            'trend' => $start === null ? null : ['delta' => $value - $start, 'since' => $since->toDateString()],
            'weeks' => $weeks,
        ];
    }

    private function applySiteIds(Builder $query, ?array $siteIds, string $column = 'site_id'): Builder
    {
        if ($siteIds === null) {
            return $query;
        }

        return $siteIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn($query->qualifyColumn($column), $siteIds);
    }

    /** The worker timezone's offset, so "overdue" on a past day matches HsCorrectiveAction::scopeOverdue's local date. */
    private function workerOffset(Carbon $now): string
    {
        $zone = (string) (config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC');

        return $now->copy()->setTimezone($zone)->format('P');
    }
}
