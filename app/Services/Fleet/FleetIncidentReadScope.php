<?php

namespace App\Services\Fleet;

use App\Models\FleetIncident;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Fleet read authority and asset Site remain independent of the consuming H&S dashboard. */
final class FleetIncidentReadScope
{
    public const PERMISSIONS = ['fleet.viewAny', 'assets.viewAny', 'fleet.manage'];
    public const FILTER_KEYS = ['vehicle_id', 'driver_id', 'site_id', 'severity', 'incident_type', 'status',
        'search', 'date_from', 'date_to', 'tab', 'occurred_from', 'occurred_to'];

    private ?CurrentAuthorizationReads $activeReads = null;
    private ?object $activeInvocation = null;
    private ?int $activeActorObject = null;
    private array $activeSites = [];

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function withCurrentViewer(User $viewer, callable $operation, array $additionalPermissionKeys = []): mixed
    {
        if (array_diff($additionalPermissionKeys, ['fleet.incidents.manage', 'hr.assets.view']) !== []) {
            throw new LogicException('Fleet display preparation is limited to its existing projector capabilities.');
        }
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($viewer, $operation, $additionalPermissionKeys) {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($viewer, array_values(array_unique([...self::PERMISSIONS, ...$additionalPermissionKeys])));
            return $this->consume($current, $reads, $operation);
        }));
    }

    public function withReadContext(User $viewer, HealthSafetyReadContext $context, callable $operation): mixed
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::PERMISSIONS,
            fn ($current, $reads) => $this->consume($current, $reads, $operation));
    }

    private function consume(User $current, CurrentAuthorizationReads $reads, callable $operation): mixed
    {
        $reads->assertActive();
        if ($this->activeReads !== null) { throw new LogicException('Fleet read consumers cannot nest or reuse an active invocation.'); }
        // Mirror existing approved-account/external isolation plus actual route read grants.
        // The Fleet manage Site bypass is not replaced with a new H&S/current-staff rule.
        abort_unless($current->approved_at !== null && ! $current->isExternalClinicianAccount()
            && ($current->canDo('fleet.viewAny') || $current->canDo('assets.viewAny')), 403);
        $this->activeReads = $reads;
        $this->activeInvocation = new \stdClass;
        $this->activeActorObject = spl_object_id($current);
        try {
            $this->activeSites = $this->siteAccess->accessibleSiteIds($current, ['fleet.manage'], $reads);
            $result = $operation($current, $reads, $this->activeSites);
            $reads->assertActive(); $this->assertDetached($result);
            return $result;
        } finally {
            $this->activeReads = null; $this->activeInvocation = null; $this->activeActorObject = null; $this->activeSites = [];
        }
    }

    /** Callback-only: requested Sites narrow canonical Fleet admission; they never confer it. */
    public function queryForCurrentReads(User $current, CurrentAuthorizationReads $reads, ?array $selectedSiteIds = null): Builder
    {
        $this->assertLease($reads, $current);
        $sites = $this->selectedSites($selectedSiteIds);
        return $this->bind($reads->query(FleetIncident::query())->whereHas('asset', fn (Builder $asset) => $asset
            ->whereNotNull('site_id')->whereIn('site_id', $sites)), $reads);
    }

    /** Retains old filters and civil whereDate meaning; only the additive instant interval is normalized. */
    public function normalizeFilters(array $filters): array
    {
        if (array_diff(array_keys($filters), self::FILTER_KEYS) !== []) {
            $this->invalid('filters', 'Use only the supported Fleet incident filters.');
        }
        if (array_key_exists('occurred_from', $filters) || array_key_exists('occurred_to', $filters)) {
            $from = $this->instant($filters['occurred_from'] ?? null, 'occurred_from');
            $to = $this->instant($filters['occurred_to'] ?? null, 'occurred_to');
            if ($from >= $to) { $this->invalid('occurred_to', 'The end instant must follow the start instant.'); }
            $filters['occurred_from'] = $from; $filters['occurred_to'] = $to;
        }
        foreach ($filters as $key => $value) {
            if (is_array($value) || is_object($value)) { $this->invalid($key, 'Use one scalar filter value.'); }
        }
        return $filters;
    }

    public function applyFiltersForCurrentReads(Builder $query, array $filters, CurrentAuthorizationReads $reads): Builder
    {
        $this->assertLease($reads);
        $filters = $this->normalizeFilters($filters);
        // Even a caller-supplied query cannot bypass this invocation's asset Site boundary.
        $query = $reads->query($query)->whereHas('asset', fn (Builder $asset) => $asset
            ->whereNotNull('site_id')->whereIn('site_id', $this->activeSites));
        if (filled($filters['site_id'] ?? null)) {
            $id = filter_var($filters['site_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($id !== false && in_array($id, $this->activeSites, true), 404);
            $query->whereHas('asset', fn (Builder $asset) => $asset->where('site_id', $id));
        }
        foreach (['vehicle_id' => 'asset_id', 'driver_id' => 'driver_user_id', 'severity' => 'severity',
            'incident_type' => 'incident_type', 'status' => 'status'] as $key => $column) {
            if (filled($filters[$key] ?? null)) { $query->where($column, $filters[$key]); }
        }
        if (filled($filters['date_from'] ?? null)) { $query->whereDate('occurred_at', '>=', $filters['date_from']); }
        if (filled($filters['date_to'] ?? null)) { $query->whereDate('occurred_at', '<=', $filters['date_to']); }
        if (isset($filters['occurred_from'])) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['occurred_from'])->utc()->format('Y-m-d H:i:s.u'))
                ->where('occurred_at', '<', Carbon::parse($filters['occurred_to'])->utc()->format('Y-m-d H:i:s.u'));
        }
        if (filled($filters['search'] ?? null)) {
            $term = trim((string) $filters['search']);
            $query->where(fn (Builder $search) => $search->where('description', 'like', "%{$term}%")
                ->orWhere('location', 'like', "%{$term}%")->orWhere('traffic_crash_report_reference', 'like', "%{$term}%")
                ->orWhere('insurance_reference', 'like', "%{$term}%")
                ->orWhereHas('asset', fn (Builder $asset) => $asset->where('name', 'like', "%{$term}%")->orWhere('registration_number', 'like', "%{$term}%")));
        }
        return $this->bind($query, $reads);
    }

    public function windowCount(User $viewer, ?array $selectedSiteIds, string $from, string $to): array
    {
        return $this->countOrRestricted(fn () => $this->withCurrentViewer($viewer,
            fn ($current, $reads) => $this->countInReads($current, $reads, $selectedSiteIds, $from, $to)));
    }

    public function windowCountInContext(User $viewer, HealthSafetyReadContext $context, ?array $selectedSiteIds, string $from, string $to): array
    {
        return $this->countOrRestricted(fn () => $this->withReadContext($viewer, $context,
            fn ($current, $reads) => $this->countInReads($current, $reads, $selectedSiteIds, $from, $to)));
    }

    /** Rolling occurrence interval plus all currently open incidents in one admitted Fleet scope. */
    public function dashboardCountsInContext(User $viewer, HealthSafetyReadContext $context, ?array $selectedSiteIds, string $from, string $to): array
    {
        try {
            return $this->withReadContext($viewer, $context, function ($current, $reads) use ($selectedSiteIds, $from, $to) {
                if ($this->selectedSites($selectedSiteIds) === []) { return ['access' => 'restricted', 'rolling_count' => null, 'open_count' => null]; }
                $base = $this->queryForCurrentReads($current, $reads, $selectedSiteIds);
                $rolling = $this->applyFiltersForCurrentReads(clone $base,
                    ['occurred_from' => $from, 'occurred_to' => $to], $reads);
                return ['access' => 'readable', 'rolling_count' => $rolling->count(), 'open_count' => $base->open()->count()];
            });
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
            return ['access' => 'restricted', 'rolling_count' => null, 'open_count' => null];
        }
    }

    /** Dashboard-only lifetime: original H&S evidence remains active through its outer final proof. */
    public function dashboardCountsWithOriginalContext(User $viewer, HealthSafetyReadContext $context, ?array $selectedSiteIds, string $from, string $to): array
    {
        $context->assertViewer($viewer);
        $keys = [...self::PERMISSIONS, ...UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS];
        $filters = $this->normalizeFilters(['occurred_from' => $from, 'occurred_to' => $to]);

        return HealthSafetyReadMaterialisationProof::within($context, fn (HealthSafetyReadMaterialisationProof $proof): array =>
            $context->consumeSourceViewer($keys, function (User $current, CurrentAuthorizationReads $reads) use ($context, $proof, $keys, $selectedSiteIds, $filters): array {
                $restricted = ['access' => 'restricted', 'rolling_count' => null, 'open_count' => null];
                if ($current->approved_at === null || $current->isExternalClinicianAccount()
                    || (! $current->canDo('fleet.viewAny') && ! $current->canDo('assets.viewAny'))) {
                    return $restricted;
                }
                // This is equality of the original prepared actor, never a new admission baseline.
                $authority = $context->originalSourceAuthorityQuery($keys, []);
                abort_unless($proof->protect(clone $authority)->get(['users.id'])->count() === 1, 404);
                $hsSites = $this->siteAccess->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
                $fleetSites = $this->siteAccess->accessibleSiteIds($current, ['fleet.manage'], $reads);
                $sites = array_values(array_intersect($hsSites, $fleetSites));
                if ($selectedSiteIds !== null) {
                    foreach ($selectedSiteIds as $id) {
                        if (! is_int($id) || $id < 1) { $this->invalid('site_id', 'Use positive integer Site identities.'); }
                    }
                    $sites = array_values(array_intersect($sites, array_values(array_unique($selectedSiteIds))));
                }
                if ($sites === []) { return $restricted; }

                $siteScope = $context->query(Site::query()->active()->notArchived()->whereNull('archived_at')->whereIn('sites.id', $sites)
                    ->whereExists((clone $authority)->selectRaw('1')->toBase()));
                $siteFacts = $proof->protect(clone $siteScope)->get(['sites.id', 'is_active', 'archived', 'archived_at'])
                    ->map(fn (Site $site): array => $site->getRawOriginal())->all();
                abort_unless(count($siteFacts) === count($sites), 404);

                $base = $context->query(FleetIncident::query()->whereExists((clone $authority)->selectRaw('1')->toBase())
                    ->whereHas('asset', fn (Builder $asset) => $asset->whereNotNull('site_id')->whereIn('site_id', $sites)
                        ->whereExists((clone $siteScope)->whereColumn('sites.id', 'assets.site_id')->selectRaw('1')->toBase())));
                // Scope identities only: descriptions, people and vehicle names never enter discovery.
                $discovery = (clone $base)->join('assets as dashboard_fleet_assets', 'dashboard_fleet_assets.id', '=', 'fleet_incidents.asset_id');
                $sourceFacts = $proof->protect(clone $discovery)->get(['fleet_incidents.id', 'fleet_incidents.asset_id',
                    'fleet_incidents.occurred_at', 'fleet_incidents.status', 'dashboard_fleet_assets.site_id as asset_site_id'])
                    ->map(fn (FleetIncident $incident): array => $incident->getRawOriginal())->all();

                $guard = clone $authority;
                foreach ($siteFacts as $site) {
                    $guard->whereExists($this->dashboardExact(clone $siteScope, $site, 'sites')->selectRaw('1')->toBase());
                }
                foreach ($sourceFacts as $source) {
                    $guard->whereExists($this->dashboardSourceTuple(clone $discovery, $source)->selectRaw('1')->toBase());
                }
                $extra = clone $discovery;
                if ($sourceFacts !== []) {
                    $extra->whereNot(function (Builder $known) use ($sourceFacts): void {
                        foreach ($sourceFacts as $source) {
                            $known->orWhere(fn (Builder $row) => $this->dashboardSourceTuple($row, $source));
                        }
                    });
                }
                $guard->whereNotExists($extra->selectRaw('1')->toBase());
                $guard = $context->query($guard);
                abort_unless($proof->protect(clone $guard)->get(['users.id'])->count() === 1, 404);
                // Keep the executable original predicate, including its date/lifetime expressions.
                $base->whereExists((clone $guard)->selectRaw('1')->toBase());
                $rolling = (clone $base)->where('occurred_at', '>=', Carbon::parse($filters['occurred_from'])->utc()->format('Y-m-d H:i:s.u'))
                    ->where('occurred_at', '<', Carbon::parse($filters['occurred_to'])->utc()->format('Y-m-d H:i:s.u'));

                return ['access' => 'readable', 'rolling_count' => $proof->protect($rolling)->count(),
                    'open_count' => $proof->protect((clone $base)->open())->count()];
            }));
    }

    private function dashboardExact(Builder $query, array $values, string $table): Builder
    {
        foreach ($values as $column => $value) {
            $value === null ? $query->whereNull($table.'.'.$column) : $query->where($table.'.'.$column, $value);
        }
        return $query;
    }

    private function dashboardSourceTuple(Builder $query, array $source): Builder
    {
        $query = $this->dashboardExact($query, array_intersect_key($source, array_flip(['id', 'asset_id', 'occurred_at', 'status'])), 'fleet_incidents');
        return $query->where('dashboard_fleet_assets.site_id', $source['asset_site_id']);
    }

    private function countInReads(User $current, CurrentAuthorizationReads $reads, ?array $selectedSiteIds, string $from, string $to): array
    {
        if ($this->selectedSites($selectedSiteIds) === []) { return ['access' => 'restricted', 'count' => null]; }
        $query = $this->queryForCurrentReads($current, $reads, $selectedSiteIds);
        $query = $this->applyFiltersForCurrentReads($query, ['occurred_from' => $from, 'occurred_to' => $to], $reads);
        return ['access' => 'readable', 'count' => $query->count()];
    }

    private function countOrRestricted(callable $operation): array
    {
        try { return $operation(); }
        catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
            return ['access' => 'restricted', 'count' => null];
        }
    }

    private function selectedSites(?array $ids): array
    {
        if ($ids === null) { return $this->activeSites; }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) { $this->invalid('site_id', 'Use positive integer Site identities.'); }
        }
        return array_values(array_intersect($this->activeSites, array_values(array_unique($ids))));
    }

    private function assertLease(CurrentAuthorizationReads $reads, ?User $current = null): void
    {
        $reads->assertActive();
        if ($reads !== $this->activeReads || ($current !== null && spl_object_id($current) !== $this->activeActorObject)) {
            throw new LogicException('Fleet source queries require their exact active current-reader invocation.');
        }
    }

    private function bind(Builder $query, CurrentAuthorizationReads $reads): Builder
    {
        $invocation = $this->activeInvocation;
        $query->getQuery()->beforeQuery(function () use ($reads, $invocation) {
            $this->assertLease($reads);
            if ($invocation === null || $invocation !== $this->activeInvocation) {
                throw new LogicException('Fleet source query has left its original materializing consumer.');
            }
        });
        return $query;
    }

    private function instant(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?(Z|[+-]\d{2}:\d{2})$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) ($parts[6] ?? 0) > 59) {
            $this->invalid($field, 'Use a real ISO date and time with an explicit timezone offset.');
        }
        $offset = $parts[8];
        if ($offset !== 'Z' && ((int) substr($offset, 1, 2) > 14 || (int) substr($offset, 4, 2) > 59
            || ((int) substr($offset, 1, 2) === 14 && (int) substr($offset, 4, 2) !== 0))) {
            $this->invalid($field, 'Use a real timezone offset.');
        }
        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }

    private function assertDetached(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $item) { $this->assertDetached($item); } return; }
        if ($value !== null && ! is_scalar($value)) { throw new LogicException('Fleet source consumers must return materialized scalar data only.'); }
    }
}
