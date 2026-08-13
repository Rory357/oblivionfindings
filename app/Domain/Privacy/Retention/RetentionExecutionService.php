<?php

namespace App\Domain\Privacy\Retention;

use App\Models\AnonymizationLog;
use App\Models\Client;
use App\Models\DataRetentionExecution;
use App\Models\DataRetentionExecutionItem;
use App\Models\DataRetentionPolicy;
use App\Models\LegalHold;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RetentionExecutionService
{
    private const ACTIONS = [
        'anonymize' => 'hard_delete_after_years',
        'soft_delete' => 'retention_period_years',
        'archive' => 'archive_after_years',
    ];

    public function __construct(private readonly RetentionOwnerRegistry $registry) {}

    /** @return array<string, mixed> */
    public function preview(DataRetentionPolicy $policy, User $actor): array
    {
        return DB::transaction(function () use ($policy, $actor): array {
            /** @var DataRetentionPolicy $locked */
            $locked = DataRetentionPolicy::query()->lockForUpdate()->findOrFail($policy->id);

            if (! $locked->active) {
                throw new RetentionContractException('inactive_policy', 'Only active retention policies can be previewed.');
            }

            $owner = $this->registry->resolve($locked->model_type);
            $owner->validateNativeContract($locked);
            $snapshot = $this->buildPreview($locked, $owner);
            $fingerprint = $this->fingerprint($locked);

            $locked->forceFill([
                'execution_state' => 'previewed',
                'preview_fingerprint' => $fingerprint,
                'preview_snapshot' => $snapshot,
                'previewed_at' => now(),
                'previewed_by_user_id' => $actor->id,
                'approved_fingerprint' => null,
                'approved_at' => null,
                'approved_by_user_id' => null,
                'updated_by' => $actor->id,
            ])->save();

            AuditLogger::logOrFail('data_retention.previewed', $locked, [
                'actor_id' => $actor->id,
                'status' => 'previewed',
                'items_processed' => $snapshot['eligible_count'],
            ]);

            return $snapshot;
        });
    }

    public function approve(DataRetentionPolicy $policy, User $actor): DataRetentionPolicy
    {
        return DB::transaction(function () use ($policy, $actor): DataRetentionPolicy {
            /** @var DataRetentionPolicy $locked */
            $locked = DataRetentionPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            $owner = $this->registry->resolve($locked->model_type);
            $owner->validateNativeContract($locked);
            $fingerprint = $this->fingerprint($locked);

            if ($locked->execution_state !== 'previewed'
                || ! is_string($locked->preview_fingerprint)
                || ! hash_equals($fingerprint, $locked->preview_fingerprint)
                || ! is_array($locked->preview_snapshot)
            ) {
                throw new RetentionContractException('preview_required', 'Create a current preview before approving this retention policy.');
            }

            if ((int) $locked->previewed_by_user_id === (int) $actor->id) {
                throw new RetentionContractException('independent_approval_required', 'A different authorised person must approve the retention preview.');
            }

            if (($locked->preview_snapshot['blocked'] ?? false) === true) {
                throw new RetentionContractException('preview_blocked', 'This retention preview is blocked and cannot be approved.', true);
            }

            $locked->forceFill([
                'execution_state' => 'approved',
                'approved_fingerprint' => $fingerprint,
                'approved_at' => now(),
                'approved_by_user_id' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            AuditLogger::logOrFail('data_retention.approved', $locked, [
                'actor_id' => $actor->id,
                'status' => 'approved',
            ]);

            return $locked;
        });
    }

    public function invalidateApproval(DataRetentionPolicy $policy, ?int $actorId = null): void
    {
        $policy->forceFill([
            'execution_state' => 'draft',
            'preview_fingerprint' => null,
            'preview_snapshot' => null,
            'previewed_at' => null,
            'previewed_by_user_id' => null,
            'approved_fingerprint' => null,
            'approved_at' => null,
            'approved_by_user_id' => null,
            'updated_by' => $actorId ?? $policy->updated_by,
        ]);
    }

    /** @return array<string, mixed> */
    public function execute(DataRetentionPolicy $policy, string $source, ?User $actor = null): array
    {
        if (! in_array($source, ['manual', 'scheduled'], true)) {
            throw new RetentionContractException('invalid_execution_source', 'The retention execution source is invalid.');
        }

        $fingerprint = $this->fingerprint($policy);
        $idempotencyKey = hash('sha256', implode('|', [
            'data-retention-v1',
            $policy->getKey(),
            $fingerprint,
            now('UTC')->toDateString(),
        ]));

        try {
            $execution = DataRetentionExecution::query()->create([
                'data_retention_policy_id' => $policy->id,
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                'contract_fingerprint' => $fingerprint,
                'status' => 'running',
                'actor_user_id' => $actor?->id,
                'previewed_by_user_id' => $policy->previewed_by_user_id,
                'approved_by_user_id' => $policy->approved_by_user_id,
                'preview_snapshot' => $policy->preview_snapshot,
                'started_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = DataRetentionExecution::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return [
                'status' => match ($existing->status) {
                    'completed' => 'already_completed',
                    'running' => 'already_running',
                    default => $existing->status,
                },
                'execution_id' => $existing->id,
                'result' => $existing->result,
                'failure_code' => $existing->failure_code,
                'failure_message' => $existing->failure_message,
            ];
        }

        try {
            $result = $this->runApprovedExecution($execution, $policy, $fingerprint);
            $execution->forceFill([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ])->save();

            return [
                'status' => 'completed',
                'execution_id' => $execution->id,
                'result' => $result,
            ];
        } catch (\Throwable $exception) {
            $contractException = $exception instanceof RetentionContractException ? $exception : null;
            $execution->forceFill([
                'status' => $contractException?->blocked ? 'blocked' : 'failed',
                'failure_code' => $contractException?->reasonCode ?? Str::snake(class_basename($exception)),
                'failure_message' => $contractException
                    ? Str::limit($contractException->getMessage(), 500, '')
                    : 'Retention execution failed before a governed outcome could be completed.',
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function runApprovedExecution(
        DataRetentionExecution $execution,
        DataRetentionPolicy $policy,
        string $fingerprint,
    ): array {
        /** @var DataRetentionPolicy $current */
        $current = DB::transaction(fn () => DataRetentionPolicy::query()
            ->lockForUpdate()
            ->findOrFail($policy->id));
        $owner = $this->registry->resolve($current->model_type);
        $owner->validateNativeContract($current);

        if (! $current->active) {
            throw new RetentionContractException('inactive_policy', 'Inactive retention policies cannot execute.', true);
        }

        if ($current->execution_state !== 'approved'
            || ! is_string($current->approved_fingerprint)
            || ! hash_equals($fingerprint, $current->approved_fingerprint)
            || ! hash_equals($fingerprint, $this->fingerprint($current))
            || ! $current->approved_by_user_id
            || ! $current->previewed_by_user_id
            || (int) $current->approved_by_user_id === (int) $current->previewed_by_user_id
        ) {
            throw new RetentionContractException('approval_required', 'A current independently approved preview is required before retention can execute.', true);
        }

        if ($this->hasGlobalLegalHold()) {
            throw new RetentionContractException('global_legal_hold', 'A global legal hold blocks retention execution.', true);
        }

        $liveSnapshot = $this->buildPreview($current, $owner);
        $execution->forceFill(['preview_snapshot' => $liveSnapshot])->save();
        $counts = [
            'anonymized' => 0,
            'soft_deleted' => 0,
            'archived' => 0,
            'already_processed' => 0,
            'exempted' => (int) $liveSnapshot['exempt_count'],
        ];

        foreach (self::ACTIONS as $action => $periodField) {
            $years = $current->{$periodField};
            if (! is_int($years) || $years < 1 || ($action !== 'anonymize' && ! $owner->usesSoftDeletes())) {
                continue;
            }

            $candidateIds = $this->eligibleQuery($current, $owner, $action, $years)
                ->orderBy($owner->model()->getQualifiedKeyName())
                ->pluck($owner->model()->getQualifiedKeyName());

            foreach ($candidateIds as $recordId) {
                $outcome = $this->executeRecord(
                    $execution,
                    $current,
                    $owner,
                    $action,
                    $years,
                    (int) $recordId,
                );
                $counts[$outcome]++;
            }
        }

        $current->forceFill(['last_applied_at' => now()])->save();
        AuditLogger::log('data_retention.executed', $current, [
            'actor_id' => $execution->actor_user_id,
            'status' => 'completed',
            'items_processed' => $counts['anonymized'] + $counts['soft_deleted'] + $counts['archived'],
        ]);

        return $counts;
    }

    private function executeRecord(
        DataRetentionExecution $execution,
        DataRetentionPolicy $policy,
        RetentionOwnerAdapter $owner,
        string $action,
        int $years,
        int $recordId,
    ): string {
        return DB::transaction(function () use ($execution, $policy, $owner, $action, $years, $recordId): string {
            /** @var Model|null $record */
            $record = $this->eligibleQuery($policy, $owner, $action, $years)
                ->where($owner->model()->getQualifiedKeyName(), $recordId)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return 'already_processed';
            }

            $existing = DataRetentionExecutionItem::query()
                ->where('data_retention_policy_id', $policy->id)
                ->where('owner_key', $owner->key)
                ->where('record_id', $recordId)
                ->where('action', $action)
                ->first();
            if ($existing) {
                return 'already_processed';
            }

            $fields = [];
            $methods = [];
            $resultKey = match ($action) {
                'anonymize' => 'anonymized',
                'soft_delete' => 'soft_deleted',
                default => 'archived',
            };

            if ($action === 'anonymize') {
                $updates = [];
                foreach ($owner->anonymizationFields as $field => $strategy) {
                    if ($record->getAttribute($field) === null) {
                        continue;
                    }
                    $updates[$field] = $strategy === 'redact' ? '[REDACTED]' : null;
                    $fields[] = $field;
                    $methods[$field] = $strategy === 'redact' ? 'redacted' : 'cleared';
                }
                if ($updates !== []) {
                    $record->forceFill($updates)->saveQuietly();
                }
            } else {
                $record->delete();
                $fields[] = $action;
                $methods[$action] = true;
            }

            AnonymizationLog::query()->create([
                'model_type' => $owner->key,
                'model_id' => $recordId,
                'reason' => 'retention_policy_expired - Policy: '.$policy->policy_name,
                'fields_anonymized' => $fields,
                'anonymization_methods' => $methods,
                'anonymized_at' => now(),
                'anonymized_by_user_id' => $execution->actor_user_id,
                'reversible' => $action !== 'anonymize',
            ]);

            $ownership = $this->ownershipEvidence($record);

            DataRetentionExecutionItem::query()->create([
                'data_retention_execution_id' => $execution->id,
                'data_retention_policy_id' => $policy->id,
                'owner_key' => $owner->key,
                'record_id' => $recordId,
                'action' => $action,
                'outcome' => $resultKey,
                'evidence' => array_merge($ownership, [
                    'fields' => $fields,
                    'legal_hold_checked' => true,
                    'active_case_checked' => (bool) $policy->active_case_exemption,
                ]),
            ]);

            AuditLogger::logOrFail('data_retention.'.$resultKey, $record, [
                'actor_id' => $execution->actor_user_id,
                'status' => $resultKey,
                'fields' => $fields,
                'client_id' => $ownership['client_id'] ?? null,
                'site_id' => $ownership['site_id'] ?? null,
            ]);

            return $resultKey;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function buildPreview(DataRetentionPolicy $policy, RetentionOwnerAdapter $owner): array
    {
        $actions = [];
        $eligibleCount = 0;
        $exemptCount = 0;

        foreach (self::ACTIONS as $action => $periodField) {
            $years = $policy->{$periodField};
            if (! is_int($years) || $years < 1 || ($action !== 'anonymize' && ! $owner->usesSoftDeletes())) {
                continue;
            }

            $beforeExemptions = $this->baseQuery($policy, $owner, $action, $years)->count();
            $eligible = $this->eligibleQuery($policy, $owner, $action, $years)->count();
            $actions[$action] = [
                'eligible_count' => $eligible,
                'exempt_count' => max(0, $beforeExemptions - $eligible),
                'cutoff' => now()->subYears($years)->toDateString(),
            ];
            $eligibleCount += $eligible;
            $exemptCount += $actions[$action]['exempt_count'];
        }

        $blocked = $this->hasGlobalLegalHold();

        return [
            'owner_key' => $owner->key,
            'owner_label' => $owner->label,
            'eligible_count' => $eligibleCount,
            'exempt_count' => $exemptCount,
            'blocked' => $blocked,
            'block_reason' => $blocked ? 'A global legal hold is active.' : null,
            'actions' => $actions,
            'previewed_at' => now()->toIso8601String(),
        ];
    }

    private function eligibleQuery(
        DataRetentionPolicy $policy,
        RetentionOwnerAdapter $owner,
        string $action,
        int $years,
    ): Builder {
        $query = $this->baseQuery($policy, $owner, $action, $years);
        $model = $owner->model();

        // Legal holds are always enforced, regardless of legacy policy flags.
        $query->whereNotExists(function ($subquery) use ($owner, $model): void {
            $subquery->selectRaw('1')
                ->from('legal_holds')
                ->where('holdable_type', $owner->key)
                ->whereColumn('holdable_id', $model->getTable().'.'.$model->getKeyName())
                ->where('status', 'active');
        });

        if ($policy->active_case_exemption && $owner->activeCaseRelation) {
            if ($owner->activeCaseRelation === '@self') {
                $query->where($model->getTable().'.status', '!=', 'active');
            } else {
                $query->whereDoesntHave($owner->activeCaseRelation, fn ($related) => $related->where('status', 'active'));
            }
        }

        return $query;
    }

    private function baseQuery(
        DataRetentionPolicy $policy,
        RetentionOwnerAdapter $owner,
        string $action,
        int $years,
    ): Builder {
        $model = $owner->model();
        $query = $owner->modelClass::query();
        if ($owner->usesSoftDeletes() && ($action === 'anonymize' || $policy->applies_to_soft_deleted)) {
            $query->withTrashed();
        }
        if ($owner->usesSoftDeletes() && in_array($action, ['soft_delete', 'archive'], true)) {
            $query->whereNull($model->getTable().'.deleted_at');
        }

        $query->where(
            $model->getTable().'.'.$model->getCreatedAtColumn(),
            '<',
            now()->subYears($years),
        );
        $owner->applyConditions($query, $policy);

        return $query;
    }

    private function hasGlobalLegalHold(): bool
    {
        return LegalHold::query()->active()
            ->whereNull('holdable_type')
            ->whereNull('holdable_id')
            ->exists();
    }

    /** @return array<string, int> */
    private function ownershipEvidence(Model $record): array
    {
        if ($record instanceof Client) {
            $client = $record;
        } elseif (method_exists($record, 'client')) {
            $client = $record->client;
        } elseif (method_exists($record, 'stay')) {
            $client = $record->stay?->client;
        } else {
            $client = null;
        }

        if (! $client instanceof Client) {
            return [];
        }

        return array_filter([
            'client_id' => (int) $client->id,
            'site_id' => $client->site_id ? (int) $client->site_id : null,
        ], fn (?int $value): bool => $value !== null);
    }

    public function fingerprint(DataRetentionPolicy $policy): string
    {
        $contract = [
            'owner_key' => $policy->model_type,
            'retention_period_years' => $policy->retention_period_years,
            'archive_after_years' => $policy->archive_after_years,
            'hard_delete_after_years' => $policy->hard_delete_after_years,
            'retention_conditions' => $policy->retention_conditions,
            'applies_to_soft_deleted' => (bool) $policy->applies_to_soft_deleted,
            'legal_hold_exemption' => true,
            'active_case_exemption' => (bool) $policy->active_case_exemption,
            'active' => (bool) $policy->active,
        ];

        return hash('sha256', json_encode($this->normalize($contract), JSON_THROW_ON_ERROR));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            || (string) ($exception->errorInfo[0] ?? '') === '23505';
    }
}
