<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;
use LogicException;
use App\Support\HealthSafety\QueryPaging;

/** Original incident/HSE association, intersected with existing source authority before copied bodies. */
final class IncidentHsEventSourceReadService
{
    public const PERMISSIONS = IncidentSavedCopyService::PERMISSIONS;
    private const EVENT_META = ['id', 'source_type', 'source_id', 'event_category', 'idempotency_key', 'site_id', 'client_id', 'control_room_alert_id'];
    private const ROOT_META = ['id', 'type', 'hs_event_id', 'control_room_alert_id', ...IncidentIndependenceIntakeService::ROOT_COLUMNS];
    private \WeakMap $evidence;
    private \WeakMap $eventParents;
    private \WeakMap $rootParents;
    private \WeakMap $discovery;

    public function __construct()
    {
        $this->evidence = new \WeakMap;
        $this->eventParents = new \WeakMap;
        $this->rootParents = new \WeakMap;
        $this->discovery = new \WeakMap;
    }

    /** Classification only: suspicious spellings/direct links must not escape through the unrelated-source arm. */
    public function incidentRelated(Builder $events): Builder
    {
        return $events->where(function (Builder $family): void {
            $family->whereRaw('LOWER(TRIM(LEADING ? FROM TRIM(REPLACE(REPLACE(REPLACE(`hs_events`.`source_type`, CHAR(9), ?), CHAR(10), ?), CHAR(13), ?)))) = LOWER(?)', ['\\', '', '', '', ClientIncident::class])
                ->orWhereExists(DB::table('client_incidents as hs_incident_family')->selectRaw('1')
                    ->whereColumn('hs_incident_family.hs_event_id', 'hs_events.id'));
        });
    }

    /** No callback means no source evidence, never an inferred permission or a detached ID admission list. */
    public function withoutIncidentSources(Builder $events): Builder
    {
        return $events->where(function (Builder $ordinary): void {
            $ordinary->where(fn (Builder $typed) => $typed->whereNull('hs_events.source_type')
                ->orWhereRaw('LOWER(TRIM(LEADING ? FROM TRIM(REPLACE(REPLACE(REPLACE(`hs_events`.`source_type`, CHAR(9), ?), CHAR(10), ?), CHAR(13), ?)))) <> LOWER(?)', ['\\', '', '', '', ClientIncident::class]))
                ->whereNotExists(DB::table('client_incidents as hs_incident_family')->selectRaw('1')
                    ->whereColumn('hs_incident_family.hs_event_id', 'hs_events.id'));
        });
    }

    public function applyEventScopeInReads(Builder $events, User $current, CurrentAuthorizationReads $reads, ?Builder $candidates = null): Builder
    {
        $reads->assertActive();
        $candidates = (clone ($candidates ?? $events))->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...));
        $this->assertEvents($candidates);
        $identities = $reads->query($this->incidentRelated($candidates))->get(self::EVENT_META);
        $sourceIds = $identities->pluck('source_id')->filter()->all();
        $eventIds = $identities->pluck('id')->all();
        if ($eventIds !== []) {
            $roots = $reads->query(ClientIncident::query()->where(fn (Builder $root) => $root
                ->whereIn('id', $sourceIds)->orWhereIn('hs_event_id', $eventIds)))->get(array_values(array_unique(self::ROOT_META)));
            $discovery = $this->discovery[$reads] ?? [];
            foreach ($identities as $event) {
                $discovery[$event->id] ??= $roots->filter(fn ($root) => (int) $root->id === (int) $event->source_id || (int) $root->hs_event_id === (int) $event->id)->values();
            }
            $this->discovery[$reads] = $discovery;
        }
        $relatedIds = $identities->pluck('id')->all();
        $bindings = [];
        foreach ($identities as $event) {
            $parent = $this->incidentQueryForEventInReads($event, $current, $reads);
            $bindings[] = ['event' => $event, 'parent' => $parent];
        }
        $events->where(function (Builder $scope) use ($bindings, $relatedIds): void {
            $scope->where(fn (Builder $ordinary) => $this->withoutIncidentSources($ordinary)->whereNotIn('hs_events.id', $relatedIds));
            foreach ($bindings as $binding) {
                $scope->orWhere(function (Builder $exact) use ($binding): void {
                    $this->exact($exact, $binding['event']->getAttributes(), 'hs_events');
                    $exact->whereExists((clone $binding['parent'])->reorder()->selectRaw('1')->toBase());
                });
            }
        });
        return $this->protect($events, $reads);
    }

    /** Active, protected parent Builder. Metadata models only locate the literal original; they are not read receipts. */
    public function incidentQueryForEventInReads(HsEvent $original, User $current, CurrentAuthorizationReads $reads): Builder
    {
        $reads->assertActive();
        $empty = fn (): Builder => $this->protect(ClientIncident::query()->whereRaw('1 = 0'), $reads);
        $meta = array_intersect_key($original->getRawOriginal(), array_flip(self::EVENT_META));
        if (count($meta) !== count(self::EVENT_META) || ! $original->id) { return $empty(); }
        $type = $original->getRawOriginal('source_type');
        // Existing Journey read contract allows leading slash legacy spelling, never case/trailing-byte rewrites.
        if (! is_string($type) || ltrim($type, '\\') !== ClientIncident::class || ! $original->source_id) { return $empty(); }
        $eventParents = $this->eventParents[$reads] ?? [];
        if (isset($eventParents[$current->id][$original->id])) {
            return $eventParents[$current->id][$original->id]['meta'] === $meta ? clone $eventParents[$current->id][$original->id]['query'] : $empty();
        }
        $roots = ($this->discovery[$reads] ?? [])[$original->id] ?? $reads->query(ClientIncident::query()->where(fn (Builder $root) => $root
            ->whereKey($original->source_id)->orWhere('hs_event_id', $original->id)))->get(array_values(array_unique(self::ROOT_META)));
        if ($roots->count() !== 1 || (int) $roots->sole()->id !== (int) $original->source_id) { return $empty(); }
        $root = $roots->sole();
        if (($root->hs_event_id !== null && (int) $root->hs_event_id !== (int) $original->id)
            || ! app(IncidentJourneyService::class)->hsEventIsCanonicalForIncident($root, $original)) { return $empty(); }
        $query = $this->originalRootParent($root, $current, $reads);
        $event = HsEvent::query()->whereKey($original->id);
        $this->exact($event, $meta, 'hs_events');
        $event->whereNotExists(DB::table('client_incidents as hs_other_incident')->selectRaw('1')
            ->where('hs_other_incident.hs_event_id', $original->id)->where('hs_other_incident.id', '!=', $root->id));
        $event->whereNotExists(DB::table('hs_events as hs_other_source')->selectRaw('1')
            ->where('hs_other_source.id', '!=', $original->id)->where('hs_other_source.source_id', $root->id)
            ->whereRaw('LOWER(TRIM(LEADING ? FROM TRIM(REPLACE(REPLACE(REPLACE(`hs_other_source`.`source_type`, CHAR(9), ?), CHAR(10), ?), CHAR(13), ?)))) = LOWER(?)', ['\\', '', '', '', ClientIncident::class]));
        $query->whereExists($event->selectRaw('1')->toBase());
        $query = $this->authorityUnchanged($query, $current, $reads, $original->site_id, $original->client_id);
        $query = $this->protect($query, $reads);
        $eventParents[$current->id][$original->id] = ['meta' => $meta, 'query' => clone $query];
        $this->eventParents[$reads] = $eventParents;
        return $query;
    }

    /** Original incident scopes for admitted metrics/reports. No HSE association is invented for an independent incident. */
    public function applyIncidentScopeInReads(Builder $incidents, User $current, CurrentAuthorizationReads $reads): Builder
    {
        $reads->assertActive();
        if (! $incidents->getModel() instanceof ClientIncident || $incidents->getQuery()->from !== 'client_incidents') {
            throw new LogicException('Incident source scope requires the canonical incident table.');
        }
        $discover = app(IncidentAccessService::class)->apply((clone $incidents)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...)), $current, $reads);
        $roots = $reads->query($discover->select(array_values(array_unique(self::ROOT_META))))->get();
        $bindings = [];
        foreach ($roots as $root) { $bindings[] = $this->originalRootParent($root, $current, $reads); }
        if ($bindings === []) { return $this->protect($incidents->whereRaw('1 = 0'), $reads); }
        $incidents->where(function (Builder $allowed) use ($bindings): void {
            foreach ($bindings as $guard) {
                $allowed->orWhereIn('client_incidents.id', (clone $guard)->reorder()->select('client_incidents.id'));
            }
        });
        return $this->protect($incidents, $reads);
    }

    /** Reuse the earliest original source proof within this exact reads/actor lifetime; never recapture a changed root as acceptance. */
    private function originalRootParent(ClientIncident $root, User $current, CurrentAuthorizationReads $reads): Builder
    {
        $parents = $this->rootParents[$reads] ?? [];
        if (isset($parents[$current->id][$root->id])) { return clone $parents[$current->id][$root->id]; }
        $query = app(IncidentAccessService::class)->apply(ClientIncident::query()->whereKey($root->id), $current, $reads);
        $query = app(IncidentIndependenceIntakeService::class)->applySourceScopeInReads($query, $current, $reads, 'read');
        $this->exact($query, array_intersect_key($root->getRawOriginal(), array_flip(self::ROOT_META)), 'client_incidents');
        $query = $this->protect($this->authorityUnchanged($query, $current, $reads, $root->site_id, $root->client_id), $reads);
        $parents[$current->id][$root->id] = clone $query;
        $this->rootParents[$reads] = $parents;
        return $query;
    }

    /** Every child SELECT embeds the SAME original parent/HSE/independence/authority predicate. */
    public function childQueryInReads(Builder $children, string $parentColumn, Builder $parent, CurrentAuthorizationReads $reads): Builder
    {
        $reads->assertActive();
        $children->whereRaw(new class(clone $parent) extends Expression {
            public function __construct(private readonly Builder $original)
            {
                parent::__construct('1 = 1');
            }
            public function getValue(Grammar $grammar)
            {
                (clone $this->original)->toSql();
                return parent::getValue($grammar);
            }
        });
        $children->whereIn($parentColumn, (clone $parent)->reorder()->select('client_incidents.id'));
        return $this->protect($children, $reads);
    }

    /** Nested SQL compilation remains bound even when an outer unrelated Builder tries to reuse the predicate. */
    private function protect(Builder $query, CurrentAuthorizationReads $reads): Builder
    {
        $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
        $day = now($timezone)->toDateString();
        $query->whereRaw(new class($reads, $timezone, $day) extends Expression {
            public function __construct(private readonly CurrentAuthorizationReads $reads, private readonly string $timezone, private readonly string $day)
            {
                parent::__construct('1 = 1');
            }
            public function getValue(Grammar $grammar)
            {
                $this->reads->assertActive();
                if ((string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')) !== $this->timezone || now($this->timezone)->toDateString() !== $this->day) {
                    throw new LogicException('Incident/HSE source date evidence changed during materialisation.');
                }
                return parent::getValue($grammar);
            }
        });
        return $reads->query($query);
    }

    private function assertEvents(Builder $query): void
    {
        if (! $query->getModel() instanceof HsEvent || $query->getQuery()->from !== 'hs_events'
            || $query->getQuery()->groups || $query->getQuery()->havings || $query->getQuery()->unions) {
            throw new LogicException('Incident HSE scope requires independent canonical event candidates.');
        }
    }

    private function exact(Builder|QueryBuilder $query, array $values, ?string $table = null): Builder|QueryBuilder
    {
        foreach ($values as $column => $value) {
            $column = $table === null ? $column : $table.'.'.$column;
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            else { $query->where($column, $value); }
        }
        return $query;
    }

    /** Equality only, after canonical Access/Intake admission. Reuses original evidence; it never evaluates/confers permission. */
    private function authorityUnchanged(Builder $source, User $current, CurrentAuthorizationReads $reads, ?int $siteId, ?int $clientId): Builder
    {
        $actors = $this->evidence[$reads] ?? [];
        if (! isset($actors[$current->id])) {
            if (! $current->relationLoaded('roles') || ! $current->relationLoaded('permissionOverrides')
                || ! $current->roles->every(fn ($role) => $role->relationLoaded('permissions'))) {
                throw new LogicException('Incident/HSE source requires prepared canonical authorization evidence.');
            }
            $snapshot = ['users' => [array_intersect_key($current->getRawOriginal(), array_flip(['id', 'role', 'approved_at', 'external_clinical_account']))],
                'definitions' => $reads->query(DB::table('permissions')->whereIn('key', self::PERMISSIONS))->get(['id', 'key'])->map(fn ($row) => (array) $row)->all(),
                'roles' => [], 'memberships' => [], 'grants' => [], 'overrides' => [], 'used_definitions' => []];
            foreach ($current->roles as $role) {
                $snapshot['roles'][] = ['id' => (int) $role->id, 'name' => $role->getRawOriginal('name')];
                $snapshot['memberships'][] = ['role_id' => (int) $role->id, 'user_id' => (int) $current->id];
                foreach ($role->permissions as $permission) {
                    if (in_array($permission->key, self::PERMISSIONS, true)) {
                        $snapshot['grants'][] = ['role_id' => (int) $role->id, 'permission_id' => (int) $permission->id];
                        $snapshot['used_definitions'][$permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
                    }
                }
            }
            foreach ($current->permissionOverrides as $permission) {
                if (in_array($permission->key, self::PERMISSIONS, true)) {
                    $snapshot['overrides'][] = ['permission_id' => (int) $permission->id, 'user_id' => (int) $current->id, 'allowed' => (int) (bool) $permission->pivot->allowed];
                    $snapshot['used_definitions'][$permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
                }
            }
            $snapshot['profiles'] = $reads->query(DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at'))
                ->get(['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at'])->map(fn ($row) => (array) $row)->all();
            // Identity/status facts only. These original sets cover later bounded Sites/Clients without rebaselining changed evidence.
            $snapshot['sites'] = $reads->query(DB::table('sites'))->get(['id', 'is_active', 'archived', 'archived_at', 'deleted_at'])->map(fn ($row) => (array) $row)->all();
            $snapshot['clients'] = $reads->query(DB::table('client_user')->where('user_id', $current->id))->get(['id', 'client_id', 'user_id'])->map(fn ($row) => (array) $row)->all();
            $actors[$current->id] = $snapshot;
            $this->evidence[$reads] = $actors;
        }
        $snapshot = $actors[$current->id];
        $roleIds = array_column($snapshot['roles'], 'id');
        $permissionIds = array_values(array_unique([...array_column($snapshot['definitions'], 'id'), ...array_keys($snapshot['used_definitions'])]));
        foreach ($snapshot['used_definitions'] as $definition) {
            $source->whereExists($this->exact(DB::table('permissions')->where('id', $definition['id']), $definition)->selectRaw('1'));
        }
        foreach ([
            [DB::table('users')->where('id', $current->id), $snapshot['users'], ['id']],
            [DB::table('permissions')->whereIn('key', self::PERMISSIONS), $snapshot['definitions'], ['id']],
            [DB::table('role_user')->where('user_id', $current->id), $snapshot['memberships'], ['role_id', 'user_id']],
            [DB::table('roles')->whereIn('id', $roleIds), $snapshot['roles'], ['id']],
            [DB::table('role_permission')->whereIn('role_id', $roleIds)->whereIn('permission_id', $permissionIds), $snapshot['grants'], ['role_id', 'permission_id']],
            [DB::table('permission_user')->where('user_id', $current->id)->whereIn('permission_id', $permissionIds), $snapshot['overrides'], ['permission_id', 'user_id']],
            [DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at'), $snapshot['profiles'], ['id']],
            [DB::table('sites')->where('id', $siteId), array_values(array_filter($snapshot['sites'], fn ($row) => (int) $row['id'] === $siteId)), ['id']],
            [DB::table('client_user')->where('user_id', $current->id)->where('client_id', $clientId), array_values(array_filter($snapshot['clients'], fn ($row) => (int) $row['client_id'] === $clientId)), ['id']],
        ] as [$selector, $rows, $identity]) { $this->expectedSet($source, $selector, $rows, $identity); }
        return $source;
    }

    private function expectedSet(Builder $guard, QueryBuilder $selector, array $rows, array $identity): void
    {
        foreach ($rows as $row) { $guard->whereExists($this->exact(clone $selector, $row)->selectRaw('1')); }
        $extra = clone $selector;
        if ($rows !== []) {
            $extra->whereNot(function (QueryBuilder $known) use ($rows, $identity): void {
                foreach ($rows as $row) {
                    $known->orWhere(fn (QueryBuilder $entry) => $this->exact($entry, array_intersect_key($row, array_flip($identity))));
                }
            });
        }
        $guard->whereNotExists($extra->selectRaw('1'));
    }
}
