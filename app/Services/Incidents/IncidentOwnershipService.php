<?php

namespace App\Services\Incidents;

use App\Jobs\DispatchIncidentOwnerDelivery;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HealthSafetySettingChange;
use App\Models\IncidentOwnerChange;
use App\Models\IncidentOwnerDelivery;
use App\Models\IncidentRoutingIntent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** The only writer for accountable incident ownership; other domain owners stay independent. */
final class IncidentOwnershipService
{
    public function __construct(private readonly IncidentOwnerEligibility $eligibility, private readonly AuthorizationEvidenceLockService $authorization) {}

    public function assign(User $actor, ClientIncident $incident, int $ownerId, int $expectedRevision, string $requestUuid, ?string $reason): IncidentOwnerChange
    {
        $reason = trim((string) $reason);
        Validator::make(['owner_user_id' => $ownerId, 'expected_revision' => $expectedRevision, 'request_uuid' => $requestUuid, 'reason' => $reason], [
            'owner_user_id' => ['required', 'integer', 'min:1'], 'expected_revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'request_uuid' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:4000'],
        ])->validate();
        $requestUuid = strtolower($requestUuid);
        // Preserve the original manual fingerprint vocabulary and attribution.
        $hash = hash('sha256', json_encode([$actor->id, $incident->id, $ownerId, $expectedRevision, $reason], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $incident, $ownerId, $expectedRevision, $requestUuid, $reason, $hash): IncidentOwnerChange {
                return CurrentAuthorizationReads::within(function ($reads) use ($actor, $incident, $ownerId, $expectedRevision, $requestUuid, $reason, $hash): IncidentOwnerChange {
                    $current = $this->authorization->lockForUserWithoutWaiting($actor, IncidentOwnerEligibility::PERMISSIONS);
                    $locked = $this->admittedIncident($current, (int) $incident->id, $reads, true);
                    abort_unless($this->eligibility->canManage($current, $locked, $reads), 403);
                    $existing = IncidentOwnerChange::query()->where('request_uuid', $requestUuid)->lockForUpdate()->first();
                    if ($existing) {
                        if ((int) $existing->incident_id !== (int) $locked->id || (int) $existing->actor_user_id !== (int) $current->id || ! hash_equals((string) $existing->request_hash, $hash)) {
                            IncidentRoutingValues::error('request_uuid', 'This request already belongs to different saved work.');
                        }
                        $this->assertAcceptance($existing);
                        return $existing;
                    }
                    abort_unless($this->eligibility->canAssign($current, $locked, $reads), 403);
                    if ((int) $locked->owner_revision !== $expectedRevision) {
                        IncidentRoutingValues::error('expected_revision', 'The incident owner changed. Review the current owner; your entries have been kept.');
                    }
                    if ((int) $locked->incident_owner_user_id === $ownerId) {
                        IncidentRoutingValues::error('owner_user_id', 'This person already owns the incident.');
                    }
                    if ($expectedRevision > 0 && $reason === '') {
                        IncidentRoutingValues::error('reason', 'Explain why incident ownership is changing.');
                    }
                    if (! $reads->query(User::query()->whereKey($ownerId))->exists()) {
                        IncidentRoutingValues::error('owner_user_id', 'Choose a currently eligible incident owner.');
                    }
                    $owner = $this->authorization->lockForUserWithoutWaiting($ownerId, IncidentOwnerEligibility::PERMISSIONS);
                    if (! $this->eligibility->canOwn($owner, $locked, $reads)) {
                        IncidentRoutingValues::error('owner_user_id', 'Choose a currently eligible incident owner.');
                    }
                    return $this->commit($locked, $owner, $current, $reason !== '' ? $reason : null, 'manual', null, $requestUuid, $hash, $reads);
                });
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                IncidentRoutingValues::error('expected_revision', 'Access or ownership is changing. Review current work; your entries have been kept.');
            }
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                IncidentRoutingValues::error('request_uuid', 'This request already belongs to saved work. Review its receipt.');
            }
            throw $exception;
        }
    }

    /** Internal configured-domain write, never reporter-derived assignment authority. Mutex precedes the incident lock. */
    public function commitConfigured(ClientIncident $incident, IncidentRoutingIntent $intent, int $ownerId, array $snapshot, string $route, CurrentAuthorizationReads $reads): IncidentOwnerChange
    {
        $reads->assertActive();
        AppSetting::query()->where('key', IncidentRoutingSettings::REVISION_KEY)->lock('for update nowait')->firstOrFail(['id']);
        $incident = ClientIncident::query()->whereKey($incident->id)->lock('for update nowait')->firstOrFail(IncidentRoutingService::SOURCE_COLUMNS);
        $retained = $reads->query(IncidentRoutingIntent::query()->whereKey($intent->id))->firstOrFail();
        $setting = $reads->query(AppSetting::query()->where('key', IncidentRoutingSettings::KEY))->first();
        $revision = $reads->query(AppSetting::query()->where('key', IncidentRoutingSettings::REVISION_KEY))->first();
        $history = $reads->query(HealthSafetySettingChange::query()->whereKey($snapshot['setting_change_id']))->firstOrFail();
        app(IncidentRoutingSettings::class)->assertHistory($history);
        if ($retained->kind !== 'initial' || (int) $retained->incident_id !== (int) $incident->id || $retained->owner_revision !== 0
            || $incident->incident_owner_user_id !== null || (int) $incident->owner_revision !== 0 || in_array($incident->status, ['draft', 'closed'], true)
            || ! hash_equals($retained->source_digest, IncidentRoutingValues::digest(IncidentRoutingService::tuple($incident)))
            || ! $setting || ! $revision || $revision->value !== $snapshot['revision'] || $history->revision !== $snapshot['revision']
            || IncidentRoutingValues::digest($setting->value) !== $snapshot['digest'] || $history->after_digest !== $snapshot['digest']) {
            IncidentRoutingValues::error('expected_revision', 'The configured routing source changed. Review the incident.');
        }
        $resolved = app(IncidentRoutingResolver::class)->resolve($incident, $snapshot, $reads, \Carbon\CarbonImmutable::instance(now('UTC')));
        if (! $resolved['monitored'] || $resolved['owner_user_id'] !== $ownerId || $resolved['route'] !== $route) {
            IncidentRoutingValues::error('owner_user_id', 'The configured recipient is no longer eligible.');
        }
        $owner = $this->authorization->lockForUserWithoutWaiting($ownerId, IncidentRoutingSettings::PERMISSIONS);
        abort_unless($this->eligibility->canOwn($owner, $incident, $reads), 403);
        $trigger = $retained->trigger_actor_id ? $reads->query(User::query()->whereKey($retained->trigger_actor_id))->first(['id', 'name']) : null;
        $hash = IncidentRoutingValues::digest(['intent_id' => (int) $retained->id, 'source_digest' => $retained->source_digest, 'configuration_digest' => $snapshot['digest'],
            'configuration_revision' => $snapshot['revision'], 'owner_user_id' => $ownerId, 'route' => $route]);
        return $this->commit($incident, $owner, $trigger, null, $route, (string) $snapshot['revision'], $retained->request_uuid, $hash, $reads);
    }

    public function assertAcceptance(IncidentOwnerChange $change, ?Builder $protectedSource = null, ?CurrentAuthorizationReads $reads = null): void
    {
        $boundary = app(IncidentTypedNotificationReadService::class);
        // Validate supplied callback evidence even on the honest legacy/no-audit branch.
        $boundary->constrain(AuditLog::query(), $protectedSource, $reads);
        if ($protectedSource !== null) { $protectedSource = (clone $protectedSource)->whereKey((int) $change->incident_id); }
        if ($change->audit_log_id === null) {
            // Honest compatibility for retained pre-contract manual changes; never a configured route certificate.
            if ($change->source !== 'manual' || (int) $change->source_schema_version !== 0
                || $boundary->constrain(AuditLog::query()->where('action', 'incidents.owner.manual')->where('auditable_type', (new ClientIncident)->getMorphClass())->where('auditable_id', $change->incident_id)->where('meta->request_uuid', $change->request_uuid)->lock('for share nowait'), $protectedSource, $reads)->exists()) {
                IncidentRoutingValues::error('ownership', 'The configured assignment evidence needs repair.');
            }
            if ($protectedSource !== null && ! $reads->query(clone $protectedSource)->exists()) {
                IncidentRoutingValues::error('ownership', 'The retained ownership source is unavailable.');
            }
            return;
        }
        $auditQuery = AuditLog::query()->whereKey($change->audit_log_id)->lock('for share nowait');
        if ($protectedSource !== null) {
            $auditQuery->whereRaw('BINARY `action` = BINARY ?', ['incidents.owner.'.$change->source])
                ->whereRaw('BINARY `auditable_type` = BINARY ?', [(new ClientIncident)->getMorphClass()])->where('auditable_id', $change->incident_id)
                ->when($change->actor_user_id === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $change->actor_user_id));
        }
        $audit = $boundary->constrain($auditQuery, $protectedSource, $reads)->first();
        if (! $audit || $audit->action !== 'incidents.owner.'.$change->source || $audit->auditable_type !== (new ClientIncident)->getMorphClass()
            || (int) $audit->auditable_id !== (int) $change->incident_id || $audit->user_id != $change->actor_user_id
            || IncidentRoutingValues::digest($audit->meta) !== IncidentRoutingValues::digest($this->auditMeta($change))) {
            IncidentRoutingValues::error('ownership', 'The retained ownership acceptance needs repair.');
        }
    }

    public function candidates(User $actor, ClientIncident $incident, string $search = ''): array
    {
        abort_unless(mb_strlen($search) <= 100, 422);
        return $this->withViewer($actor, function ($current, $reads) use ($incident, $search): array {
            $row = $this->admittedIncident($current, (int) $incident->id, $reads);
            abort_unless($this->eligibility->canAssign($current, $row, $reads), 403);
            $ids = $reads->query(User::query()->staff()->whereNotNull('approved_at')
                ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))->orderBy('name')->orderBy('id'))->pluck('id');
            $results = [];
            foreach ($ids as $id) {
                $candidate = $this->authorization->lockForUserWithoutWaiting((int) $id, IncidentOwnerEligibility::PERMISSIONS);
                if ($this->eligibility->canOwn($candidate, $row, $reads)) {
                    $results[] = ['id' => (int) $candidate->id, 'name' => $candidate->name];
                    if (count($results) === 30) {
                        break;
                    }
                }
            }
            return $results;
        });
    }

    public function summary(User $viewer, ClientIncident $incident): array
    {
        return $this->withViewer($viewer, fn ($current, $reads) => $this->summaryInReads($current, $this->admittedIncident($current, (int) $incident->id, $reads), $reads));
    }


    /** Same active read handle for register projection; the source is re-admitted before summary facts. */
    public function summaryInCurrentReads(User $viewer, ClientIncident $incident, CurrentAuthorizationReads $reads): array
    {
        $reads->assertActive();
        $current = $this->authorization->lockForUserWithoutWaiting($viewer, IncidentOwnerEligibility::PERMISSIONS);
        $result = $this->summaryInReads($current, $this->admittedIncident($current, (int) $incident->id, $reads), $reads);
        IncidentRoutingSettings::detached($result);
        return $result;
    }

    /** Additional copied-source admission before any owner/root body; old/default summary and all commands retain their contract. */
    public function summaryFromProtectedSourceInCurrentReads(User $viewer, ClientIncident $incident, CurrentAuthorizationReads $reads, Builder $protectedParents): ?array
    {
        $reads->assertActive();
        if (! $protectedParents->getModel() instanceof ClientIncident) { throw new LogicException('Incident owner summary requires a canonical incident parent query.'); }
        $current = $this->authorization->lockForUserWithoutWaiting($viewer, IncidentOwnerEligibility::PERMISSIONS);
        $one = IncidentIndependenceIntakeService::narrowedToIncident($protectedParents, (int) $incident->id, $reads) ?? (clone $protectedParents)->whereKey($incident->id);
        $qualified = app(IncidentAccessService::class)->apply($one, $current, $reads);
        $source = $reads->query(clone $qualified)->first(IncidentRoutingService::SOURCE_COLUMNS);
        if ($source === null) { return null; }
        $protectedFacts = app(IncidentTypedNotificationReadService::class)->identity(clone $qualified, $source, IncidentRoutingService::SOURCE_COLUMNS);
        $result = $this->summaryInReads($current, $source, $reads, $protectedFacts);
        $final = clone $qualified;
        foreach (IncidentRoutingService::SOURCE_COLUMNS as $column) {
            $value = $source->getRawOriginal($column);
            if ($value === null) { $final->whereNull('client_incidents.'.$column); }
            elseif (is_string($value)) { $final->whereRaw('BINARY `client_incidents`.`'.$column.'` = BINARY ?', [$value]); }
            else { $final->where('client_incidents.'.$column, $value); }
        }
        if (! $reads->query($final)->exists()) { return null; }
        IncidentRoutingSettings::detached($result);
        return $result;
    }

    public function present(User $viewer, ClientIncident $incident): array
    {
        return $this->withViewer($viewer, function ($current, $reads) use ($incident): array {
            $row = $this->admittedIncident($current, (int) $incident->id, $reads);
            $summary = $this->summaryInReads($current, $row, $reads);
            $changes = $reads->query(IncidentOwnerChange::query()->where('incident_id', $row->id)->orderByDesc('revision'))->get();
            foreach ($changes as $change) { $this->assertAcceptance($change); }
            $deliveries = $reads->query(IncidentOwnerDelivery::query()->whereIn('change_id', $changes->pluck('id')))->get()->groupBy('change_id');
            return $summary + ['last_owner_name' => $changes->first()?->owner_name, 'history' => $changes->map(fn ($change) => [
                'id' => $change->id, 'revision' => $change->revision,
                'request_uuid' => $change->request_uuid, 'owner_user_id' => (int) $change->owner_user_id,
                'previous_owner' => $change->previous_owner_name, 'owner' => $change->owner_name,
                'changed_by' => $change->actor_name, 'changed_at' => $change->created_at->toIso8601String(),
                'reason' => $change->reason, 'source' => $change->source, 'routing_version' => $change->routing_version,
                'deliveries' => ($deliveries[$change->id] ?? collect())->map(fn ($delivery) => ['id' => $delivery->id, 'role' => $delivery->recipient_role, 'status' => $delivery->status])->all(),
            ])->all()];
        });
    }

    /** Filter BEFORE parent counts/pagination, inside the same active current actor callback. */
    public function applyOwnerFilter(Builder $query, User $current, string $filter, CurrentAuthorizationReads $reads): Builder
    {
        $reads->assertActive();
        if (! $query->getModel() instanceof ClientIncident || ! in_array($filter, ['me', 'needs_owner'], true)) {
            throw new LogicException('Ownership filters require an independent canonical incident query and supported filter.');
        }
        $current = $this->authorization->lockForUserWithoutWaiting($current, IncidentOwnerEligibility::PERMISSIONS);
        abort_unless($current->canDo('incidents.viewAny') || $current->canDo('incidents.viewAssigned'), 403);
        $query = app(IncidentAccessService::class)->apply($query, $current, $reads);
        $candidates = (clone $query)->withoutEagerLoads()->reorder();
        $candidates->getQuery()->limit = null; $candidates->getQuery()->offset = null;
        $rows = $reads->query($candidates->select(array_map(fn ($column) => 'client_incidents.'.$column, IncidentRoutingService::SOURCE_COLUMNS)))->get();
        $bindings = [];
        foreach ($rows as $row) {
            $summary = $this->summaryInReads($current, $row, $reads);
            if (($filter === 'needs_owner' && in_array($summary['state'], ['unassigned', 'replacement_needed'], true) && $row->status !== 'draft')
                || ($filter === 'me' && (int) $row->incident_owner_user_id === (int) $current->id && in_array($summary['state'], ['assigned', 'historical'], true)
                    && ($row->status !== 'closed' || $this->hasRetainedOwner($row, $reads)))) {
                $binding = [];
                foreach (IncidentRoutingService::SOURCE_COLUMNS as $column) { $binding[$column] = $row->getRawOriginal($column); }
                $bindings[] = $binding;
            }
        }
        // Current reads hold exact source/user/HR/conflict evidence through final count/search/hydration.
        // Executing the returned builder after this callback expires is an error, never a stale grant.
        if ($bindings === []) { return $reads->query($query->whereRaw('1 = 0')); }
        return $reads->query($query->where(function (Builder $qualified) use ($bindings, $filter, $current): void {
            foreach ($bindings as $binding) {
                $qualified->orWhere(function (Builder $exact) use ($binding, $filter, $current): void {
                    foreach ($binding as $column => $value) {
                        if ($value === null) { $exact->whereNull('client_incidents.'.$column); }
                        elseif (in_array($column, ['type', 'status'], true)) { $exact->whereRaw('BINARY client_incidents.'.$column.' = BINARY ?', [$value]); }
                        else { $exact->where('client_incidents.'.$column, $value); }
                    }
                    if ($filter === 'me') { $exact->where('client_incidents.incident_owner_user_id', $current->id); }
                });
            }
        }));
    }
    public function withViewer(User $viewer, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($viewer, $operation): mixed {
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, IncidentRoutingSettings::PERMISSIONS);
            $result = $operation($current, $reads);
            IncidentRoutingSettings::detached($result);
            return $result;
        }));
    }

    public function admittedIncident(User $current, int $id, CurrentAuthorizationReads $reads, bool $write = false): ClientIncident
    {
        $query = app(IncidentAccessService::class)->apply(ClientIncident::query(), $current, $reads)->whereKey($id);
        if ($write) {
            // Last callback overrides only the outer lock, retaining current locks on nested access predicates.
            $query->getQuery()->beforeQuery(fn ($query) => $query->lock('for update nowait'));
        }
        return $query->firstOrFail();
    }

    /** Same active-read proof; a raw owner pointer or damaged retained change is replacement work. */
    public function hasCurrentOwner(ClientIncident $incident, CurrentAuthorizationReads $reads, ?Builder $protectedSource = null): bool
    {
        $reads->assertActive();
        if ($protectedSource !== null) { app(IncidentTypedNotificationReadService::class)->constrain(User::query(), $protectedSource, $reads); }
        if (! $incident->incident_owner_user_id || ! $reads->query(User::query()->whereKey($incident->incident_owner_user_id))->exists()) { return false; }
        $owner = $this->authorization->lockForUserWithoutWaiting((int) $incident->incident_owner_user_id, IncidentOwnerEligibility::PERMISSIONS);
        if (! $this->eligibility->canOwn($owner, $incident, $reads)) { return false; }
        return $this->hasRetainedOwner($incident, $reads, $protectedSource);
    }

    /** Closed associations need retained matching history; present eligibility does not rewrite past ownership. */
    private function hasRetainedOwner(ClientIncident $incident, CurrentAuthorizationReads $reads, ?Builder $protectedSource = null): bool
    {
        $reads->assertActive();
        if (! $incident->incident_owner_user_id) { return false; }
        $latest = $reads->query(app(IncidentTypedNotificationReadService::class)->constrain(IncidentOwnerChange::query()->where('incident_id', $incident->id)->orderByDesc('revision'), $protectedSource, $protectedSource === null ? null : $reads))->first();
        if (! $latest || (int) $latest->revision !== (int) $incident->owner_revision || (int) $latest->owner_user_id !== (int) $incident->incident_owner_user_id) { return false; }
        try { $this->assertAcceptance($latest, $protectedSource, $protectedSource === null ? null : $reads); }
        catch (\Illuminate\Validation\ValidationException $error) {
            if (array_keys($error->errors()) !== ['ownership']) { throw $error; }
            return false;
        }
        return true;
    }

    private function summaryInReads(User $viewer, ClientIncident $incident, CurrentAuthorizationReads $reads, ?Builder $protectedSource = null): array
    {
        $owner = $incident->incident_owner_user_id ? $reads->query(app(IncidentTypedNotificationReadService::class)->constrain(User::query()->whereKey($incident->incident_owner_user_id), $protectedSource, $protectedSource === null ? null : $reads))->first(['id', 'name']) : null;
        $eligible = $this->hasCurrentOwner($incident, $reads, $protectedSource);
        $state = $incident->status === 'closed' ? 'historical' : ($eligible ? 'assigned' : ((int) $incident->owner_revision > 0 || $incident->incident_owner_user_id !== null ? 'replacement_needed' : 'unassigned'));
        return ['revision' => (int) $incident->owner_revision, 'state' => $state,
            'owner' => $owner ? ['id' => (int) $owner->id, 'name' => $owner->name] : null,
            'can_assign' => $this->eligibility->canAssign($viewer, $incident, $reads), 'can_retry_delivery' => $this->eligibility->canManage($viewer, $incident, $reads)];
    }

    private function commit(ClientIncident $incident, User $owner, ?User $actor, ?string $reason, string $source, ?string $routingVersion, string $uuid, string $hash, CurrentAuthorizationReads $reads): IncidentOwnerChange
    {
        $previous = $incident->incident_owner_user_id ? $reads->query(User::query()->whereKey($incident->incident_owner_user_id))->first(['id', 'name']) : null;
        $change = new IncidentOwnerChange([
            'incident_id' => $incident->id, 'revision' => (int) $incident->owner_revision + 1, 'previous_owner_user_id' => $previous?->id, 'previous_owner_name' => $previous?->name,
            'owner_user_id' => $owner->id, 'owner_name' => $owner->name, 'actor_user_id' => $actor?->id, 'actor_name' => $actor?->name ?? '',
            'reason' => $reason, 'source' => $source, 'source_schema_version' => 1, 'routing_version' => $routingVersion, 'request_uuid' => $uuid, 'request_hash' => $hash, 'created_at' => now()->startOfSecond(),
        ]);
        $request = clone request();
        $request->setUserResolver(fn () => $actor);
        AuditLogger::logOrFail('incidents.owner.'.$source, $incident, $this->auditMeta($change), $request, $actor === null);
        $audits = AuditLog::query()->where('action', 'incidents.owner.'.$source)->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)->where('meta->request_uuid', $uuid)->lockForUpdate()->get();
        if ($audits->count() !== 1 || IncidentRoutingValues::digest($audits->sole()->meta) !== IncidentRoutingValues::digest($this->auditMeta($change))) {
            throw new LogicException('Ownership requires exactly one persisted acceptance audit.');
        }
        $change->audit_log_id = $audits->sole()->id;
        IncidentRoutingSettings::saved($change);
        $incident->forceFill(['incident_owner_user_id' => $owner->id, 'owner_revision' => $change->revision]);
        IncidentRoutingSettings::saved($incident);
        $this->assertAcceptance($change);
        foreach (['incoming' => $owner->id, 'outgoing' => $previous?->id] as $role => $recipient) {
            if (! $recipient) {
                continue;
            }
            $delivery = new IncidentOwnerDelivery(['id' => (string) Str::uuid(), 'change_id' => $change->id, 'recipient_user_id' => $recipient, 'recipient_role' => $role, 'status' => 'pending']);
            IncidentRoutingSettings::saved($delivery);
            DB::afterCommit(function () use ($delivery): void {
                try {
                    DispatchIncidentOwnerDelivery::dispatch($delivery->id);
                } catch (Throwable) {
                    IncidentOwnerDelivery::query()->whereKey($delivery->id)->where('status', 'pending')->update(['status' => 'failed', 'failure_code' => 'dispatch_failed', 'updated_at' => now()]);
                }
            });
        }
        return $change;
    }

    private function auditMeta(IncidentOwnerChange $change): array
    {
        return ['request_uuid' => $change->request_uuid, 'request_hash' => $change->request_hash, 'incident_id' => (int) $change->incident_id,
            'revision' => (int) $change->revision, 'owner_user_id' => (int) $change->owner_user_id, 'actor_id' => $change->actor_user_id === null ? null : (int) $change->actor_user_id,
            'source' => $change->source, 'routing_version' => $change->routing_version,
            'history_digest' => IncidentRoutingValues::digest([
                'incident_id' => (int) $change->incident_id, 'revision' => (int) $change->revision, 'previous_owner_user_id' => $change->previous_owner_user_id === null ? null : (int) $change->previous_owner_user_id,
                'previous_owner_name' => $change->previous_owner_name, 'owner_user_id' => (int) $change->owner_user_id, 'owner_name' => $change->owner_name,
                'actor_user_id' => $change->actor_user_id === null ? null : (int) $change->actor_user_id, 'actor_name' => $change->actor_name, 'reason' => $change->reason,
                'source' => $change->source, 'routing_version' => $change->routing_version, 'source_schema_version' => (int) $change->source_schema_version,
                'created_at' => $change->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ])];
    }
}
