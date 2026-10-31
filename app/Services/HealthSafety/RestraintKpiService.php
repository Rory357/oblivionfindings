<?php

namespace App\Services\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\RestraintEvent;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Metrics retain the register's current confidential source authority. */
class RestraintKpiService
{
    private const COUNTS = ['events_in_period', 'out_of_plan', 'with_injury', 'critical', 'unreviewed',
        'active_plans', 'plans_review_due', 'clients_no_active_bsp'];

    private const EVENT_IDENTITY = ['id', 'client_id', 'site_id', 'stay_id', 'source_schema_version',
        'recorded_client_id', 'recorded_site_id', 'recorded_stay_id', 'behaviour_support_plan_id', 'behaviour_support_plan_version_id'];

    private const PLAN_IDENTITY = ['id', 'client_id', 'source_schema_version', 'source_site_id', 'source_stay_id'];

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** Standing oversight operands remain independent of the requested period. */
    public function summary(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to,
        ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->materialize($viewer, $context, function ($access, $current, $reads, $sites, $proof, $active) use ($siteId, $from, $to): array {
            [$events, $plans] = $this->sourceQueries($siteId, $access, $current, $reads, $sites, $proof, $active);
            $period = (clone $events)->whereBetween('started_at', [$from, $to]);
            $count = fn (Builder $query): int => $proof->protect($query)->count();
            $clients = $proof->protect((clone $events)->whereNotNull('client_id')->distinct())->pluck('client_id');
            $active = $proof->protect((clone $plans)->whereIn('client_id', $clients)->where('status', 'active')->distinct())->pluck('client_id');
            $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
            return [
                'events_in_period' => $count(clone $period),
                'out_of_plan' => $count((clone $period)->where('within_support_plan', false)),
                'with_injury' => $count((clone $period)->where('injury_occurred', true)),
                'critical' => $count((clone $period)->where('severity', 'critical')),
                'unreviewed' => $count((clone $events)->whereNull('reviewed_at')),
                'active_plans' => $count((clone $plans)->where('status', 'active')),
                'plans_review_due' => $count((clone $plans)->whereIn('status', ['active', 'under_review'])
                    ->whereNotNull('review_date')->where('review_date', '<=', now($timezone)->addDays(30))),
                'clients_no_active_bsp' => $clients->diff($active)->count(),
                'source_access' => 'readable',
                'scope' => 'current_readable_sources',
            ];
        }, fn ($state) => [...array_fill_keys(self::COUNTS, null), 'source_access' => $state, 'scope' => 'current_readable_sources']);
    }

    /** Null means withheld; an authorised empty queue remains []. */
    public function unreviewedWorklist(int|array|null $siteId, int $limit = 8,
        ?User $viewer = null, ?HealthSafetyReadContext $context = null): ?array
    {
        return $this->materialize($viewer, $context, function ($access, $current, $reads, $sites, $proof, $active) use ($siteId, $limit): array {
            [$events] = $this->sourceQueries($siteId, $access, $current, $reads, $sites, $proof, $active);
            $rows = $proof->protect((clone $events)->whereNull('reviewed_at')->orderByDesc('started_at')->orderByDesc('id')
                ->limit(max(0, $limit)))->get([...self::EVENT_IDENTITY, 'reference_number', 'restraint_type', 'severity',
                    'within_support_plan', 'injury_occurred', 'started_at']);
            $result = [];
            foreach ($rows as $event) {
                // Names are not eager-loaded ahead of exact admitted source identity.
                $parent = $this->exact(clone $events, $event->getRawOriginal(), 'restraint_events')->selectRaw('1');
                $client = $proof->protect($reads->query(Client::query()->whereKey($event->client_id)
                    ->whereExists($parent->toBase())))->firstOrFail(['id', 'site_id', 'first_name', 'last_name']);
                $result[] = [
                    'id' => (int) $event->id,
                    'reference' => $event->reference_number ?? 'RE-'.str_pad((string) $event->id, 3, '0', STR_PAD_LEFT),
                    'client' => trim($client->first_name.' '.$client->last_name),
                    'restraint_type' => $event->restraint_type,
                    'severity' => $event->severity,
                    'within_support_plan' => (bool) $event->within_support_plan,
                    'injury_occurred' => (bool) $event->injury_occurred,
                    'started_at' => $event->started_at?->toIso8601String(),
                ];
            }
            return $result;
        }, fn () => null);
    }

    public function breakdowns(int|array|null $siteId, CarbonInterface $from, CarbonInterface $to,
        ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->materialize($viewer, $context, function ($access, $current, $reads, $sites, $proof, $active) use ($siteId, $from, $to): array {
            [$events, $plans] = $this->sourceQueries($siteId, $access, $current, $reads, $sites, $proof, $active);
            $events->whereBetween('started_at', [$from, $to]);
            $group = fn (Builder $query, string $column): array => $proof->protect($query->select($column, DB::raw('COUNT(*) as count'))
                ->groupBy($column)->orderByDesc('count'))->get()->map(fn ($row) => ['label' => (string) $row->{$column}, 'count' => (int) $row->count])->all();
            return ['by_type' => $group(clone $events, 'restraint_type'), 'by_severity' => $group(clone $events, 'severity'),
                'by_plan_status' => $group($plans, 'status'), 'source_access' => 'readable', 'scope' => 'current_readable_sources'];
        }, fn ($state) => ['by_type' => null, 'by_severity' => null, 'by_plan_status' => null, 'source_access' => $state, 'scope' => 'current_readable_sources']);
    }

    private function materialize(?User $viewer, ?HealthSafetyReadContext $context, callable $operation, callable $withheld): mixed
    {
        if ($viewer === null) {
            if ($context !== null) { throw new \LogicException('A supplied metrics context requires its viewer.'); }
            return $withheld('restricted');
        }
        $consume = function (HealthSafetyReadContext $active) use ($viewer, $operation): mixed {
            $active->assertViewer($viewer);
            $access = app(RestraintAccessService::class);
            return HealthSafetyReadMaterialisationProof::within($active, fn ($proof) => $access->withReadContext($active,
                function ($current, $reads, $sites) use ($access, $operation, $proof, $active) {
                    $access->assertOperation($current, 'view');
                    return $operation($access, $current, $reads, $sites, $proof, $active);
                }));
        };
        try {
            return $context !== null ? $consume($context) : HealthSafetyReadContext::within($viewer, RestraintAccessService::PERMISSIONS, $consume);
        } catch (ModelNotFoundException) {
            return $withheld('unavailable');
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            return $withheld($error->getStatusCode() === 403 ? 'restricted' : 'unavailable');
        }
    }

    /** Source metadata is qualified before dates, metric facts or worklist names. */
    private function sourceQueries(int|array|null $requested, RestraintAccessService $access, User $current,
        CurrentAuthorizationReads $reads, array $sites, HealthSafetyReadMaterialisationProof $proof, HealthSafetyReadContext $active): array
    {
        // Capture the already-prepared actor/assignment/HR/date evidence before
        // discovery. The same live EXISTS remains in every later metric SQL.
        $authority = $access->metricSourceAuthorityQuery($active);
        abort_unless($proof->protect(clone $authority)->get(['users.id'])->count() === 1, 404);
        $clientRows = $proof->protect($reads->query(Client::query()->whereIn('site_id', $sites)
            ->whereExists((clone $authority)->selectRaw('1')->toBase())))->get(['id', 'site_id']);
        $clients = $clientRows->filter(fn ($client) => app(ClientPolicy::class)->viewFromCurrentEvidence($current, $client, $reads));
        $clientIds = $clients->pluck('id')->all();
        $eventCandidates = RestraintEvent::query()->whereIn('client_id', $clientIds)
            ->where(fn ($query) => $query->whereIn('site_id', $sites)->when($current->canDo('healthSafety.viewAllSites'),
                fn ($wide) => $wide->orWhere(fn ($legacy) => $legacy->whereNull('site_id')->whereNull('source_schema_version'))));
        $planCandidates = BehaviourSupportPlan::query()->whereIn('client_id', $clientIds)
            ->where(fn ($query) => $query->whereNull('source_schema_version')->orWhereIn('source_site_id', $sites));
        if ($requested !== null) {
            $ids = $this->normalizeSiteIds($requested);
            $eventCandidates->whereIn('site_id', $ids);
            $this->siteAccess->applyBehaviourSupportPlanSiteScopeForSiteIds($planCandidates, $ids);
        }
        $queries = [];
        foreach ([[$eventCandidates, self::EVENT_IDENTITY], [$planCandidates, self::PLAN_IDENTITY]] as $index => [$candidate, $columns]) {
            $candidate->whereExists((clone $authority)->selectRaw('1')->toBase());
            $identities = $proof->protect($reads->query(clone $candidate))->orderBy('id')->get($columns);
            $parents = [];
            foreach ($identities as $identity) {
                // Structurally damaged in-scope rows cannot disappear into a healthy zero.
                $parent = $index === 0 ? $access->protectedEventSourceQuery($identity, $active)
                    : $access->protectedPlanSourceQuery($identity, $active);
                // Retain positive graph membership in the shared final barrier.
                // A later broken supporting tuple must not turn a positive into 0.
                abort_unless($proof->protect(clone $parent)->get(['id'])->count() === 1, 404);
                $parents[] = $parent;
            }
            $query = $reads->query(clone $candidate)->where(function (Builder $allowed) use ($identities, $parents): void {
                if ($identities->isEmpty()) { $allowed->whereRaw('1 = 0'); return; }
                foreach ($identities as $position => $identity) {
                    $allowed->orWhere(function (Builder $exact) use ($identity, $parents, $position): void {
                        $this->exact($exact, $identity->getRawOriginal(), $exact->getModel()->getTable())
                            ->whereExists((clone $parents[$position])->selectRaw('1')->toBase());
                    });
                }
            });
            // Preserve the exact Client placement used by canonical Client policy.
            $query->whereHas('client', function (Builder $client) use ($clients): void {
                $client->where(function (Builder $allowed) use ($clients): void {
                    if ($clients->isEmpty()) { $allowed->whereRaw('1 = 0'); return; }
                    foreach ($clients as $identity) { $allowed->orWhere(fn (Builder $exact) => $this->exact($exact, $identity->getRawOriginal(), 'clients')); }
                });
            });
            $queries[] = $query;
        }
        return $queries;
    }

    private function exact(Builder $query, array $values, string $table): Builder
    {
        foreach ($values as $column => $value) {
            $column = $table.'.'.$column;
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            else { $query->where($column, $value); }
        }
        return $query;
    }

    private function normalizeSiteIds(int|array $siteId): array
    {
        return array_values(array_unique(array_filter(array_map('intval', is_array($siteId) ? $siteId : [$siteId]), fn ($id) => $id > 0)));
    }
}
