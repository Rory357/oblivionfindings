<?php

namespace App\Services\Incidents;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Independent policy key; configuration grants neither incident read nor operational decision authority. */
final class IncidentIndependencePolicySettings
{
    public const KEY = 'healthSafety.incidents.independence_policy';
    public const REVISION_KEY = 'healthSafety.incidents.independence_policy_revision';
    public const DECLARATION_PREFIX = 'healthSafety.incidents.independence_declaration.';
    public const PERMISSION = 'healthSafety.settings.manage';

    public function withLockedPolicy(callable $operation, ?User $manager = null, ?callable $beforeRead = null): mixed
    {
        return DB::transaction(function () use ($operation, $manager, $beforeRead): mixed {
            AppSetting::query()->insertOrIgnore(['key' => self::REVISION_KEY, 'value' => json_encode(0), 'created_at' => now(), 'updated_at' => now()]);
            $mutex = AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->firstOrFail();
            if ($manager) { CurrentAuthorizationReads::within(fn ($reads) => $this->currentManager($manager, $reads)); }
            if ($beforeRead) { $beforeRead(); }
            $result = $operation($this->snapshot($mutex));
            IncidentRoutingSettings::detached($result);
            return $result;
        }, 3);
    }

    public function currentManager(User $actor, CurrentAuthorizationReads $reads): User
    {
        return app(IncidentRoutingSettings::class)->current($actor, $reads);
    }

    public function editor(User $actor, int $page = 1): array
    {
        return $this->withLockedPolicy(fn ($snapshot) => CurrentAuthorizationReads::within(function ($reads) use ($actor, $snapshot, $page): array {
            $this->currentManager($actor, $reads);
            // Exact policy key only. Private per-incident declaration acceptances are never settings history.
            $rows = $reads->query(HealthSafetySettingChange::query()->where('setting_key', self::KEY)->orderByDesc('revision'))->paginate(25, ['*'], 'independence_page', max(1, $page));
            $rows->setCollection($rows->getCollection()->map(function ($row): array {
                try { $this->assertHistory($row); }
                catch (\Illuminate\Validation\ValidationException $error) {
                    if (array_keys($error->errors()) !== ['policy']) { throw $error; }
                    return ['id' => (int) $row->id, 'revision' => $row->revision, 'integrity' => 'repair_required', 'mode' => null, 'actor' => null, 'reason' => null, 'recorded_at' => null];
                }
                return ['id' => (int) $row->id, 'revision' => $row->revision, 'integrity' => 'recorded', 'mode' => $row->after_value['mode'], 'actor' => $row->actor_snapshot, 'reason' => $row->reason, 'recorded_at' => $row->recorded_at->toIso8601String()];
            }));
            return ['independence_policy' => $snapshot, 'independence_history' => $rows->toArray(), 'can_manage_independence' => true];
        }), $actor);
    }

    public function save(User $actor, array $input): array
    {
        return IncidentIndependenceValues::uniqueReceipt(fn () => $this->persistPolicy($actor, $input));
    }

    private function persistPolicy(User $actor, array $input): array
    {
        $values = IncidentIndependenceValues::policyCommand($input);
        return $this->withLockedPolicy(fn ($before) => CurrentAuthorizationReads::within(function ($reads) use ($actor, $values, $before): array {
            $current = $this->currentManager($actor, $reads);
            $fingerprint = IncidentIndependenceValues::digest(['actor_id' => (int) $current->id, 'command' => 'save_independence_policy', 'values' => $values]);
            $identity = $reads->query(HealthSafetySettingChange::query()->where('command_key', $values['request_uuid']))->first(['id', 'setting_key', 'actor_id']);
            if ($identity) {
                if ($identity->setting_key !== self::KEY || (int) $identity->actor_id !== (int) $current->id) { IncidentIndependenceValues::fail('request_uuid', 'This identity belongs to different accepted work.'); }
                $existing = $reads->query(HealthSafetySettingChange::query()->whereKey($identity->id)->where('setting_key', self::KEY)->where('actor_id', $current->id))->firstOrFail();
                if ($existing->fingerprint !== $fingerprint) { IncidentIndependenceValues::fail('request_uuid', 'This identity belongs to different accepted work.'); }
                $this->assertHistory($existing);
                return $this->receipt($existing);
            }
            if ($before['policy_revision'] === null || $before['policy_revision'] !== $values['expected_policy_revision'] || $before['policy_revision'] >= 2147483646) {
                IncidentIndependenceValues::fail('expected_policy_revision', 'The policy changed or needs repair. Review current settings; your entries are retained.');
            }
            $setting = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->first() ?? new AppSetting(['key' => self::KEY]);
            $rawBefore = $setting->value;
            $policy = ['schema_version' => 1, 'mode' => $values['mode']];
            $setting->value = $policy; IncidentRoutingSettings::saved($setting);
            $mutex = AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->firstOrFail();
            $revision = $before['policy_revision'] + 1; $mutex->value = $revision; IncidentRoutingSettings::saved($mutex);
            $action = $before['configured'] && $before['mode'] === $values['mode'] ? 'kept' : 'changed';
            $recorded = now('UTC')->toImmutable(); $actorSnapshot = ['id' => (int) $current->id, 'name' => $current->name];
            $result = ['accepted' => true, 'request_uuid' => $values['request_uuid'], 'setting_key' => self::KEY, 'policy_revision' => $revision,
                'mode' => $values['mode'], 'policy_digest' => IncidentIndependenceValues::digest($policy), 'action' => $action, 'actor_id' => (int) $current->id];
            $meta = $result + ['fingerprint' => $fingerprint, 'before_digest' => $rawBefore === null ? null : IncidentIndependenceValues::digest($rawBefore),
                'reason_digest' => IncidentIndependenceValues::digest($values['reason']), 'actor_snapshot_digest' => IncidentIndependenceValues::digest($actorSnapshot), 'recorded_at' => $recorded->format('Y-m-d\TH:i:s.u\Z')];
            $request = clone request(); $request->setUserResolver(fn () => $current);
            AuditLogger::logOrFail('incidents.independence.policy.'.$action, $mutex, $meta, $request);
            $audits = AuditLog::query()->where('user_id', $current->id)->where('auditable_type', $mutex->getMorphClass())->where('auditable_id', $mutex->id)
                ->where('action', 'incidents.independence.policy.'.$action)->where('meta->request_uuid', $values['request_uuid'])->lockForUpdate()->get();
            if ($audits->count() !== 1 || IncidentIndependenceValues::digest($audits->sole()->meta) !== IncidentIndependenceValues::digest($meta)) { throw new LogicException('Policy acceptance requires one exact persisted audit.'); }
            $row = new HealthSafetySettingChange(['setting_key' => self::KEY, 'revision' => $revision, 'schema_version' => 1, 'action' => $action, 'origin' => 'organisation_setting',
                'before_value' => $rawBefore, 'after_value' => $policy, 'before_digest' => $meta['before_digest'], 'after_digest' => $result['policy_digest'],
                'actor_id' => $current->id, 'actor_snapshot' => $actorSnapshot, 'recorded_at' => $recorded, 'reason' => $values['reason'],
                'audit_id' => $audits->sole()->id, 'command_key' => $values['request_uuid'], 'fingerprint' => $fingerprint, 'accepted_result' => $result]);
            IncidentRoutingSettings::saved($row); $this->assertHistory($row);
            return $result;
        }), $actor);
    }

    public function receiptForManager(User $actor, string $uuid): ?array
    {
        $uuid = IncidentIndependenceValues::uuid($uuid);
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $uuid): ?array {
            $current = $this->currentManager($actor, $reads);
            $row = $reads->query(HealthSafetySettingChange::query()->where('setting_key', self::KEY)->where('actor_id', $current->id)->where('command_key', $uuid))->first();
            if (! $row) { return null; }
            $this->assertHistory($row); return $this->receipt($row);
        }));
    }

    public function assertSnapshot(array $snapshot): array
    {
        if (! ($snapshot['configuration_valid'] ?? false)) { IncidentIndependenceValues::fail('policy', 'Independent-handling policy needs an authorised settings review.'); }
        if (($snapshot['setting_change_id'] ?? null) === null) {
            $expected = $this->defaultSnapshot();
            if (IncidentIndependenceValues::digest($snapshot) !== IncidentIndependenceValues::digest($expected)) { IncidentIndependenceValues::fail('policy', 'The retained default policy evidence needs repair.'); }
            return [];
        }
        $row = HealthSafetySettingChange::query()->where('setting_key', self::KEY)->whereKey($snapshot['setting_change_id'])->lock('for share nowait')
            ->select('health_safety_setting_changes.*')->selectRaw(IncidentIndependenceBindingSql::fingerprint('acceptance', 'health_safety_setting_changes').' AS binding_fingerprint')->first();
        if (! $row) { IncidentIndependenceValues::fail('policy', 'The original policy evidence is unavailable.'); }
        $guards = $this->assertHistory($row);
        if (IncidentIndependenceValues::digest($snapshot) !== IncidentIndependenceValues::digest($this->historySnapshot($row))) { IncidentIndependenceValues::fail('policy', 'The retained policy snapshot needs repair.'); }
        return [...$guards, ['family' => 'acceptance', 'id' => (int) $row->id, 'fingerprint' => $row->getRawOriginal('binding_fingerprint')]];
    }

    public function assertHistory(HealthSafetySettingChange $row): array
    {
        $policy = $row->after_value;
        $expected = is_array($policy) && in_array($policy['mode'] ?? null, IncidentIndependenceValues::MODES, true) ? ['schema_version' => 1, 'mode' => $policy['mode']] : null;
        $result = $expected ? ['accepted' => true, 'request_uuid' => $row->command_key, 'setting_key' => self::KEY, 'policy_revision' => $row->revision,
            'mode' => $policy['mode'], 'policy_digest' => $row->after_digest, 'action' => $row->action, 'actor_id' => (int) $row->actor_id] : [];
        $mutex = AppSetting::query()->where('key', self::REVISION_KEY)->lock('for share nowait')->select('app_settings.id')
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('policy_mutex', 'app_settings').' AS binding_fingerprint')->first();
        $audit = AuditLog::query()->whereKey($row->audit_id)->lock('for share nowait')->select('audit_logs.*')
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('audit', 'audit_logs').' AS binding_fingerprint')->first();
        $meta = $result + ['fingerprint' => $row->fingerprint, 'before_digest' => $row->before_digest, 'reason_digest' => IncidentIndependenceValues::digest($row->reason),
            'actor_snapshot_digest' => IncidentIndependenceValues::digest($row->actor_snapshot), 'recorded_at' => $row->recorded_at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z')];
        if (! $expected || $row->setting_key !== self::KEY || $row->schema_version !== 1 || $row->revision < 1 || ! in_array($row->action, ['changed', 'kept'], true)
            || $row->origin !== 'organisation_setting' || IncidentIndependenceValues::digest($expected) !== IncidentIndependenceValues::digest($policy)
            || $row->after_digest !== IncidentIndependenceValues::digest($policy) || IncidentIndependenceValues::digest($result) !== IncidentIndependenceValues::digest($row->accepted_result)
            || ($row->before_value === null ? null : IncidentIndependenceValues::digest($row->before_value)) !== $row->before_digest
            || ! $mutex || ! $audit || $audit->action !== 'incidents.independence.policy.'.$row->action || $audit->user_id != $row->actor_id
            || $audit->auditable_type !== $mutex->getMorphClass() || (int) $audit->auditable_id !== (int) $mutex->id || IncidentIndependenceValues::digest($audit->meta) !== IncidentIndependenceValues::digest($meta)) {
            IncidentIndependenceValues::fail('policy', 'The retained policy acceptance needs repair.');
        }
        return [['family' => 'policy_mutex', 'id' => (int) $mutex->id, 'fingerprint' => $mutex->getRawOriginal('binding_fingerprint')],
            ['family' => 'audit', 'id' => (int) $audit->id, 'fingerprint' => $audit->getRawOriginal('binding_fingerprint')]];
    }

    private function snapshot(AppSetting $mutex): array
    {
        $setting = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->first();
        $history = HealthSafetySettingChange::query()->where('setting_key', self::KEY)->orderByDesc('revision')->lockForUpdate()->first();
        if (! $setting && ! $history && $mutex->value === 0) { return $this->defaultSnapshot(); }
        if ($setting && $history && is_int($mutex->value) && $mutex->value === $history->revision) {
            try {
                $this->assertHistory($history);
                if (IncidentIndependenceValues::digest($setting->value) === $history->after_digest) { return $this->historySnapshot($history); }
            } catch (\Illuminate\Validation\ValidationException $error) { if (array_keys($error->errors()) !== ['policy']) { throw $error; } }
        }
        return ['schema_version' => 1, 'mode' => null, 'policy_revision' => is_int($mutex->value) && $mutex->value >= 0 && $mutex->value <= 2147483647 ? $mutex->value : null,
            'configured' => false, 'configuration_valid' => false, 'origin' => 'repair_required', 'policy_digest' => null, 'setting_change_id' => null, 'reviewed_by' => null, 'reviewed_at' => null];
    }
    private function defaultSnapshot(): array
    {
        return ['schema_version' => 1, 'mode' => IncidentIndependenceValues::DEFAULT_MODE, 'policy_revision' => 0, 'configured' => false, 'configuration_valid' => true,
            'origin' => 'application_default_unreviewed', 'policy_digest' => IncidentIndependenceValues::digest(['schema_version' => 1, 'mode' => IncidentIndependenceValues::DEFAULT_MODE]),
            'setting_change_id' => null, 'reviewed_by' => null, 'reviewed_at' => null];
    }
    private function historySnapshot(HealthSafetySettingChange $row): array
    {
        return ['schema_version' => 1, 'mode' => $row->after_value['mode'], 'policy_revision' => $row->revision, 'configured' => true, 'configuration_valid' => true,
            'origin' => 'organisation_setting', 'policy_digest' => $row->after_digest, 'setting_change_id' => (int) $row->id, 'reviewed_by' => (int) $row->actor_id, 'reviewed_at' => $row->recorded_at->toIso8601String()];
    }
    private function receipt(HealthSafetySettingChange $row): array
    {
        return ['accepted' => true, 'request_uuid' => $row->command_key, 'setting_key' => self::KEY, 'policy_revision' => $row->revision,
            'mode' => $row->after_value['mode'], 'policy_digest' => $row->after_digest, 'action' => $row->action, 'actor_id' => (int) $row->actor_id];
    }
}
