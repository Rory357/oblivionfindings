<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\HealthSafetySettingChange;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** One organisation's approval workflow; never clinical qualification or source access. */
final class BehaviourSupportApprovalPolicySettings
{
    public const KEY = 'healthSafety.restraints.plan_approval_policy';

    public const REVISION_KEY = 'healthSafety.restraints.plan_approval_policy_revision';

    public const PERMISSION = 'healthSafety.settings.manage';

    public const MODES = ['separate_specialist', 'authorised_reviewer'];

    public const DEFAULT_MODE = 'separate_specialist';

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly AuthorizationEvidenceLockService $authorization,
        private readonly HrCurrentStaffService $staff,
    ) {}

    /** Safe projection only; does not materialize history or cache configuration. */
    public function snapshot(): array
    {
        return $this->readSnapshot(false);
    }

    /**
     * Approval/publication commands must enter here BEFORE locking any plan/version.
     * Only a detached snapshot crosses this callback: no mutable mutex or authority.
     * A failed nested source command rolls back any revision-zero materialization.
     */
    public function withLockedPolicy(callable $operation): mixed
    {
        return $this->transaction(function () use ($operation) {
            $this->lockMutex();
            $snapshot = $this->readSnapshot(true);
            if ($snapshot['configuration_valid'] && $snapshot['policy_revision'] === 0 && $snapshot['setting_change_id'] === null) {
                $this->materializeDefault();
                $snapshot = $this->readSnapshot(true);
            }

            return $operation($snapshot);
        });
    }

    /** Call after current source authority and accepted replay, before a NEW approval. */
    public function assertUsable(array $snapshot, int $expectedRevision): void
    {
        if (! ($snapshot['configuration_valid'] ?? false)) {
            throw ValidationException::withMessages(['expected_policy_revision' => 'The approval policy configuration needs an authorised settings review before a new decision.']);
        }
        if ($snapshot['policy_revision'] !== $expectedRevision) {
            throw ValidationException::withMessages(['expected_policy_revision' => 'The approval policy changed. Your entries were not saved; review the current policy before trying again.']);
        }
    }

    /** Navigation capability: no policy/configuration/history read or write. */
    public function canManageSettings(User $actor): bool
    {
        try {
            return $this->transaction(function () use ($actor): bool {
                $this->currentManager($actor);

                return true;
            });
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return false;
            }
            throw $error;
        } catch (ValidationException) {
            // Concurrent evidence cannot establish a navigation capability.
            return false;
        }
    }

    /** Reconstruct a page-local acceptance from retained, currently authorized evidence. */
    public function receiptForManager(User $actor, mixed $candidate): ?array
    {
        if (! is_array($candidate) || ! is_int($candidate['setting_change_id'] ?? null)
            || ! is_int($candidate['policy_revision'] ?? null)
            || ! is_string($candidate['idempotency_key'] ?? null) || ! Str::isUuid($candidate['idempotency_key'])) {
            return null;
        }

        return $this->transaction(function () use ($actor, $candidate): ?array {
            $current = $this->currentManager($actor);
            $row = HealthSafetySettingChange::query()->whereKey($candidate['setting_change_id'])
                ->where('setting_key', self::KEY)->where('actor_id', $current->id)
                ->where('command_key', strtolower($candidate['idempotency_key']))
                ->where('revision', $candidate['policy_revision'])->lock('for share nowait')->first();

            return $row && is_array($row->accepted_result)
                ? $this->receipt($row, ($candidate['replayed'] ?? false) === true)
                : null;
        });
    }

    public function assertManager(User $actor): void
    {
        $this->transaction(function () use ($actor): void {
            $this->lockMutex();
            $this->currentManager($actor);
        });
    }

    /** Privileged editor/history; ordinary plan readers only receive snapshot(). */
    public function editor(User $actor, int $page = 1): array
    {
        return $this->withLockedPolicy(function (array $snapshot) use ($actor, $page): array {
            $this->currentManager($actor);
            $history = HealthSafetySettingChange::query()->where('setting_key', self::KEY)
                ->orderByDesc('revision')->paginate(25, ['*'], 'page', max(1, $page));
            $history->setCollection($history->getCollection()->map(fn ($row) => [
                'id' => (int) $row->id, 'revision' => $row->revision, 'action' => $row->action,
                'origin' => $row->origin, 'before_value' => $row->before_value, 'after_value' => $row->after_value,
                'before_digest' => $row->before_digest, 'after_digest' => $row->after_digest,
                'actor' => $row->actor_snapshot, 'recorded_at' => $row->recorded_at->utc()->toIso8601String(),
                'reason' => $row->reason, 'audit_id' => $row->audit_id,
            ]));

            return ['policy' => $snapshot, 'can_manage' => true, 'history' => $history->toArray()];
        });
    }

    public function save(User $actor, array $input): array
    {
        return $this->withLockedPolicy(function (array $before) use ($actor, $input): array {
            $current = $this->currentManager($actor);
            $payload = $this->normalize($input);
            $fingerprint = $this->digest(['actor_id' => (int) $current->id, 'setting_key' => self::KEY, 'verb' => 'save_approval_policy', 'payload' => $payload]);
            $receipt = HealthSafetySettingChange::query()->where('command_key', $payload['idempotency_key'])->lockForUpdate()->first();
            if ($receipt) {
                if ((int) $receipt->actor_id !== (int) $current->id || ! hash_equals((string) $receipt->fingerprint, $fingerprint) || $receipt->setting_key !== self::KEY) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This command key already belongs to different saved work.']);
                }

                return $this->receipt($receipt, true);
            }
            if ($before['policy_revision'] === null || $before['policy_revision'] !== $payload['expected_policy_revision']) {
                throw ValidationException::withMessages(['expected_policy_revision' => 'The approval policy changed or its revision is invalid. Your entries were not saved; reload the settings before trying again.']);
            }
            $configRow = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->first();
            $rawBefore = $configRow?->value;
            $policy = ['schema_version' => self::SCHEMA_VERSION, 'mode' => $payload['mode']];
            $digest = $this->digest($policy);
            $kept = $before['configuration_valid'] && $before['mode'] === $payload['mode'];
            if (! $kept && $payload['reason'] === null) {
                throw ValidationException::withMessages(['reason' => 'Record why the approval workflow is changing.']);
            }
            $restored = ! $kept && HealthSafetySettingChange::query()->where('setting_key', self::KEY)->where('after_digest', $digest)->lockForUpdate()->first() !== null;
            $action = $kept ? 'kept' : ($restored ? 'restored' : 'changed');
            if ($before['policy_revision'] >= PHP_INT_MAX - 1) {
                throw ValidationException::withMessages(['expected_policy_revision' => 'The policy revision cannot be incremented safely. No change was saved.']);
            }
            $revision = $before['policy_revision'] + 1;
            $recordedAt = now()->utc();
            AppSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $policy]);
            $mutex = AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->firstOrFail();
            $mutex->update(['value' => $revision]);
            $event = 'health_safety.settings.restraint_plan_approval.'.$action;
            // Attribute only to this command actor, irrespective of unrelated HTTP auth.
            $auditRequest = clone request();
            $auditRequest->setUserResolver(fn () => $current);
            AuditLogger::logOrFail($event, $mutex, [
                'actor_id' => (int) $current->id, 'idempotency_key' => $payload['idempotency_key'],
                'setting_key' => self::KEY, 'policy_revision' => $revision,
                'before_digest' => $rawBefore === null ? null : $this->digest($rawBefore),
                'after_digest' => $digest, 'action' => $action,
                'before_policy' => $before['policy_snapshot'], 'after_policy' => $policy,
                'before_configuration_valid' => $before['configuration_valid'],
            ], $auditRequest);
            $audits = AuditLog::query()->where('user_id', $current->id)->where('action', $event)
                ->where('auditable_type', $mutex->getMorphClass())->where('auditable_id', $mutex->id)
                ->where('meta->idempotency_key', $payload['idempotency_key'])->lockForUpdate()->get();
            if ($audits->count() !== 1) {
                throw new LogicException('The accepted approval policy command requires exactly one attributable audit.');
            }
            $row = HealthSafetySettingChange::create([
                'setting_key' => self::KEY, 'revision' => $revision, 'schema_version' => self::SCHEMA_VERSION,
                'action' => $action, 'origin' => 'organisation_setting',
                'before_value' => $rawBefore, 'after_value' => $policy,
                'before_digest' => $rawBefore === null ? null : $this->digest($rawBefore), 'after_digest' => $digest,
                'actor_id' => $current->id, 'actor_snapshot' => $this->actorSnapshot($current),
                'recorded_at' => $recordedAt, 'reason' => $payload['reason'], 'audit_id' => $audits->sole()->id,
                'command_key' => $payload['idempotency_key'], 'fingerprint' => $fingerprint,
                'accepted_result' => [
                    'accepted' => true, 'idempotency_key' => $payload['idempotency_key'],
                    'setting_key' => self::KEY, 'policy_revision' => $revision, 'schema_version' => self::SCHEMA_VERSION,
                    'mode' => $policy['mode'], 'policy_digest' => $digest, 'policy_snapshot' => $policy,
                    'action' => $action, 'origin' => 'organisation_setting', 'recorded_at' => $recordedAt->toIso8601String(),
                ],
            ]);

            return $this->receipt($this->retainedHistory($row), false);
        });
    }

    private function currentManager(User $actor): User
    {
        return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor): User {
            $current = $this->authorization->lockForUserWithoutWaiting($actor, [self::PERMISSION]);
            abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->exists(), 403);
            abort_unless($current->canDo(self::PERMISSION), 403);

            return $current;
        });
    }

    private function actorSnapshot(User $actor): array
    {
        return [
            'id' => (int) $actor->id, 'name' => $actor->name, 'legacy_role' => $actor->role,
            'roles' => $actor->roles->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])->values()->all(),
            'permissions' => [self::PERMISSION => true],
            'overrides' => $actor->permissionOverrides->map(fn ($permission) => ['key' => $permission->key, 'allowed' => (bool) $permission->pivot->allowed])->values()->all(),
            'approved_at' => $actor->approved_at?->toIso8601String(),
        ];
    }

    private function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['mode', 'expected_policy_revision', 'idempotency_key', 'reason'])) {
            throw ValidationException::withMessages(['mode' => 'Only the approval policy command fields may be submitted.']);
        }
        if (is_bool($input['expected_policy_revision'] ?? null)) {
            throw ValidationException::withMessages(['expected_policy_revision' => 'The policy revision must be a nonnegative integer.']);
        }
        $valid = Validator::make($input, [
            'mode' => ['required', 'string', Rule::in(self::MODES)],
            'expected_policy_revision' => ['required', 'integer', 'min:0', 'max:'.(PHP_INT_MAX - 1)],
            'idempotency_key' => ['required', 'string', 'uuid'], 'reason' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $reason = $valid['reason'] ?? null;

        return ['mode' => $valid['mode'], 'expected_policy_revision' => (int) $valid['expected_policy_revision'],
            'idempotency_key' => strtolower($valid['idempotency_key']), 'reason' => $reason === null || trim($reason) === '' ? null : $reason];
    }

    private function lockMutex(): AppSetting
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Policy mutex requires an active transaction.');
        }
        // The unique key serializes concurrent first use as well as later writes.
        AppSetting::query()->insertOrIgnore(['key' => self::REVISION_KEY, 'value' => json_encode(0), 'created_at' => now(), 'updated_at' => now()]);

        return AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->firstOrFail();
    }

    private function readSnapshot(bool $locked): array
    {
        $query = AppSetting::query()->whereIn('key', [self::KEY, self::REVISION_KEY]);
        if ($locked) {
            $query->lockForUpdate();
        }
        $rows = $query->get()->keyBy('key');
        $stored = $rows->get(self::KEY);
        $revisionRow = $rows->get(self::REVISION_KEY);
        $revision = $revisionRow ? $revisionRow->value : 0;
        $invalid = ! is_int($revision) || $revision < 0 || $revision >= PHP_INT_MAX;
        $policy = $stored ? $stored->value : ['schema_version' => self::SCHEMA_VERSION, 'mode' => self::DEFAULT_MODE];
        $validPolicy = is_array($policy) && count($policy) === 2 && array_key_exists('schema_version', $policy)
            && array_key_exists('mode', $policy) && $policy['schema_version'] === self::SCHEMA_VERSION
            && in_array($policy['mode'], self::MODES, true);
        $historyQuery = HealthSafetySettingChange::query()->where('setting_key', self::KEY)->where('revision', is_int($revision) ? $revision : 0);
        if ($locked) {
            $historyQuery->lockForUpdate();
        }
        $history = $historyQuery->first();
        $latestQuery = HealthSafetySettingChange::query()->where('setting_key', self::KEY)->orderByDesc('revision');
        if ($locked) {
            $latestQuery->lockForUpdate();
        }
        $latest = $latestQuery->first();
        $futureHistory = is_int($revision) && $revision >= 0 && $latest !== null && $latest->revision > $revision;
        $canonical = $validPolicy ? ['schema_version' => self::SCHEMA_VERSION, 'mode' => $policy['mode']] : null;
        $digest = $canonical === null ? null : $this->digest($canonical);
        if (! $invalid && $validPolicy) {
            // Deletion/direct edits cannot masquerade as a governed reset or revision.
            $invalid = ($revision > 0 && (! $stored || ! $history))
                || ($revision === 0 && $policy['mode'] !== self::DEFAULT_MODE)
                || ($history && (! hash_equals($history->after_digest, $digest) || ! is_array($history->after_value) || count($history->after_value) !== 2
                    || ($history->after_value['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($history->after_value['mode'] ?? null) !== $canonical['mode']))
                || $futureHistory;
        }
        $usable = ! $invalid && $validPolicy;

        return [
            'setting_key' => self::KEY, 'schema_version' => $usable ? self::SCHEMA_VERSION : null,
            'mode' => $usable ? $canonical['mode'] : null,
            'policy_revision' => ! $futureHistory && is_int($revision) && $revision >= 0 && $revision < PHP_INT_MAX ? $revision : null,
            'policy_digest' => $usable ? $digest : null, 'policy_snapshot' => $usable ? $canonical : null,
            'setting_change_id' => $usable && $history ? (int) $history->id : null,
            'origin' => ! $usable ? 'configuration_invalid' : ($revision === 0 ? 'application_default' : 'organisation_setting'),
            'reviewed_at' => $usable && $revision > 0 ? $history->recorded_at->utc()->toIso8601String() : null,
            'configuration_valid' => $usable, 'configuration_error' => $usable ? null : 'invalid_policy_configuration',
        ];
    }

    private function materializeDefault(): void
    {
        $policy = ['schema_version' => self::SCHEMA_VERSION, 'mode' => self::DEFAULT_MODE];
        $row = HealthSafetySettingChange::create([
            'setting_key' => self::KEY, 'revision' => 0, 'schema_version' => self::SCHEMA_VERSION,
            'action' => 'application_default', 'origin' => 'application_default',
            'before_value' => null, 'after_value' => $policy, 'before_digest' => null, 'after_digest' => $this->digest($policy),
            'actor_id' => null, 'actor_snapshot' => null, 'recorded_at' => now()->utc(),
            'reason' => null, 'audit_id' => null, 'command_key' => null, 'fingerprint' => null, 'accepted_result' => null,
        ]);
        $this->retainedHistory($row);
    }

    private function retainedHistory(HealthSafetySettingChange $row): HealthSafetySettingChange
    {
        $retained = $row->exists && $row->getKey() !== null
            ? HealthSafetySettingChange::query()->whereKey($row->getKey())->lockForUpdate()->first()
            : null;
        if (! $retained) {
            throw new LogicException('Approval policy provenance must be persisted before the command can succeed.');
        }

        return $retained;
    }

    private function receipt(HealthSafetySettingChange $row, bool $replayed): array
    {
        return [...$row->accepted_result, 'setting_change_id' => (int) $row->id, 'replayed' => $replayed];
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (\Illuminate\Database\DeadlockException) {
            // Inside a transaction Laravel raises a deadlock (1213) as DeadlockException, not QueryException.
            throw ValidationException::withMessages(['expected_policy_revision' => 'The approval policy or its authority changed concurrently. Your entries were not saved; review current settings before trying again.']);
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['expected_policy_revision' => 'The approval policy or its authority changed concurrently. Your entries were not saved; review current settings before trying again.']);
            }
            throw $error;
        }
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
