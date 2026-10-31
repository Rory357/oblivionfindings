<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** User-command boundary; protected Journey carries the same original source evidence. */
final class IncidentResponseEscalationService
{
    private const PERMISSIONS = [
        'controlRoom.alerts.create', 'incidents.viewAny', 'incidents.viewAssigned',
        'healthSafety.viewAllSites', 'hr.cases.view', 'reports.viewAny',
    ];

    public function __construct(
        private readonly IncidentAccessService $access,
        private readonly AuthorizationEvidenceLockService $authorization,
        private readonly IncidentJourneyService $journeys,
        private readonly UserSiteAccessService $sites,
        private readonly IncidentIndependenceIntakeService $independence,
    ) {}

    public function canEscalate(User $actor, ClientIncident $incident, ?CurrentAuthorizationReads $reads = null): bool
    {
        $consume = function (CurrentAuthorizationReads $currentReads) use ($actor, $incident): bool {
            $current = $this->authorization->lockForUserWithoutWaiting($actor, self::PERMISSIONS);
            return $this->admittedSource((int) $incident->id, $current, $currentReads)['query']->exists();
        };
        if ($reads !== null) { $reads->assertActive(); return $consume($reads); }
        return DB::transaction(fn () => CurrentAuthorizationReads::within($consume));
    }

    public function escalate(User $actor, int $incidentId, ?string $reason, string $severity): ControlRoomAlert
    {
        try {
            return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $incidentId, $reason, $severity): ControlRoomAlert {
                $current = $this->authorization->lockForUserWithoutWaiting($actor, self::PERMISSIONS);
                $admitted = $this->admittedSource($incidentId, $current, $reads);
                abort_unless($admitted['identity'] !== null, 403, 'You are not authorized to access that incident source.');
                return IncidentResponseJourneyReadContext::within($reads, $admitted['query'], $admitted['identity'], $current, $admitted['sites'], function ($source) use ($incidentId, $current, $reason, $severity, $admitted): ControlRoomAlert {
                    $incident = $source->incidentQuery(ClientIncident::query()->whereKey($incidentId), true)->first();
                    abort_unless($incident, 403, 'You are not authorized to access that incident source.');
                    if ($incident->status === 'draft' || ! $incident->isSubmitted()) {
                        throw ValidationException::withMessages(['source_id' => 'Submit the incident before creating its Control Room response.']);
                    }
                    $source->assertCurrent();
                    $journey = $this->journeys->ensureAlertForIncident($incident, $current, $reason, $severity, $source);
                    $alert = $journey->alert;
                    if ($alert === null) { throw new \RuntimeException('The canonical incident journey did not return its operational alert.'); }
                    abort_unless(in_array((int) $alert->site_id, $admitted['sites'], true), 403, 'You are not authorized to create an alert for that Site.');
                    $source->assertCurrent();
                    AuditLogger::logOrFail('controlRoom.alert.createFromIncident', $alert, [
                        'actor_id' => (int) $current->id, 'alert_id' => $alert->id,
                        'incident_source_type' => 'client_incident', 'incident_source_id' => $incident->id,
                    ]);
                    $source->assertCurrent();
                    return $alert;
                });
            }), 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['source_id' => 'The incident or your access is being changed. Review the current incident and try again; your entries have been kept.']);
            }
            throw $exception;
        }
    }

    /** Metadata/source proof only until the final guarded private SELECT. */
    private function admittedSource(int $id, User $current, CurrentAuthorizationReads $reads): array
    {
        $reads->assertActive();
        $empty = fn () => ['query' => $reads->query(ClientIncident::query()->whereKey($id)->whereRaw('1 = 0')), 'sites' => [], 'identity' => null];
        if (! $current->canDo('controlRoom.alerts.create') || $current->approved_at === null || $current->isExternalClinicianAccount()) { return $empty(); }
        $responseSites = $this->sites->accessibleSiteIds($current, ['reports.viewAny'], $reads);
        $base = $this->access->forSiteIds($this->access->apply(ClientIncident::query()->whereKey($id), $current, $reads), $responseSites);
        $identity = $reads->query(clone $base)->first(IncidentResponseJourneyReadContext::METADATA);
        if ($identity === null) { return $empty(); }
        $observedSites = array_values(array_unique([...$responseSites, ...$this->sites->accessibleSiteIds($current, [], $reads)]));
        $base = $this->evidenceGuard($base, $current, $reads, $identity, $observedSites);
        // Evidence capture cannot turn a later observation into a new permission baseline.
        // Reconsume canonical current profile/Site policy and require BOTH source scopes.
        $currentResponseSites = $this->sites->accessibleSiteIds($current, ['reports.viewAny'], $reads);
        $confirmed = $this->access->forSiteIds($this->access->apply(ClientIncident::query()->whereKey($id), $current, $reads), $currentResponseSites);
        $base->whereIn('client_incidents.id', $confirmed->select('client_incidents.id'));
        $base = $this->independence->applySourceScopeInReads($base, $current, $reads, 'read');
        return ['query' => $reads->query($base), 'sites' => $responseSites, 'identity' => $identity];
    }
    /** Equality only: no permission evaluation, new grant or global/cross-callback whitelist. */
    private function evidenceGuard(Builder $source, User $current, CurrentAuthorizationReads $reads, ClientIncident $identity, array $siteIds): Builder
    {
        $reads->assertActive();
        if (! $current->relationLoaded('roles') || ! $current->relationLoaded('permissionOverrides')
            || ! $current->roles->every(fn ($role) => $role->relationLoaded('permissions'))) {
            throw new LogicException('Response admission requires the prepared canonical authorization evidence.');
        }
        $guard = clone $source;
        $this->expectedSet($guard, DB::table('users')->where('id', $current->id),
            [array_intersect_key($current->getRawOriginal(), array_flip(['id', 'role', 'approved_at', 'external_clinical_account']))], ['id']);
        $definitions = $reads->query(DB::table('permissions')->whereIn('key', self::PERMISSIONS))->get(['id', 'key'])
            ->map(fn ($row) => (array) $row)->all();
        $this->expectedSet($guard, DB::table('permissions')->whereIn('key', self::PERMISSIONS), $definitions, ['id']);
        $permissionIds = array_map(fn ($row) => (int) $row['id'], $definitions);
        $roles = []; $assignments = []; $grants = []; $overrides = []; $usedDefinitions = [];
        foreach ($current->roles as $role) {
            $roles[] = ['id' => (int) $role->id, 'name' => $role->getRawOriginal('name')];
            $assignments[] = ['role_id' => (int) $role->id, 'user_id' => (int) $current->id];
            foreach ($role->permissions as $permission) {
                if (! in_array($permission->key, self::PERMISSIONS, true)) { continue; }
                $usedDefinitions[(int) $permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
                $grants[] = ['role_id' => (int) $role->id, 'permission_id' => (int) $permission->id];
            }
        }
        foreach ($current->permissionOverrides as $permission) {
            if (! in_array($permission->key, self::PERMISSIONS, true)) { continue; }
            $usedDefinitions[(int) $permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
            $overrides[] = ['permission_id' => (int) $permission->id, 'user_id' => (int) $current->id,
                'allowed' => (int) (bool) $permission->pivot->allowed];
        }
        // A definition renamed/deleted after actor preparation cannot become a new snapshot baseline.
        foreach ($usedDefinitions as $definition) {
            $guard->whereExists($this->exactRow(DB::table('permissions')->where('id', $definition['id']), $definition)->selectRaw('1'));
        }
        $roleIds = array_map(fn ($role) => $role['id'], $roles);
        $permissionIds = array_values(array_unique([...$permissionIds, ...array_keys($usedDefinitions)]));
        $this->expectedSet($guard, DB::table('role_user')->where('user_id', $current->id), $assignments, ['role_id', 'user_id']);
        $this->expectedSet($guard, DB::table('roles')->whereIn('id', $roleIds), $roles, ['id']);
        $this->expectedSet($guard, DB::table('role_permission')->whereIn('role_id', $roleIds)->whereIn('permission_id', $permissionIds), $grants, ['role_id', 'permission_id']);
        $this->expectedSet($guard, DB::table('permission_user')->where('user_id', $current->id)->whereIn('permission_id', $permissionIds), $overrides, ['permission_id', 'user_id']);
        $profileScope = DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at');
        $profileColumns = ['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at'];
        $profiles = $reads->query(clone $profileScope)->get($profileColumns)->map(fn ($row) => (array) $row)->all();
        $this->expectedSet($guard, $profileScope, $profiles, ['id']);
        $siteScope = DB::table('sites')->whereIn('id', array_values(array_unique([...$siteIds, ...($identity->site_id === null ? [] : [(int) $identity->site_id])])));
        $sites = $reads->query(clone $siteScope)->get(['id', 'is_active', 'archived', 'archived_at'])->map(fn ($row) => (array) $row)->all();
        $this->expectedSet($guard, $siteScope, $sites, ['id']);
        // Relevant assigned-Client audience only. Full ordinary source SQL still decides whether it is required.
        $membershipScope = DB::table('client_user')->where('user_id', $current->id)->where('client_id', $identity->client_id);
        $memberships = $reads->query(clone $membershipScope)->get(['id', 'client_id', 'user_id'])->map(fn ($row) => (array) $row)->all();
        $this->expectedSet($guard, $membershipScope, $memberships, ['id']);
        $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC'); $day = now($timezone)->toDateString();
        // Nested EXISTS compilation does not execute an embedded Builder's beforeQuery callbacks.
        // The expression keeps the ORIGINAL read lifetime, even if another viewer wraps it in new reads.
        $guard->whereRaw(new class($reads, $timezone, $day) extends Expression {
            public function __construct(private readonly CurrentAuthorizationReads $originalReads, private readonly string $timezone, private readonly string $day)
            {
                parent::__construct('1 = 1');
            }

            public function getValue(Grammar $grammar)
            {
                $this->originalReads->assertActive();
                if ((string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC') !== $this->timezone || now($this->timezone)->toDateString() !== $this->day) {
                    throw new LogicException('Response authorization date evidence changed during materialisation.');
                }
                return parent::getValue($grammar);
            }
        });
        return $reads->query($guard);
    }

    /** Bind every observed value and require the same complete scoped membership, including honest absence. */
    private function expectedSet(Builder $guard, QueryBuilder $selector, array $rows, array $identity): void
    {
        foreach ($rows as $row) { $guard->whereExists($this->exactRow(clone $selector, $row)->selectRaw('1')); }
        $extra = clone $selector;
        if ($rows !== []) {
            $extra->whereNot(function (QueryBuilder $known) use ($rows, $identity): void {
                foreach ($rows as $row) {
                    $known->orWhere(function (QueryBuilder $entry) use ($row, $identity): void {
                        $this->exactRow($entry, array_intersect_key($row, array_flip($identity)));
                    });
                }
            });
        }
        $guard->whereNotExists($extra->selectRaw('1'));
    }

    private function exactRow(QueryBuilder $query, array $values): QueryBuilder
    {
        foreach ($values as $column => $value) {
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            elseif ($value instanceof \DateTimeInterface) { $query->where($column, $value); }
            else { $query->where($column, $value); }
        }
        return $query;
    }

}
