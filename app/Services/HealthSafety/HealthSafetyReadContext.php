<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\HsEvent;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Support\HealthSafety\QueryPaging;

/** Fresh locked evidence belongs to exactly one materializing read callback. */
final class HealthSafetyReadContext
{
    private bool $active = true;

    /** Shared Linked/CR passive active-callback ownership; no proof lookup, replacement or reset API. */
    private array $controlRoomOriginalReadOwners = [];

    private ?\App\Services\Incidents\IncidentHsEventSourceReadService $incidentSources = null;

    /** @var array<string, list<int>> */
    private array $siteIds = [];

    /** @var array<string, array{approved: list<int>, clients: list<int>}> */
    private array $firstAidSubjects = [];

    /** @var array<string, list<int>> */
    private array $firstAidEvents = [];

    private array $originalSourceAuthorities = [];

    private readonly ?array $originalSourceProfile;

    private readonly User $originalSourceActor;

    private readonly array $originalSourceDate;

    private function __construct(
        private ?User $current,
        private readonly CurrentAuthorizationReads $reads,
        private readonly array $permissionKeys,
        private readonly bool $currentStaff,
        array $preparedSourceDate,
    ) {
        $this->originalSourceProfile = $current->getRelation('hrEmployeeProfile')?->getRawOriginal();
        $this->originalSourceActor = clone $current;
        $this->originalSourceActor->setRelation('roles', $current->roles->map(function ($role) {
            $copy = clone $role;
            return $copy->setRelation('permissions', $role->permissions->map(fn ($permission) => clone $permission));
        }));
        $this->originalSourceActor->setRelation('permissionOverrides', $current->permissionOverrides->map(function ($permission) {
            $copy = clone $permission;
            return $copy->setRelation('pivot', clone $permission->pivot);
        }));
        $this->originalSourceDate = $preparedSourceDate;
    }

    /** Equality of original evidence only: this query never grants source access. */
    public function originalSourceAuthorityQuery(array $requiredPermissionKeys, ?array $sourceSiteIds = null): Builder
    {
        $this->consumeSourceViewer($requiredPermissionKeys, static fn () => null);

        sort($requiredPermissionKeys);
        if ($sourceSiteIds !== null) { sort($sourceSiteIds); }
        $key = json_encode([$requiredPermissionKeys, $sourceSiteIds], JSON_THROW_ON_ERROR);
        if (! isset($this->originalSourceAuthorities[$key])) {
            $this->originalSourceAuthorities[$key] = self::captureSourceAuthorityQuery($this->originalSourceActor, $this->reads, $requiredPermissionKeys, $this->originalSourceProfile, $this->originalSourceDate, $sourceSiteIds);
        }

        return clone $this->originalSourceAuthorities[$key];
    }

    /** Capture once, before source discovery; standalone readers retain their own read lifetime. */
    public static function captureSourceAuthorityQuery(User $current, CurrentAuthorizationReads $reads, array $keys, ?array $originalProfile = null, ?array $originalDate = null, ?array $sourceSiteIds = null): Builder
    {
        $reads->assertActive();
        if (! $current->relationLoaded('roles') || ! $current->relationLoaded('permissionOverrides')
            || ! $current->roles->every(fn ($role) => $role->relationLoaded('permissions'))) {
            throw new LogicException('Source equality requires prepared authorization evidence.');
        }
        $guard = User::query()->whereKey($current->id);
        $exact = static function (QueryBuilder $query, array $row): QueryBuilder {
            foreach ($row as $column => $value) {
                if ($value === null) { $query->whereNull($column); }
                elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
                else { $query->where($column, $value); }
            }
            return $query;
        };
        // The prepared roles may arrive as a plain collection; read the keys either way.
        $roles = $current->roles->map(fn ($role) => $role->getKey())->values()->all();
        $definitions = $reads->query(DB::table('permissions')->whereIn('key', $keys))->get(['id', 'key'])->map(fn ($row) => (array) $row)->all();
        $permissionIds = array_column($definitions, 'id');
        $profile = func_num_args() >= 4 ? $originalProfile : $current->getRelation('hrEmployeeProfile')?->getRawOriginal();
        $profileColumns = ['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at'];
        $prepared = ['users' => [array_intersect_key($current->getRawOriginal(), array_flip(['id', 'role', 'approved_at', 'external_clinical_account']))],
            'hr_employee_profiles' => $profile === null ? [] : [array_intersect_key($profile, array_flip($profileColumns))],
            'roles' => [], 'role_user' => [], 'role_permission' => [], 'permission_user' => []];
        foreach ($current->roles as $role) {
            $prepared['roles'][] = ['id' => (int) $role->id, 'name' => $role->getRawOriginal('name')];
            $prepared['role_user'][] = ['role_id' => (int) $role->id, 'user_id' => (int) $current->id];
            foreach ($role->permissions as $permission) {
                if (in_array($permission->key, $keys, true)) {
                    $permissionIds[] = (int) $permission->id;
                    $prepared['role_permission'][] = ['role_id' => (int) $role->id, 'permission_id' => (int) $permission->id];
                    $guard->whereExists($exact(DB::table('permissions'), ['id' => (int) $permission->id, 'key' => $permission->getRawOriginal('key')])->selectRaw('1'));
                }
            }
        }
        foreach ($current->permissionOverrides as $permission) {
            if (in_array($permission->key, $keys, true)) {
                $permissionIds[] = (int) $permission->id;
                $prepared['permission_user'][] = ['permission_id' => (int) $permission->id, 'user_id' => (int) $current->id, 'allowed' => (int) (bool) $permission->pivot->allowed];
                $guard->whereExists($exact(DB::table('permissions'), ['id' => (int) $permission->id, 'key' => $permission->getRawOriginal('key')])->selectRaw('1'));
            }
        }
        $permissionIds = array_values(array_unique($permissionIds));
        $assignments = DB::table('client_user')->where('user_id', $current->id);
        $portal = DB::table('client_portal_users')->where('user_id', $current->id);
        if ($sourceSiteIds !== null) {
            $clients = DB::table('clients')->whereIn('site_id', $sourceSiteIds)->select('id');
            $assignments->whereIn('client_id', clone $clients);
            $portal->whereIn('client_id', clone $clients);
        }
        $sets = [
            [DB::table('users')->where('id', $current->id), ['id', 'role', 'approved_at', 'external_clinical_account'], ['id']],
            [DB::table('permissions')->whereIn('key', $keys), ['id', 'key'], ['id']],
            [DB::table('role_user')->where('user_id', $current->id), ['role_id', 'user_id'], ['role_id', 'user_id']],
            [DB::table('roles')->whereIn('id', $roles), ['id', 'name'], ['id']],
            [DB::table('role_permission')->whereIn('role_id', $roles)->whereIn('permission_id', $permissionIds), ['role_id', 'permission_id'], ['role_id', 'permission_id']],
            [DB::table('permission_user')->where('user_id', $current->id)->whereIn('permission_id', $permissionIds), ['permission_id', 'user_id', 'allowed'], ['permission_id', 'user_id']],
            [DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at'), $profileColumns, ['id']],
            [$assignments, ['id', 'client_id', 'user_id'], ['id']],
            [$portal, ['client_id', 'user_id', 'relation'], ['client_id', 'user_id']],
        ];
        foreach ($sets as [$selector, $columns, $identity]) {
            $rows = $prepared[$selector->from] ?? $reads->query(clone $selector)->get($columns)->map(fn ($row) => (array) $row)->all();
            foreach ($rows as $row) { $guard->whereExists($exact(clone $selector, $row)->selectRaw('1')); }
            $extra = clone $selector;
            if ($rows !== []) {
                $extra->whereNot(function (QueryBuilder $known) use ($rows, $identity, $exact): void {
                    foreach ($rows as $row) { $known->orWhere(fn (QueryBuilder $entry) => $exact($entry, array_intersect_key($row, array_flip($identity)))); }
                });
            }
            $guard->whereNotExists($extra->selectRaw('1'));
        }
        $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
        [$timezone, $day] = $originalDate ?? [$timezone, now($timezone)->toDateString()];
        $guard->whereRaw(new class($reads, $timezone, $day) extends \Illuminate\Database\Query\Expression {
            public function __construct(private readonly CurrentAuthorizationReads $reads, private readonly string $timezone, private readonly string $day) { parent::__construct('1 = 1'); }
            public function getValue(\Illuminate\Database\Grammar $grammar)
            {
                $this->reads->assertActive();
                if ((string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC') !== $this->timezone || now($this->timezone)->toDateString() !== $this->day) {
                    throw new LogicException('Original source date evidence changed during materialisation.');
                }
                return parent::getValue($grammar);
            }
        });

        return $reads->query($guard);
    }

    public static function within(User $viewer, array $permissionKeys, callable $consumer): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($viewer, $permissionKeys, $consumer) {
            $permissionKeys = array_values(array_unique($permissionKeys));
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($viewer, $permissionKeys);
            $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
            $preparedSourceDate = [$timezone, now($timezone)->toDateString()];
            $currentStaff = $current->approved_at !== null && ! $current->isExternalClinicianAccount()
                && $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->whereKey($current->id)->exists();
            $current->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first());
            $context = new self($current, $reads, $permissionKeys, $currentStaff, $preparedSourceDate);
            try {
                $result = $consumer($context);
                self::assertMaterialized($result);

                return $result;
            } finally {
                $context->active = false;
                // Retire the graphs even when an escaped actual query still references its owner.
                // Passive exact stdClass storage only: never invoke caller cleanup callbacks.
                foreach ($context->controlRoomOriginalReadOwners as $owner) { $owner->frames = []; }
                $context->controlRoomOriginalReadOwners = [];
                $context->current = null;
                $context->incidentSources = null;
                $context->siteIds = $context->firstAidSubjects = $context->firstAidEvents = [];
            }
        }));
    }

    /** Shared private Linked/CR owners use a frames array; storage alone never establishes admission. */
    public function retainControlRoomOriginalReadOwner(\stdClass $owner): void
    {
        $this->assertActive();
        if (get_class($owner) !== \stdClass::class || array_keys(get_object_vars($owner)) !== ['frames']
            || ! is_array($owner->frames)) {
            throw new LogicException('Original CR callback ownership requires a plain passive state object.');
        }
        $this->controlRoomOriginalReadOwners[spl_object_id($owner)] ??= $owner;
    }

    public function assertActive(): void
    {
        if (! $this->active || $this->current === null) {
            throw new LogicException('Health and Safety evidence has left its read callback.');
        }
        $this->reads->assertActive();
    }

    /** Execution remains bound to this callback, even if a caller captures the builder. */
    public function query(Builder $query): Builder
    {
        $this->assertActive();

        return $this->reads->query($query);
    }

    public function assertViewer(User $viewer): void
    {
        $this->assertActive();
        if ((int) $viewer->id !== (int) $this->current->id) {
            throw new LogicException('Health and Safety evidence belongs to another viewer.');
        }
    }

    /** Ordinary H&S sources retain their own policy instead of inheriting treatment eligibility. */
    public function consumeSourceViewer(array $requiredPermissionKeys, callable $operation): mixed
    {
        $this->assertActive();
        if (array_diff($requiredPermissionKeys, $this->permissionKeys) !== []) {
            throw new LogicException('The read callback did not prepare the required capabilities.');
        }

        return $operation($this->current, $this->reads);
    }

    /** Union preparation never grants a permission; each consuming operation still checks it. */
    public function consumeViewer(array $requiredPermissionKeys, callable $operation): mixed
    {
        return $this->consumeSourceViewer($requiredPermissionKeys, function ($current, $reads) use ($operation) {
            abort_unless($this->currentStaff, 403);
            $sites = $this->approvedSiteIds(UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
            abort_unless($sites !== [], 403);

            return $operation($current, $reads, $sites);
        });
    }

    private function approvedSiteIds(array $bypassPermissions): array
    {
        $this->assertActive();
        sort($bypassPermissions);
        $key = json_encode($bypassPermissions, JSON_THROW_ON_ERROR);
        if (! array_key_exists($key, $this->siteIds)) {
            $this->siteIds[$key] = app(UserSiteAccessService::class)->accessibleSiteIds($this->current, $bypassPermissions, $this->reads);
        }

        return $this->siteIds[$key];
    }

    /** Canonical H&S Site/null-Site semantics, using this callback's fresh evidence. */
    public function applyEventSiteScope(Builder $query, array $bypassPermissions): Builder
    {
        return $this->consumeSourceViewer($bypassPermissions, function ($current) use ($query, $bypassPermissions) {
            $sites = $this->approvedSiteIds($bypassPermissions);
            $wide = app(UserSiteAccessService::class)->canBypass($current, $bypassPermissions);
            if ($sites === [] && ! $wide) {
                return $this->query($query->whereRaw('1 = 0'));
            }
            $query->where(function (Builder $scope) use ($sites, $wide): void {
                if ($sites !== []) {
                    $scope->whereIn('site_id', $sites);
                }
                if ($wide) {
                    $sites === [] ? $scope->whereNull('site_id') : $scope->orWhereNull('site_id');
                }
            });

            return $this->query($query);
        });
    }

    /** Subject identities remain private; the canonical query consumes them inside this callback. */
    public function withFirstAidSubjects(array $siteIds, bool $includeArchived, callable $operation): mixed
    {
        $this->assertActive();
        $approved = $this->approvedSiteIds(UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
        if (array_diff($siteIds, $approved) !== []) {
            throw new LogicException('The source query requested Sites outside its read callback.');
        }
        sort($siteIds);
        // Archival changes the treatment query, never the active Site/readable Client universe.
        // Keep that option on the source query and its event whitelist, not subject preparation.
        $key = json_encode([$siteIds, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS], JSON_THROW_ON_ERROR);
        if (! array_key_exists($key, $this->firstAidSubjects)) {
            $this->firstAidSubjects[$key] = $this->consumeViewer(FirstAidAccessService::PERMISSIONS,
                fn ($current, $reads) => app(FirstAidAccessService::class)->readableSubjects($current, $reads, $siteIds));
        }
        $this->assertActive();

        return $operation($this->firstAidSubjects[$key]['approved'], $this->firstAidSubjects[$key]['clients']);
    }

    public function applyFirstAidEventScope(Builder $query, array $sourceTypes, ?Builder $sourceCandidates = null): Builder
    {
        $this->assertActive();
        sort($sourceTypes);
        // The source reader deliberately includes archived treatment, and requires an exact injury tuple.
        $key = json_encode([$sourceTypes, true, HsEvent::CATEGORY_INJURY, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS], JSON_THROW_ON_ERROR);
        // A bounded lookup must neither reuse nor populate the global whitelist:
        // two records in the same callback can have disjoint candidate sets.
        if ($sourceCandidates !== null || ! array_key_exists($key, $this->firstAidEvents)) {
            try {
                $ids = $this->consumeViewer(FirstAidAccessService::PERMISSIONS,
                    fn ($current, $reads, $sites) => app(HsEventAccessService::class)->readFirstAidEventIds($current, $reads, $sites, $sourceTypes, $this, $sourceCandidates));
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 403) {
                    throw $error;
                }
                $ids = [];
            }
            if ($sourceCandidates === null) {
                $this->firstAidEvents[$key] = $ids;
            }
        } else {
            $ids = $this->firstAidEvents[$key];
        }
        $this->assertActive();
        // Final source correlation remains binding, including an empty whitelist.
        $query->where(function (Builder $sources) use ($sourceTypes, $ids): void {
            $sources->whereNull('hs_events.source_type')->orWhereNotIn('hs_events.source_type', $sourceTypes)
                ->orWhereIn('hs_events.id', $ids);
        });

        return $this->reads->query($query);
    }

    /** Factual injury provenance uses current source authority, never medical-content hydration. */
    public function applyInjuryEventScope(Builder $query, ?Builder $sourceCandidates = null): Builder
    {
        $this->assertActive();
        $types = array_values(array_unique([WorkplaceInjury::class, (new WorkplaceInjury)->getMorphClass()]));
        $candidates = (clone ($sourceCandidates ?? $query))->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...));
        $ids = [];
        try {
            $ids = $this->consumeSourceViewer(InjuryAccessService::PERMISSIONS,
                fn (User $current): array => app(InjurySourceProjectionService::class)
                    ->readableHsEventIdsInContext($current, $candidates, $this));
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }
        }
        // Discovery cannot widen a final source correlation. Unproven injury
        // bindings stay excluded even when an independent candidate omitted them.
        $query->where(fn (Builder $sources) => $sources->whereNull('hs_events.source_type')
            ->orWhereNotIn('hs_events.source_type', $types)->orWhereIn('hs_events.id', $ids));

        return $this->query($query);
    }

    /** Incident-derived HSE/body scopes use the same prepared actor and original read lifetime. */
    public function applyIncidentEventScope(Builder $query, ?Builder $sourceCandidates = null): Builder
    {
        return $this->consumeSourceViewer(\App\Services\Incidents\IncidentHsEventSourceReadService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads): Builder => $this->incidentSourceReads()
                ->applyEventScopeInReads($query, $current, $reads, $sourceCandidates));
    }

    public function applyIncidentSourceScope(Builder $query): Builder
    {
        return $this->consumeSourceViewer(\App\Services\Incidents\IncidentHsEventSourceReadService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads): Builder => $this->incidentSourceReads()
                ->applyIncidentScopeInReads($query, $current, $reads));
    }

    public function incidentQueryForEvent(HsEvent $original): Builder
    {
        return $this->consumeSourceViewer(\App\Services\Incidents\IncidentHsEventSourceReadService::PERMISSIONS,
            fn (User $current, CurrentAuthorizationReads $reads): Builder => $this->incidentSourceReads()->incidentQueryForEventInReads($original, $current, $reads));
    }

    public function reconsumeIncidentPredicate(Builder $actual, Builder $original): Builder
    {
        $this->assertActive();
        $actual->whereRaw(new class(clone $original) extends \Illuminate\Database\Query\Expression
        {
            public function __construct(private Builder $original)
            {
                parent::__construct('1 = 1');
            }

            public function getValue(\Illuminate\Database\Grammar $grammar)
            {
                // Builder subqueries are compiled eagerly by whereIn. Reconsume the
                // original guarded proof at the destination SELECT's compilation.
                (clone $this->original)->toSql();

                return '1 = 1';
            }
        });

        return $this->query($actual);
    }

    private function incidentSourceReads(): \App\Services\Incidents\IncidentHsEventSourceReadService
    {
        $this->assertActive();
        return $this->incidentSources ??= app(\App\Services\Incidents\IncidentHsEventSourceReadService::class);
    }

    private static function assertMaterialized(mixed $result): void
    {
        if ($result instanceof Model || $result instanceof Builder || $result instanceof QueryBuilder
            || $result instanceof self || $result instanceof CurrentAuthorizationReads) {
            throw new LogicException('Read callbacks may return only materialized source and task projections.');
        }
        if ($result instanceof TaskItem) {
            foreach (get_object_vars($result) as $value) {
                self::assertMaterialized($value);
            }
        } elseif (is_array($result)) {
            foreach ($result as $value) {
                self::assertMaterialized($value);
            }
        } elseif (is_object($result)) {
            throw new LogicException('Read callbacks may not return authority objects.');
        }
    }
}
