<?php

namespace App\Services\HealthSafety;

use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Support\HealthSafety\QueryPaging;

/** Purpose-fixed factual injury projections. No private recovery data or authority escapes. */
final class InjurySourceProjectionService
{
    private ?\WeakMap $metricEvidence = null;
    private ?\WeakMap $metricParents = null;

    public function __construct(private readonly InjuryAccessService $access) {}

    /** Purpose-fixed original injury metric SQL; neither source models nor authority leave this callback. */
    public function consumeMetricQueryInContext(HealthSafetyReadContext $context, Builder $candidates, string $purpose, callable $consume): mixed
    {
        if (! in_array($purpose, ['factual', 'medical_recordability'], true)
            || ! $candidates->getModel() instanceof WorkplaceInjury || $candidates->getQuery()->from !== 'workplace_injuries'
            || $candidates->getQuery()->groups || $candidates->getQuery()->havings || $candidates->getQuery()->unions) {
            throw new LogicException('Use independent canonical injury candidates and a fixed metric purpose.');
        }
        return HealthSafetyReadMaterialisationProof::within($context, function (HealthSafetyReadMaterialisationProof $materialisation) use ($context, $candidates, $purpose, $consume): mixed {
            return $this->access->withReadContext($context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($context, $candidates, $purpose, $consume, $materialisation): mixed {
                $medical = $current->canDo('hr.wellbeing.view');
                if ($purpose === 'medical_recordability' && ! $medical) {
                    return $consume(null, ['access' => 'restricted', 'source_scope' => 'readable_subset', 'source_completeness' => 'unknown', 'attribution_state' => 'unavailable']);
                }
                $query = (clone $candidates)->setEagerLoads([])->whereIn('site_id', $sites);
                $query = $this->metricAuthority($query, $context, $current, $reads, $sites);
                $discovery = (clone $query)->reorder()->when(true, QueryPaging::clear(...))->select(InjuryAccessService::FACTUAL_COLUMNS);
                $discovery = $materialisation->protect($this->metricLifetime($discovery, $reads));
                $roots = $discovery->get();
                $parents = ($this->metricParents ??= new \WeakMap)[$context] ?? [];
                $branches = []; $states = []; $repair = false;
                foreach ($roots as $root) {
                    if (! isset($parents[$root->id])) {
                        $proof = $this->access->factualSourceProof($root, $current, $reads, $sites);
                        $states[] = $proof['attribution_state'];
                        if ($proof['attribution_state'] === 'repair_required') {
                            $parents[$root->id] = ['query' => null, 'state' => 'repair_required'];
                            $repair = true; continue;
                        }
                        $guard = WorkplaceInjury::query()->whereKey($root->id);
                        $this->metricExact($guard, $root->getRawOriginal(), 'workplace_injuries');
                        if ($proof['attribution_state'] === 'recorded') {
                            $version = $reads->query(DB::table('workplace_injury_source_versions')->where('id', $proof['source_version_id']))
                                ->first(['id', 'workplace_injury_id', 'aggregate_revision', 'source_schema_version', 'worker_id', 'site_id', 'factual_content', 'factual_digest']);
                            if (! $version) { throw new LogicException('Original injury factual version disappeared.'); }
                            $guard->whereExists($this->metricExact(DB::table('workplace_injury_source_versions')->where('id', $version->id), (array) $version)->selectRaw('1'))
                                ->whereNotExists(DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $root->id)->where('aggregate_revision', '>', $proof['source_revision'])->selectRaw('1'));
                        } else {
                            $guard->whereNotExists(DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $root->id)
                                ->where(fn ($version) => $version->where('source_schema_version', '<>', 0)->orWhere('aggregate_revision', '<>', 0)
                                    ->orWhereNotNull('factual_content')->orWhereNotNull('factual_digest'))->selectRaw('1'));
                        }
                        $parents[$root->id] = ['query' => $this->metricLifetime($guard, $reads), 'state' => $proof['attribution_state']];
                    } else { $states[] = $parents[$root->id]['state']; }
                    if ($parents[$root->id]['state'] === 'repair_required') { $repair = true; continue; }
                    $branches[] = clone $parents[$root->id]['query'];
                }
                $this->metricParents[$context] = $parents;
                $query->where(function (Builder $allowed) use ($branches): void {
                    if ($branches === []) { $allowed->whereRaw('1 = 0'); return; }
                    foreach ($branches as $branch) { $allowed->orWhereIn('workplace_injuries.id', (clone $branch)->select('workplace_injuries.id')); }
                });
                foreach ([$discovery, ...$branches] as $branch) {
                    $query->whereRaw(new class(clone $branch) extends Expression {
                        public function __construct(private readonly Builder $original) { parent::__construct('1 = 1'); }
                        public function getValue(Grammar $grammar) { (clone $this->original)->toSql(); return parent::getValue($grammar); }
                    });
                }
                $states = array_values(array_unique($states));
                $query = $materialisation->protect($this->metricLifetime($query, $reads));
                abort_unless((clone $query)->count() === count($branches), 404, 'Original injury metric source changed before materialisation.');
                $result = $consume($query, [
                    'access' => $repair ? 'repair_required' : 'readable', 'source_scope' => 'readable_subset', 'source_completeness' => 'unknown',
                    'attribution_state' => count($states) === 1 ? $states[0] : ($states === [] ? 'unavailable' : 'mixed'),
                ]);
                abort_unless((clone $query)->count() === count($branches), 404, 'Original injury metric source changed during materialisation.');
                return $result;
            });
        });
    }

    private function metricLifetime(Builder $query, CurrentAuthorizationReads $reads): Builder
    {
        $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
        $day = now($timezone)->toDateString();
        $query->whereRaw(new class($reads, $timezone, $day) extends Expression {
            public function __construct(private readonly CurrentAuthorizationReads $reads, private readonly string $timezone, private readonly string $day) { parent::__construct('1 = 1'); }
            public function getValue(Grammar $grammar) {
                $this->reads->assertActive();
                if ((string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')) !== $this->timezone || now($this->timezone)->toDateString() !== $this->day) {
                    throw new LogicException('Original injury metric date evidence changed.');
                }
                return parent::getValue($grammar);
            }
        });
        return $reads->query($query);
    }

    private function metricExact(Builder|QueryBuilder $query, array $values, ?string $table = null): Builder|QueryBuilder
    {
        foreach ($values as $column => $value) {
            $column = $table === null ? $column : $table.'.'.$column;
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            else { $query->where($column, $value); }
        }
        return $query;
    }

    private function metricExpectedSet(Builder $guard, QueryBuilder $selector, array $rows, array $identity): void
    {
        foreach ($rows as $row) { $guard->whereExists($this->metricExact(clone $selector, $row)->selectRaw('1')); }
        $extra = clone $selector;
        if ($rows !== []) {
            $extra->whereNot(function (QueryBuilder $known) use ($rows, $identity): void {
                foreach ($rows as $row) { $known->orWhere(fn (QueryBuilder $entry) => $this->metricExact($entry, array_intersect_key($row, array_flip($identity)))); }
            });
        }
        $guard->whereNotExists($extra->selectRaw('1'));
    }

    /** Equality guards preserve canonical original operation/Site evidence; they never confer a permission. */
    private function metricAuthority(Builder $query, HealthSafetyReadContext $context, User $current, CurrentAuthorizationReads $reads, array $sites): Builder
    {
        $cache = $this->metricEvidence ??= new \WeakMap;
        if (! isset($cache[$context])) {
            $snapshot = ['user' => [array_intersect_key($current->getRawOriginal(), array_flip(['id', 'role', 'approved_at', 'external_clinical_account']))],
                'roles' => [], 'memberships' => [], 'grants' => [], 'overrides' => [], 'definitions' => [],
                'permissions' => $reads->query(DB::table('permissions')->whereIn('key', InjuryAccessService::PERMISSIONS))->get(['id', 'key'])->map(fn ($row) => (array) $row)->all()];
            foreach ($current->roles as $role) {
                $snapshot['roles'][] = ['id' => (int) $role->id, 'name' => $role->getRawOriginal('name')];
                $snapshot['memberships'][] = ['role_id' => (int) $role->id, 'user_id' => (int) $current->id];
                foreach ($role->permissions as $permission) {
                    if (in_array($permission->key, InjuryAccessService::PERMISSIONS, true)) {
                        $snapshot['grants'][] = ['role_id' => (int) $role->id, 'permission_id' => (int) $permission->id];
                        $snapshot['definitions'][$permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
                    }
                }
            }
            foreach ($current->permissionOverrides as $permission) {
                if (in_array($permission->key, InjuryAccessService::PERMISSIONS, true)) {
                    $snapshot['overrides'][] = ['user_id' => (int) $current->id, 'permission_id' => (int) $permission->id, 'allowed' => (int) (bool) $permission->pivot->allowed];
                    $snapshot['definitions'][$permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
                }
            }
            $profile = $current->getRelation('hrEmployeeProfile');
            $snapshot['profiles'] = $profile === null ? [] : [array_intersect_key($profile->getRawOriginal(), array_flip(['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at']))];
            $snapshot['sites'] = $reads->query(DB::table('sites')->whereIn('id', $sites))->get(['id', 'is_active', 'archived', 'archived_at', 'deleted_at'])->map(fn ($row) => (array) $row)->all();
            $cache[$context] = $snapshot;
        }
        $snapshot = $cache[$context]; $roleIds = array_column($snapshot['roles'], 'id');
        $permissionIds = array_values(array_unique([...array_column($snapshot['permissions'], 'id'), ...array_keys($snapshot['definitions'])]));
        foreach ($snapshot['definitions'] as $definition) { $query->whereExists($this->metricExact(DB::table('permissions')->where('id', $definition['id']), $definition)->selectRaw('1')); }
        foreach ([
            [DB::table('users')->where('id', $current->id), $snapshot['user'], ['id']],
            [DB::table('permissions')->whereIn('key', InjuryAccessService::PERMISSIONS), $snapshot['permissions'], ['id']],
            [DB::table('role_user')->where('user_id', $current->id), $snapshot['memberships'], ['role_id', 'user_id']],
            [DB::table('roles')->whereIn('id', $roleIds), $snapshot['roles'], ['id']],
            [DB::table('role_permission')->whereIn('role_id', $roleIds)->whereIn('permission_id', $permissionIds), $snapshot['grants'], ['role_id', 'permission_id']],
            [DB::table('permission_user')->where('user_id', $current->id)->whereIn('permission_id', $permissionIds), $snapshot['overrides'], ['user_id', 'permission_id']],
            [DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at'), $snapshot['profiles'], ['id']],
            [DB::table('sites')->whereIn('id', array_column($snapshot['sites'], 'id')), $snapshot['sites'], ['id']],
        ] as [$selector, $rows, $identity]) { $this->metricExpectedSet($query, $selector, $rows, $identity); }
        $query->whereExists(DB::table('sites')->whereColumn('sites.id', 'workplace_injuries.site_id')
            ->where('is_active', true)->where('archived', false)->whereNull('archived_at')->whereNull('deleted_at')->selectRaw('1'));
        return $query;
    }

    /** Complete provider candidate set; TaskItem objects are constructed only after this callback returns. */
    public function taskRows(User $viewer, array $filters = [], ?HealthSafetyReadContext $context = null): array
    {
        try {
            return $this->within($viewer, $context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($filters): array {
                $query = $this->access->readableQuery($current, $reads, $sites)
                    ->select(InjuryAccessService::FACTUAL_COLUMNS)
                    ->when(isset($filters['id']), fn (Builder $query) => $query->whereKey((int) $filters['id']))
                    ->orderByDesc('injury_date')->orderByDesc('id')->limit(300);
                if (empty($filters['include_done'])) {
                    $query->whereNotIn('status', ['recovered', 'closed']);
                }
                $injuries = $query->get();
                $proofs = [];
                $injuries = $injuries->filter(function (WorkplaceInjury $injury) use ($current, $reads, $sites, &$proofs): bool {
                    $proofs[(int) $injury->id] = $this->access->factualSourceProof($injury, $current, $reads, $sites);
                    return $proofs[(int) $injury->id]['attribution_state'] !== 'repair_required';
                });
                if ($injuries->isEmpty()) {
                    return [];
                }
                $people = $reads->query(User::query())->whereIn('id', $injuries->pluck('user_id')->unique()->all())
                    ->get(['id', 'name'])->mapWithKeys(fn (User $user): array => [(int) $user->id => ['id' => (int) $user->id, 'name' => $user->name]])->all();
                $locations = $reads->query(Site::query())->whereIn('id', $injuries->pluck('site_id')->unique()->all())
                    ->get(['id', 'name'])->mapWithKeys(fn (Site $site): array => [(int) $site->id => ['id' => (int) $site->id, 'name' => $site->name]])->all();
                $rows = [];
                foreach ($injuries as $injury) {
                    $fact = $this->facts($injury, $current, $reads, $sites, $people, $locations, $proofs[(int) $injury->id]);
                    if ($fact !== null) {
                        $rows[] = $fact;
                    }
                }
                return $rows;
            });
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }
            return [];
        }
    }

    public function forInjury(int $id, User $viewer, ?HealthSafetyReadContext $context = null): ?array
    {
        try {
            return $this->within($viewer, $context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($id): ?array {
                $row = $this->access->readableQuery($current, $reads, $sites)->select(InjuryAccessService::FACTUAL_COLUMNS)->whereKey($id)->first();
                return $row ? $this->facts($row, $current, $reads, $sites) : null;
            });
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }
            return null;
        }
    }

    /** The consumer still applies canonical operational access. This method grants no alert view or command. */
    public function forAlert(ControlRoomAlert $alert, User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        try {
            return $this->within($viewer, $context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($alert): array {
                // Read identity keys only; never hydrate copied clinical/narrative context for this proof.
                $row = $reads->query(ControlRoomAlert::query())->whereKey($alert->id)
                    ->select(['id', 'source', 'alert_type', 'site_id', 'client_id'])
                    ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.workplace_injury_id')) as injury_identity")
                    ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.site_id')) as source_site_identity")
                    ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.user_id')) as worker_identity")->first();
                if (! $row) {
                    return ['access' => 'restricted', 'source' => null];
                }
                $injuryId = $this->positiveId($row->injury_identity);
                if ($row->alert_type !== 'operations.workplace_injury' && $injuryId === null) {
                    return ['access' => 'absent', 'source' => null, 'ignored' => true];
                }
                if ($row->source !== 'operations' || $row->alert_type !== 'operations.workplace_injury'
                    || $injuryId === null || $row->client_id !== null) {
                    return ['access' => 'restricted', 'source' => null];
                }
                $injury = $this->access->readableQuery($current, $reads, $sites)->select(InjuryAccessService::FACTUAL_COLUMNS)->whereKey($injuryId)->first();
                if (! $injury) {
                    $exists = $reads->query(WorkplaceInjury::query())->whereKey($injuryId)->exists();
                    return ['access' => $exists ? 'restricted' : 'unavailable', 'source' => null];
                }
                if ($this->positiveId($row->site_id) !== (int) $injury->site_id
                    || $this->positiveId($row->source_site_identity) !== (int) $injury->site_id
                    || $this->positiveId($row->worker_identity) !== (int) $injury->user_id) {
                    return ['access' => 'restricted', 'source' => null];
                }
                $fact = $this->facts($injury, $current, $reads, $sites);
                return $fact === null
                    ? ['access' => 'unavailable', 'source' => null, 'integrity' => 'repair_required']
                    : ['access' => 'readable', 'source' => $fact];
            });
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }
            return ['access' => 'restricted', 'source' => null];
        }
    }

    /** Materialise only the independent caller candidates; final SQL correlation/denial stays with the caller. */
    public function readableHsEventIdsInContext(User $viewer, Builder $sourceCandidates, HealthSafetyReadContext $context): array
    {
        return $this->within($viewer, $context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($sourceCandidates): array {
            $types = array_unique([WorkplaceInjury::class, (new WorkplaceInjury)->getMorphClass()]);
            $query = $reads->query(clone $sourceCandidates)->whereIn('hs_events.source_type', $types)
                ->where('hs_events.event_category', HsEvent::CATEGORY_INJURY)
                ->select(['hs_events.id', 'hs_events.source_id', 'hs_events.site_id', 'hs_events.staff_id',
                    'hs_events.client_id', 'hs_events.asset_id']);
            $ids = [];
            $query->chunkById(200, function ($events) use ($current, $reads, $sites, &$ids): void {
                $sources = $this->access->readableQuery($current, $reads, $sites)->select(InjuryAccessService::FACTUAL_COLUMNS)
                    ->whereIn('id', $events->pluck('source_id')->unique()->all())->get()->keyBy('id');
                $workerIds = $reads->query(User::query())->whereIn('id', $sources->pluck('user_id')->unique()->all())
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $siteIds = $reads->query(Site::query())->whereIn('id', $sources->pluck('site_id')->unique()->all())
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $proofs = [];
                foreach ($events as $event) {
                    $source = $sources->get((int) $event->source_id);
                    if ($source && $this->positiveId($event->site_id) === (int) $source->site_id
                        && $this->positiveId($event->staff_id) === (int) $source->user_id
                        && $event->client_id === null && $event->asset_id === null) {
                        $proof = $proofs[(int) $source->id] ??= $this->access->factualSourceProof($source, $current, $reads, $sites);
                        if ($proof['attribution_state'] !== 'repair_required'
                            && in_array((int) $source->user_id, $workerIds, true)
                            && in_array((int) $source->site_id, $siteIds, true)) {
                            $ids[] = (int) $event->id;
                        }
                    }
                }
            }, 'hs_events.id', 'id');
            return array_values(array_unique($ids));
        });
    }

    private function within(User $viewer, ?HealthSafetyReadContext $context, callable $operation): mixed
    {
        if ($context !== null) {
            $context->assertViewer($viewer);
            return $this->access->withReadContext($context, $operation);
        }
        return $this->access->withCurrentViewer($viewer, $operation);
    }

    private function facts(WorkplaceInjury $row, User $current, CurrentAuthorizationReads $reads, array $sites,
        ?array $people = null, ?array $locations = null, ?array $proof = null): ?array
    {
        // Every caller supplied a scoped current-locking factual SELECT in this active callback.
        // Prepared proof/labels are private callback-local scalar evidence, never shared or cached.
        $reads->assertActive();
        if ((int) $row->site_id < 1 || (int) $row->user_id < 1) {
            return null;
        }
        $proof ??= $this->access->factualSourceProof($row, $current, $reads, $sites);
        if ($proof['attribution_state'] === 'repair_required') {
            return null;
        }
        $worker = $people === null
            ? $reads->query(User::query())->whereKey($row->user_id)->first(['id', 'name'])?->only(['id', 'name'])
            : ($people[(int) $row->user_id] ?? null);
        $site = $locations === null
            ? $reads->query(Site::query())->whereKey($row->site_id)->first(['id', 'name'])?->only(['id', 'name'])
            : ($locations[(int) $row->site_id] ?? null);
        if (! $worker || ! $site) {
            return null;
        }
        $coordinator = null;
        if ($row->coordinator_user_id !== null) {
            try {
                $person = $this->access->currentStaff((int) $row->coordinator_user_id, (int) $row->site_id,
                    $current, $reads, $sites, true);
                $coordinator = ['id' => (int) $person->id, 'name' => $person->name];
            } catch (HttpExceptionInterface $denial) {
                if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                    throw $denial;
                }
            }
        }
        return ['id' => (int) $row->id, 'reference' => $row->reference_number, 'status' => $row->status,
            'severity' => $row->severity, 'injury_type' => $row->injury_type, 'body_part_affected' => $row->body_part_affected,
            'description' => $row->description, 'injury_date' => $row->injury_date?->toISOString(),
            'worker' => ['id' => (int) $worker['id'], 'name' => $worker['name']],
            'site' => ['id' => (int) $site['id'], 'name' => $site['name']], 'coordinator' => $coordinator,
            'review_date' => $row->next_review_date?->toDateString(),
            'due_at' => $row->next_review_date === null ? null
                : CarbonImmutable::parse($row->next_review_date->toDateString(), 'Pacific/Auckland')->endOfDay()->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(), 'source_revision' => $proof['source_revision'],
            'source_version_id' => $proof['source_version_id'],
            'attribution_state' => $proof['attribution_state'], 'medical_access' => 'excluded',
            'href' => '/health-safety/injuries/'.$row->id];
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value))) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
