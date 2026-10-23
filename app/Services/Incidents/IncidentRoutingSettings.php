<?php

namespace App\Services\Incidents;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Separate organisational routing key; neither BSP policy nor an incident read grant. */
final class IncidentRoutingSettings
{
    public const KEY = 'healthSafety.incidents.owner_routing';
    public const REVISION_KEY = 'healthSafety.incidents.owner_routing_revision';
    public const PERMISSION = 'healthSafety.settings.manage';
    public const PERMISSIONS = [...IncidentOwnerEligibility::PERMISSIONS, self::PERMISSION];

    public function __construct(private readonly AuthorizationEvidenceLockService $authorization, private readonly IncidentOwnerEligibility $eligibility, private readonly UserSiteAccessService $sites) {}

    /** Call before incident locks; the default has no recipient and no invented interval. */
    public function withLockedConfiguration(callable $operation, ?User $manager = null, ?callable $beforeRead = null): mixed
    {
        return DB::transaction(function () use ($operation, $manager, $beforeRead): mixed {
            AppSetting::query()->insertOrIgnore(['key' => self::REVISION_KEY, 'value' => json_encode(0), 'created_at' => now(), 'updated_at' => now()]);
            $mutex = AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->firstOrFail();
            if ($manager) { CurrentAuthorizationReads::within(fn ($reads) => $this->current($manager, $reads)); }
            if ($beforeRead) { $beforeRead(); }
            $result = $operation($this->snapshot($mutex));
            self::detached($result);
            return $result;
        }, 3);
    }


    /** Typed delivery needs the governing mutex, not an unrelated/current configuration snapshot before its source gate. */
    public function withLockedConfigurationMutex(callable $operation, ?callable $beforeRead = null): mixed
    {
        return DB::transaction(function () use ($operation, $beforeRead): mixed {
            AppSetting::query()->insertOrIgnore(['key' => self::REVISION_KEY, 'value' => json_encode(0), 'created_at' => now(), 'updated_at' => now()]);
            AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->firstOrFail(['id', 'key']);
            if ($beforeRead) { $beforeRead(); }
            $result = $operation();
            self::detached($result);
            return $result;
        }, 3);
    }

    public function current(User $actor, CurrentAuthorizationReads $reads): User
    {
        $reads->assertActive();
        $current = $this->authorization->lockForUserWithoutWaiting($actor, self::PERMISSIONS);
        abort_unless($current->canDo(self::PERMISSION) && $this->eligibility->activeInternal($current, $reads), 403);
        return $current;
    }

    public function editor(User $actor, int $page = 1): array
    {
        return $this->withLockedConfiguration(fn ($snapshot) => CurrentAuthorizationReads::within(function ($reads) use ($actor, $snapshot, $page): array {
            $current = $this->current($actor, $reads);
            $siteIds = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            $history = $reads->query(HealthSafetySettingChange::query()->where('setting_key', self::KEY)->orderByDesc('revision'))
                ->paginate(25, ['*'], 'routing_page', max(1, $page));
            $visibleSnapshot = $snapshot;
            if ($visibleSnapshot['configuration'] !== null) { $visibleSnapshot['configuration'] = $this->scopedConfiguration($visibleSnapshot['configuration'], $siteIds, $reads); }
            $history->setCollection($history->getCollection()->map(fn ($row) => $this->historyProjection($row, $siteIds, $reads)));
            return ['routing_configuration' => $visibleSnapshot, 'routing_history' => $history->toArray(), 'can_manage_routing' => true,
                'sites' => $reads->query(Site::query()->whereIn('id', $siteIds)->orderBy('name'))->get(['id', 'name'])->map(fn ($site) => ['id' => (int) $site->id, 'name' => $site->name])->all()];
        }), $actor);
    }

    /** Bounded configuration references only, not an incident/body/HR directory endpoint. */
    public function candidates(User $actor, int $siteId, string $scope = 'ordinary', string $search = '', ?int $selectedId = null): array
    {
        abort_unless(in_array($scope, ['ordinary', 'restricted', 'triage'], true) && mb_strlen($search) <= 100 && ($selectedId === null || ($selectedId > 0 && $selectedId <= 2147483647)), 422);
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $siteId, $scope, $search, $selectedId): array {
            $current = $this->current($actor, $reads);
            $this->assertSite($current, $siteId, $reads);
            $references = $this->referenceQuery([$siteId], $search);
            if ($selectedId !== null) { $references->whereKey($selectedId); }
            $ids = $reads->query($references)->pluck('id');
            $options = [];
            foreach ($ids as $id) {
                $candidate = $this->authorization->lockForUserWithoutWaiting((int) $id, self::PERMISSIONS);
                if ($this->eligibleReference($candidate, $siteId, $scope, $reads)) {
                    $options[] = ['id' => (int) $candidate->id, 'name' => $candidate->name];
                    if (count($options) === 30) {
                        break;
                    }
                }
            }
            return $options;
        }));
    }

    public function save(User $actor, array $input): array
    {
        $values = IncidentRoutingValues::command($input);
        return $this->withLockedConfiguration(fn ($before) => CurrentAuthorizationReads::within(function ($reads) use ($actor, $values, $before): array {
            $current = $this->current($actor, $reads);
            $siteIds = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            foreach ($values['configuration']['routes'] as $route) { $this->assertSite($current, $route['site_id'], $reads); }
            $fingerprint = IncidentRoutingValues::digest(['actor_id' => (int) $current->id, 'setting_key' => self::KEY, 'command' => 'save_incident_routing', 'values' => $values]);
            $existing = HealthSafetySettingChange::query()->where('command_key', $values['request_uuid'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->setting_key !== self::KEY || (int) $existing->actor_id !== (int) $current->id || ! hash_equals((string) $existing->fingerprint, $fingerprint)) {
                    IncidentRoutingValues::error('request_uuid', 'This command identity already belongs to different saved work.');
                }
                $this->assertHistory($existing);
                return $existing->accepted_result;
            }
            if ($before['revision'] === null || $before['revision'] !== $values['expected_revision'] || $before['revision'] >= 2147483646) {
                IncidentRoutingValues::error('expected_revision', 'The routing configuration changed or needs repair. Review current settings; your entries are retained.');
            }
            foreach ($values['configuration']['routes'] as $index => $route) {
                $this->assertSite($current, $route['site_id'], $reads);
                foreach (['lead_user_id', 'cover_user_id'] as $field) {
                    if ($route[$field] !== null) {
                        $this->assertReference($route[$field], $route['site_id'], $route['scope'], $reads, 'configuration.routes.'.$index.'.'.$field, $siteIds);
                    }
                }
            }
            if ($values['triage_supplied'] && ($oldTriage = $before['configuration']['triage'] ?? null) !== null
                && ! $this->triageSelectable($oldTriage, $siteIds, $reads) && ! $current->canDo('healthSafety.viewAllSites')) {
                IncidentRoutingValues::error('configuration.triage', 'The saved recipient references are restricted. Omit triage to preserve them, or obtain independently authorised organisation Site-scope review.');
            }
            if ($values['triage_supplied'] && ($triage = $values['configuration']['triage'])) {
                foreach (['user_id', 'escalation_user_id'] as $field) {
                    $this->assertReference($triage[$field], null, 'triage', $reads, 'configuration.triage.'.$field, $siteIds);
                }
            }
            $revision = $before['revision'] + 1;
            $config = $values['configuration'];
            if (! $values['triage_supplied'] && $before['configuration'] !== null) { $config['triage'] = $before['configuration']['triage']; }
            $siteIds = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            if ($before['configuration'] !== null) {
                $outside = array_values(array_filter($before['configuration']['routes'], fn ($rule) => ! in_array($rule['site_id'], $siteIds, true)));
                $config['routes'] = [...$config['routes'], ...$outside];
                $config = IncidentRoutingValues::configuration($config);
            } elseif ($before['state'] === 'repair_required' && ! $current->canDo('healthSafety.viewAllSites')) {
                IncidentRoutingValues::error('configuration', 'The original routing scope cannot be proved. An authorised organisation settings and Site-scope repair is required; the saved configuration is retained.');
            }
            $digest = IncidentRoutingValues::digest($config);
            $action = $before['configuration_valid'] && $before['digest'] === $digest ? 'kept' : 'changed';
            $setting = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->first() ?? new AppSetting(['key' => self::KEY]);
            $rawBefore = $setting->value;
            $setting->value = $config;
            self::saved($setting);
            $mutex = AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->firstOrFail();
            $mutex->value = $revision;
            self::saved($mutex);
            $result = ['accepted' => true, 'request_uuid' => $values['request_uuid'], 'setting_key' => self::KEY,
                'revision' => $revision, 'configuration_digest' => $digest, 'action' => $action, 'actor_id' => (int) $current->id];
            $recordedAt = now('UTC')->toImmutable();
            $actorSnapshot = ['id' => (int) $current->id, 'name' => $current->name];
            $request = clone request();
            $request->setUserResolver(fn () => $current);
            $auditMeta = $result + ['fingerprint' => $fingerprint, 'reason_digest' => IncidentRoutingValues::digest($values['reason']), 'before_digest' => $rawBefore === null ? null : IncidentRoutingValues::digest($rawBefore), 'actor_snapshot_digest' => IncidentRoutingValues::digest($actorSnapshot), 'recorded_at' => $recordedAt->format('Y-m-d\TH:i:s.u\Z')];
            AuditLogger::logOrFail('incidents.routing.settings.'.$action, $mutex, $auditMeta, $request);
            $audits = AuditLog::query()->where('user_id', $current->id)->where('action', 'incidents.routing.settings.'.$action)
                ->where('auditable_type', $mutex->getMorphClass())->where('auditable_id', $mutex->id)->where('meta->request_uuid', $values['request_uuid'])->lockForUpdate()->get();
            if ($audits->count() !== 1 || IncidentRoutingValues::digest($audits->sole()->meta) !== IncidentRoutingValues::digest($auditMeta)) {
                throw new LogicException('Routing configuration requires an exact persisted audit.');
            }
            $row = new HealthSafetySettingChange([
                'setting_key' => self::KEY, 'revision' => $revision, 'schema_version' => 1, 'action' => $action, 'origin' => 'organisation_setting',
                'before_value' => $rawBefore, 'after_value' => $config, 'before_digest' => $rawBefore === null ? null : IncidentRoutingValues::digest($rawBefore), 'after_digest' => $digest,
                'actor_id' => $current->id, 'actor_snapshot' => $actorSnapshot, 'recorded_at' => $recordedAt, 'reason' => $values['reason'],
                'audit_id' => $audits->sole()->id, 'command_key' => $values['request_uuid'], 'fingerprint' => $fingerprint, 'accepted_result' => $result,
            ]);
            self::saved($row);
            $this->assertHistory($row);
            return $result;
        }), $actor);
    }

    public function receiptForManager(User $actor, string $uuid): ?array
    {
        if (! \Illuminate\Support\Str::isUuid($uuid)) {
            return null;
        }
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $uuid): ?array {
            $current = $this->current($actor, $reads);
            $row = $reads->query(HealthSafetySettingChange::query()->where('setting_key', self::KEY)->where('actor_id', $current->id)->where('command_key', strtolower($uuid)))->first();
            if (! $row) {
                return null;
            }
            $this->assertHistory($row);
            return $row->accepted_result;
        }));
    }

    public function assertHistory(HealthSafetySettingChange $row, ?\Illuminate\Database\Eloquent\Builder $protectedSource = null, ?CurrentAuthorizationReads $reads = null): void
    {
        $boundary = app(IncidentTypedNotificationReadService::class);
        $boundary->constrain(AuditLog::query(), $protectedSource, $reads);
        if ($protectedSource !== null && $row->setting_key !== self::KEY) { IncidentRoutingValues::error('configuration', 'The retained configuration belongs to another setting.'); }
        if (! is_array($row->after_value)) { IncidentRoutingValues::error('configuration', 'The retained configuration shape needs repair.'); }
        $normalized = IncidentRoutingValues::configuration($row->after_value);
        if (IncidentRoutingValues::digest($normalized) !== IncidentRoutingValues::digest($row->after_value)) { IncidentRoutingValues::error('configuration', 'The retained configuration types need repair.'); }
        $result = $row->accepted_result;
        $auditQuery = AuditLog::query()->whereKey($row->audit_id)->lock('for share nowait');
        $mutexQuery = AppSetting::query()->where('key', self::REVISION_KEY)->lock('for share nowait');
        if ($protectedSource !== null) {
            $mutex = $boundary->constrain($mutexQuery, $protectedSource, $reads)->first(['id']);
            $auditQuery->whereRaw('BINARY `action` = BINARY ?', ['incidents.routing.settings.'.$row->action])
                ->whereRaw('BINARY `auditable_type` = BINARY ?', [(new AppSetting)->getMorphClass()])->where('auditable_id', $mutex?->id ?? 0)
                ->where('user_id', $row->actor_id);
            $audit = $boundary->constrain($auditQuery, $protectedSource, $reads)->first();
        } else {
            // Preserve the default verifier's existing query order and authority.
            $audit = $auditQuery->first(); $mutex = $mutexQuery->first(['id']);
        }
        if ($row->setting_key !== self::KEY || $row->schema_version !== 1 || $row->revision < 1 || ! is_array($row->after_value)
            || ! hash_equals((string) $row->after_digest, IncidentRoutingValues::digest($row->after_value)) || ! is_array($result)
            || IncidentRoutingValues::digest($result) !== IncidentRoutingValues::digest([
                'accepted' => true, 'request_uuid' => $row->command_key, 'setting_key' => self::KEY, 'revision' => $row->revision,
                'configuration_digest' => $row->after_digest, 'action' => $row->action, 'actor_id' => (int) $row->actor_id,
            ]) || ! $audit || ! $mutex || (int) $audit->user_id !== (int) $row->actor_id
            || $audit->action !== 'incidents.routing.settings.'.$row->action || $audit->auditable_type !== (new AppSetting)->getMorphClass()
            || (int) $audit->auditable_id !== (int) $mutex->id || IncidentRoutingValues::digest($audit->meta) !== IncidentRoutingValues::digest($result + ['fingerprint' => $row->fingerprint, 'reason_digest' => IncidentRoutingValues::digest($row->reason), 'before_digest' => $row->before_digest, 'actor_snapshot_digest' => IncidentRoutingValues::digest($row->actor_snapshot), 'recorded_at' => $row->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z')])
            || ($row->before_value === null ? null : IncidentRoutingValues::digest($row->before_value)) !== $row->before_digest) {
            IncidentRoutingValues::error('configuration', 'The retained routing configuration needs an authorised integrity review.');
        }
    }

    private function snapshot(AppSetting $mutex): array
    {
        $setting = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->first();
        $revision = $mutex->value;
        $history = HealthSafetySettingChange::query()->where('setting_key', self::KEY)->orderByDesc('revision')->lockForUpdate()->first();
        $validRevision = is_int($revision) && $revision >= 0 && $revision <= 2147483647 && (! $history || $history->revision === $revision);
        $configuration = null;
        $valid = false;
        if ($setting && $validRevision && $history) {
            try {
                $configuration = IncidentRoutingValues::configuration($setting->value);
                $this->assertHistory($history);
                $valid = $history->revision === $revision && IncidentRoutingValues::digest($configuration) === $history->after_digest;
            } catch (\Illuminate\Validation\ValidationException | \TypeError) {
                $configuration = null;
            }
        }
        $unconfigured = ! $setting && ! $history && $revision === 0;
        return ['setting_key' => self::KEY, 'revision' => $validRevision ? $revision : null,
            'configuration_valid' => $valid, 'state' => $valid ? 'configured' : ($unconfigured ? 'unconfigured' : 'repair_required'),
            'configuration' => $valid ? $configuration : null, 'digest' => $valid ? $history->after_digest : null,
            'setting_change_id' => $valid ? (int) $history->id : null, 'recorded_at' => $valid ? $history->recorded_at->toIso8601String() : null];
    }

    private function historyProjection(HealthSafetySettingChange $row, array $sites, CurrentAuthorizationReads $reads): array
    {
        try { $this->assertHistory($row); }
        catch (\Illuminate\Validation\ValidationException) {
            return ['id' => (int) $row->id, 'revision' => $row->revision, 'integrity' => 'repair_required', 'action' => null, 'configuration' => null, 'reason' => null, 'actor' => null, 'recorded_at' => null];
        }
        return ['id' => (int) $row->id, 'revision' => $row->revision, 'integrity' => 'recorded', 'action' => $row->action,
            'configuration' => $this->scopedConfiguration($row->after_value, $sites, $reads), 'reason' => $row->reason,
            'actor' => $row->actor_snapshot, 'recorded_at' => $row->recorded_at->toIso8601String()];
    }
    /** Redacted editor contract, never a storage configuration or a completeness certificate. */
    private function scopedConfiguration(array $configuration, array $sites, CurrentAuthorizationReads $reads): array
    {
        $triage = $configuration['triage'];
        $visible = $triage === null || $this->triageSelectable($triage, $sites, $reads);
        $names = [];
        if ($visible && $triage !== null) {
            foreach (['user' => 'user_id', 'escalation' => 'escalation_user_id'] as $label => $key) {
                // Existing scoped eligibility admits the references; repeat the exact roster predicate before names.
                $person = $reads->query($this->referenceQuery($sites)->whereKey($triage[$key]))->first(['id', 'name']);
                if (! $person) { $visible = false; break; }
                $names[$label] = $person->name;
            }
        }
        $projection = $visible ? ['visibility' => 'readable', 'state' => $triage === null ? 'not_configured' : 'configured', 'values' => $triage]
            : ['visibility' => 'restricted', 'state' => 'configured'];
        if ($visible && $triage !== null) { $projection['display_names'] = $names; }
        return ['schema_version' => $configuration['schema_version'],
            'routes' => array_values(array_filter($configuration['routes'], fn ($route) => in_array($route['site_id'], $sites, true))),
            'triage' => $projection];
    }

    private function triageSelectable(array $triage, array $sites, CurrentAuthorizationReads $reads): bool
    {
        foreach (['user_id', 'escalation_user_id'] as $key) {
            if (! $reads->query($this->referenceQuery($sites)->whereKey($triage[$key]))->exists()) { return false; }
            $user = $this->authorization->lockForUserWithoutWaiting($triage[$key], self::PERMISSIONS);
            if (! $this->eligibleReference($user, null, 'triage', $reads)) { return false; }
        }
        return true;
    }

    /** Capture-only reference IDs; callers already prove the exact Client/own Shift before this call. */
    public function ordinaryCaptureReferenceIds(User $current, int $siteId, CurrentAuthorizationReads $reads): array
    {
        $reads->assertActive();
        abort_unless($current->canDo('incidents.create') && $this->eligibility->activeInternal($current, $reads), 403);
        abort_unless(in_array($siteId, $this->sites->accessibleSiteIds($current, IncidentIndependenceCaptureAccess::SITE_BYPASS, $reads), true), 404);
        $reads->query(Site::query()->whereKey($siteId)->where('is_active', true))->firstOrFail(['id']);
        // Shared NOWAIT reads preserve the policy-writer lock order without taking
        // an exclusive routing mutex after capture actor/Site evidence.
        $mutex = $reads->query(AppSetting::query()->where('key', self::REVISION_KEY))->first();
        if (! $mutex || ! is_int($mutex->value) || $mutex->value < 1) { return []; }
        $setting = $reads->query(AppSetting::query()->where('key', self::KEY))->first();
        $history = $reads->query(HealthSafetySettingChange::query()->where('setting_key', self::KEY)->orderByDesc('revision'))->first();
        if (! $setting || ! is_array($setting->value) || ! $history || $mutex->value !== $history->revision) { return []; }
        try { $this->assertHistory($history); }
        catch (\Illuminate\Validation\ValidationException $error) {
            foreach (array_keys($error->errors()) as $field) {
                if ($field !== 'configuration' && ! str_starts_with($field, 'configuration.')) { throw $error; }
            }
            return [];
        }
        if (IncidentRoutingValues::digest($setting->value) !== $history->after_digest) { return []; }
        $at = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
        $ids = [];
        foreach ($history->after_value['routes'] as $route) {
            if ($route['site_id'] !== $siteId || $route['scope'] !== 'ordinary' || $route['effective_from'] > $at
                || ($route['effective_until'] !== null && $route['effective_until'] <= $at)) { continue; }
            $ids[] = $route['lead_user_id'];
            if ($route['cover_user_id'] !== null && $route['cover_from'] <= $at && $route['cover_until'] > $at) { $ids[] = $route['cover_user_id']; }
        }
        if ($ids === []) { return []; }
        // Nomination is a contextual reference, not owner qualification or a
        // global directory. Current staff and the existing Site reference rule
        // still admit each ID before the capture caller can load its name.
        return $reads->query($this->referenceQuery([$siteId])->whereIn('users.id', array_unique($ids)))
            ->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Exact approved roster discovery BEFORE names or full authorization subjects are hydrated. */
    private function referenceQuery(array $sites, string $search = ''): \Illuminate\Database\Eloquent\Builder
    {
        $query = app(\App\Domain\Hr\Services\HrCurrentStaffService::class)->currentUsersQuery();
        if ($sites === []) { return $query->whereRaw('1 = 0'); }
        return $query->where(function ($roster) use ($sites): void {
            $roster->whereHas('hrEmployeeProfile', fn ($profile) => $profile->where(function ($assigned) use ($sites): void {
                $assigned->whereIn('primary_site_id', $sites);
                foreach ($sites as $site) { $assigned->orWhereJsonContains('secondary_site_ids', $site); }
            }))->orWhere(function ($bypass): void {
                $bypass->whereDoesntHave('permissionOverrides', fn ($p) => $p->where('permissions.key', 'healthSafety.viewAllSites')->where('permission_user.allowed', false))
                    ->where(fn ($grants) => $grants->whereHas('permissionOverrides', fn ($p) => $p->where('permissions.key', 'healthSafety.viewAllSites')->where('permission_user.allowed', true))
                        ->orWhereHas('roles.permissions', fn ($p) => $p->where('permissions.key', 'healthSafety.viewAllSites')));
            });
        })->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))->orderBy('name')->orderBy('id');
    }
    private function assertSite(User $actor, int $id, CurrentAuthorizationReads $reads): void
    {
        abort_unless(in_array($id, $this->sites->accessibleSiteIds($actor, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads), true), 404);
    }

    private function assertReference(int $id, ?int $site, string $scope, CurrentAuthorizationReads $reads, string $field, array $actorSites): void
    {
        if (! $reads->query($this->referenceQuery($site === null ? $actorSites : [$site])->whereKey($id))->exists()) {
            IncidentRoutingValues::error($field, 'Choose a currently eligible internal recipient.');
        }
        $user = $this->authorization->lockForUserWithoutWaiting($id, self::PERMISSIONS);
        if (! $this->eligibleReference($user, $site, $scope, $reads)) {
            IncidentRoutingValues::error($field, 'Choose a currently eligible internal recipient.');
        }
    }

    private function eligibleReference(User $user, ?int $site, string $scope, CurrentAuthorizationReads $reads): bool
    {
        return $this->eligibility->activeInternal($user, $reads) && $user->canDo('incidents.owner.hold')
            && ($user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned'))
            && ($scope !== 'restricted' || $user->canDo('incidents.owner.holdRestricted'))
            && ($scope !== 'triage' || $user->canDo('incidents.owner.assign'))
            && ($site === null || in_array($site, $this->sites->accessibleSiteIds($user, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads), true));
    }

    public static function detached(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $item) { self::detached($item); } return; }
        if ($value !== null && ! is_scalar($value)) { throw new LogicException('Routing callbacks may return detached values only.'); }
    }
    public static function saved(\Illuminate\Database\Eloquent\Model $model): void
    {
        if (! $model->save() || ! $model->exists || ! $model->getKey()) {
            throw new LogicException('The incident routing write was refused; no acceptance was saved.');
        }
    }
}
