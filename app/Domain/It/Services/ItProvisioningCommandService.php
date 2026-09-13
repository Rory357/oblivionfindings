<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicketCommandReceipt;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Actor-bound receipts reuse the canonical IT command inventory. */
final class ItProvisioningCommandService
{
    public const REQUEST_ACTIONS = ['assign', 'request_approval', 'withdraw_approval', 'approve', 'reject', 'fulfil', 'fail', 'retry', 'cancel', 'reopen'];

    public const WORKFLOW_ACTIONS = ['assign', 'reschedule', 'cancel', 'reverse'];

    public function __construct(
        private readonly ItProvisioningAccessService $access,
        private readonly ItProvisioningRequestLifecycleService $requests,
        private readonly ItProvisioningWorkflowLifecycleService $workflows,
    ) {}

    public function execute(User $actor, string $kind, int $targetId, string $operation, array $input): array
    {
        $input = $this->validate($kind, $operation, $input, true);
        $payload = Arr::except($input, ['actor_user_id', 'request_uuid', 'expected_version']);
        $hash = hash('sha256', json_encode([$kind, $targetId, $operation, (int) $input['expected_version'], Arr::sortRecursive($payload)], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $kind, $targetId, $operation, $input, $payload, $hash): array {
            $retained = $this->retainedCreationResult($actor, $kind, $targetId, $operation, $input, $hash);
            if ($retained !== null) {
                return $retained;
            }
            [$target, $actor] = $this->context($actor, $kind, $targetId, (int) $input['actor_user_id']);
            $receipt = $this->receipt($actor, $kind, $operation, $input['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                $result = $this->result($receipt, $actor, $kind, $targetId, $operation);
                abort_if($result['status'] !== 'cancelled' && ! hash_equals($receipt->request_hash, $hash), 409,
                    'This command already belongs to different details. Check its original outcome.');

                return $result;
            }
            $version = in_array($kind, ['launch', 'manual'], true) ? 1 : (int) $target->lock_version;
            abort_unless((int) $input['expected_version'] === $version, 409, 'This work changed. Reload and review the current version before making a new decision.');
            $receipt = ItTicketCommandReceipt::query()->create([
                'actor_user_id' => $actor->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                'operation' => 'provisioning.'.$kind.'.'.$operation, 'request_uuid' => strtolower($input['request_uuid']),
                'request_hash' => $hash,
            ]);
            if ($kind === 'manual') {
                $changed = $this->requests->createManual($actor, $target,
                    ! empty($payload['assigned_to_user_id']) ? User::query()->findOrFail((int) $payload['assigned_to_user_id']) : null, $payload);
            } elseif ($kind === 'template') {
                $changed = app(ItProvisioningTemplatePublicationService::class)->publish($target, $actor, $operation === 'publish', $payload + ['expected_version' => (int) $input['expected_version']]);
            } elseif ($kind === 'workflow') {
                $changed = $this->workflows->change($target, $actor, $operation, $payload);
            } elseif ($kind === 'launch') {
                $changed = app(ItProvisioningWorkflowService::class)->launch(
                    profile: $target, lifecycleType: $payload['lifecycle_type'], sourceType: 'manual', sourceId: $target->id,
                    sourceEventKey: 'manual:'.$actor->id.':'.strtolower($input['request_uuid']), actorId: $actor->id,
                    effectiveAt: Carbon::parse($payload['effective_date']),
                    templateVersionId: (int) $payload['template_version_id'],
                );
                $changed = $this->workflows->change($changed, $actor, 'assign', $payload);
            } else {
                $changed = match ($operation) {
                    'assign' => $this->assign($target, $actor, (int) $payload['assigned_to_user_id'], $payload['reason'] ?? null),
                    'request_approval' => $this->requests->requestApproval($target, $actor, $payload + [
                        'approval_expires_at' => Carbon::createFromFormat('!Y-m-d', $payload['approval_expires_on'], 'Pacific/Auckland')->endOfDay()->toIso8601String(),
                    ]),
                    'withdraw_approval' => $this->requests->withdrawApproval($target, $actor, $payload['reason']),
                    'approve', 'reject' => $this->requests->decide($target, $actor, $operation === 'approve' ? 'approved' : 'rejected', $payload['reason'] ?? null),
                    'fulfil' => $this->requests->fulfil($target, $actor, $payload),
                    'fail' => $this->requests->fail($target, $actor, $payload['reason']),
                    'retry' => $this->requests->retry($target, $actor, $payload['reason']),
                    'cancel' => $this->requests->cancel($target, $actor, $payload['reason']),
                    'reopen' => $this->requests->reopen($target, $actor, $payload['reason']),
                };
            }
            $receipt->update(['committed_at' => now(), 'result_metadata' => [
                'state' => 'committed', 'kind' => $kind, 'target_id' => $targetId,
                'result_id' => (int) $changed->id, 'lock_version' => (int) $changed->lock_version,
                'changed' => in_array($kind, ['manual', 'launch', 'template'], true) || (int) $changed->lock_version !== $version,
            ]]);

            return $this->result($receipt, $actor, $kind, $targetId, $operation, false);
        }, 3);
    }

    public function lookup(User $actor, string $kind, int $targetId, string $operation, array $input, bool $cancel = false): array
    {
        $input = $this->validate($kind, $operation, $input, false);

        return DB::transaction(function () use ($actor, $kind, $targetId, $operation, $input, $cancel): array {
            $retained = $this->retainedCreationResult($actor, $kind, $targetId, $operation, $input);
            if ($retained !== null) {
                return $retained;
            }
            [$target, $actor] = $this->context($actor, $kind, $targetId, (int) $input['actor_user_id']);
            $receipt = $this->receipt($actor, $kind, $operation, $input['request_uuid'])->lockForUpdate()->first();
            if (! $receipt && $cancel) {
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $actor->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                    'operation' => 'provisioning.'.$kind.'.'.$operation, 'request_uuid' => strtolower($input['request_uuid']),
                    'request_hash' => hash('sha256', json_encode(['cancelled', $kind, $targetId, $operation], JSON_THROW_ON_ERROR)),
                    'result_metadata' => ['state' => 'cancelled', 'kind' => $kind, 'target_id' => $targetId],
                ]);
                AuditLogger::logOrFail('it.provisioning.command.cancelled', $target, ['actor_id' => $actor->id,
                    'operation' => $operation, 'request_uuid' => $receipt->request_uuid]);
            }
            if ($receipt) {
                return $this->result($receipt, $actor, $kind, $targetId, $operation);
            }

            return ['status' => 'not_found', 'data' => $this->identity($actor, $kind, $targetId, $operation, strtolower($input['request_uuid'])) + ['retry_same_command' => true]];
        }, 3);
    }

    /** Retained work is authorized by its current canonical result, even after its employee leaves. */
    private function retainedCreationResult(User $actor, string $kind, int $targetId, string $operation, array $input, ?string $hash = null): ?array
    {
        if (! in_array($kind, ['manual', 'launch'], true)) {
            return null;
        }
        abort_unless((int) $actor->id === (int) $input['actor_user_id'], 403, 'Use the original account to recover this command.');
        // Serialize creation before opening a repeatable-read snapshot. A worker
        // that waited for another creation must see both its receipt and result,
        // rather than a new receipt followed by an older, empty result snapshot.
        HrEmployeeProfile::query()->whereKey($targetId)->lockForUpdate()->first(['id']);
        $receipt = $this->receipt($actor, $kind, $operation, $input['request_uuid'])->first();
        if (! $receipt || $receipt->committed_at === null) {
            return null;
        }
        $receipt = ItTicketCommandReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->approved_at !== null && $actor->canDo('it.manage')
            && app(HrCurrentStaffService::class)->isCurrent($actor), 404);
        $result = $this->result($receipt, $actor, $kind, $targetId, $operation);
        abort_if($hash !== null && ! hash_equals($receipt->request_hash, $hash), 409,
            'This command already belongs to different details. Check its original outcome.');

        return $result;
    }

    private function context(User $actor, string $kind, int $id, int $originalActorId): array
    {
        abort_unless((int) $actor->id === $originalActorId, 403, 'The signed-in account changed. Use the original account to recover this command.');
        if ($kind === 'workflow') {
            return $this->workflows->lockContext(ItProvisioningWorkflow::query()->findOrFail($id), $actor);
        }
        if ($kind === 'template') {
            $target = ItProvisioningTemplate::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        } elseif ($kind === 'request') {
            $target = ItProvisioningRequest::query()->findOrFail($id);
            HrEmployeeProfile::query()->whereKey($target->employee_profile_id)->lockForUpdate()->firstOrFail();
            if ($target->provisioning_workflow_id) {
                ItProvisioningWorkflow::query()->whereKey($target->provisioning_workflow_id)->lockForUpdate()->firstOrFail();
            }
            $target = ItProvisioningRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        } else {
            $target = HrEmployeeProfile::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        }
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $permitted = match ($kind) {
            'template' => app(ItProvisioningTemplatePublicationService::class)->canView($actor, $target),
            'request' => $this->access->canManage($actor, $target),
            default => $this->access->canSelectProfile($actor, $target),
        };
        abort_unless($actor->approved_at !== null && $permitted, 404);
        if (! app(ItProvisioningReadinessService::class)->storageReady()) {
            throw new DomainException('Complete the reviewed provisioning history database update before using this command.');
        }

        return [$target, $actor];
    }

    private function assign(ItProvisioningRequest $request, User $actor, int $assigneeId, ?string $reason): ItProvisioningRequest
    {
        $this->requests->assign($request, $actor, User::query()->findOrFail($assigneeId), reason: $reason);

        return $request->refresh();
    }

    private function receipt(User $actor, string $kind, string $operation, string $uuid)
    {
        return ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)->where('channel', ItTicketCommandReceipt::CHANNEL)
            ->where('operation', 'provisioning.'.$kind.'.'.$operation)->where('request_uuid', strtolower($uuid));
    }

    private function result(ItTicketCommandReceipt $receipt, User $actor, string $kind, int $targetId, string $operation, bool $replayed = true): array
    {
        $data = $receipt->result_metadata ?? [];
        abort_unless(($data['kind'] ?? null) === $kind && ($data['target_id'] ?? null) === $targetId, 409, 'This command belongs to another record.');
        $identity = $this->identity($actor, $kind, $targetId, $operation, $receipt->request_uuid);
        if (($data['state'] ?? null) === 'cancelled' && $receipt->committed_at === null) {
            return ['status' => 'cancelled', 'data' => $identity];
        }
        abort_unless($receipt->committed_at !== null && ($data['state'] ?? null) === 'committed' && (int) ($data['result_id'] ?? 0) > 0, 409, 'The command outcome cannot yet be confirmed.');
        $resultId = (int) $data['result_id'];
        if ($kind === 'manual') {
            $result = ItProvisioningRequest::query()->findOrFail($resultId);
            abort_unless($this->access->canManage($actor, $result), 404);
        } elseif ($kind === 'launch') {
            $result = ItProvisioningWorkflow::query()->findOrFail($resultId);
            abort_unless($this->access->canManageWorkflow($actor, $result), 404);
        }

        return ['status' => 'committed', 'data' => $identity + [
            'result_id' => $resultId, 'lock_version' => (int) $data['lock_version'], 'replayed' => $replayed,
            'changed' => (bool) ($data['changed'] ?? true),
            'url' => '/it/provisioning/'.match ($kind) {
                'request', 'manual' => 'tasks', 'template' => 'templates', default => 'workflows'
            }.'/'.$resultId,
        ]];
    }

    private function identity(User $actor, string $kind, int $id, string $operation, string $uuid): array
    {
        return ['viewer_user_id' => (int) $actor->id, 'kind' => $kind, 'target_id' => $id, 'operation' => $operation, 'request_uuid' => $uuid];
    }

    private function validate(string $kind, string $operation, array $input, bool $write): array
    {
        $actions = match ($kind) {
            'request' => self::REQUEST_ACTIONS, 'workflow' => self::WORKFLOW_ACTIONS, 'launch' => ['launch'], 'manual' => ['create'], 'template' => ['publish', 'unpublish'], default => []
        };
        abort_unless(in_array($operation, $actions, true), 404);
        $rules = ['actor_user_id' => ['required', 'integer', 'min:1'], 'request_uuid' => ['required', 'uuid']];
        if ($write) {
            $rules += ['expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:5000'],
                'evidence_summary' => ['nullable', 'string', 'max:5000'], 'external_ref' => ['nullable', 'string', 'max:255'],
                'canonical_target_type' => ['nullable', 'required_with:canonical_target_id', Rule::in(['identity', 'asset_assignment', 'device_assignment'])],
                'canonical_target_id' => ['nullable', 'required_with:canonical_target_type', 'integer', 'min:1'], 'create_reversals' => ['sometimes', 'boolean']];
            if (in_array($kind, ['workflow', 'launch', 'template'], true) || in_array($operation, ['withdraw_approval', 'reject', 'fail', 'retry', 'cancel', 'reopen'], true)) {
                $rules['reason'] = ['required', 'string', 'max:5000'];
            }
            if ($kind === 'template') {
                $rules['expected_published_version_id'] = ['present', 'nullable', 'integer', 'min:1'];
            }
            if ($kind === 'manual') {
                $rules += ['type' => ['required', Rule::in(ItProvisioningRequest::TYPES)], 'item' => ['required', 'string', 'max:255'],
                    'priority' => ['required', Rule::in(ItProvisioningRequest::PRIORITIES)], 'notes' => ['nullable', 'string', 'max:5000'],
                    'due_date' => ['nullable', 'date_format:Y-m-d'], 'assigned_to_user_id' => ['nullable', 'integer', 'min:1']];
            }
            if ($kind === 'launch' || ($kind === 'workflow' && $operation === 'assign')) {
                $rules += ['owner_user_id' => ['required', 'integer', 'min:1'], 'cover_user_id' => ['required', 'integer', 'min:1']];
            }
            if ($kind === 'launch' || $operation === 'reschedule') {
                $rules['effective_date'] = ['required', 'date_format:Y-m-d'];
            }
            if ($kind === 'launch') {
                $rules += ['template_version_id' => ['required', 'integer', 'min:1'], 'lifecycle_type' => ['required', Rule::in(['joiner', 'mover', 'leaver'])],
                    'changes' => ['sometimes', 'array:position_role,primary_site_id,employment_type'],
                    'changes.*' => ['array:from,to']];
            }
            if ($kind === 'request' && $operation === 'assign') {
                $rules['assigned_to_user_id'] = ['required', 'integer', 'min:1'];
            }
            if ($operation === 'request_approval') {
                $rules += ['primary_approver_user_id' => ['required', 'integer', 'min:1'], 'cover_approver_user_id' => ['required', 'integer', 'min:1'],
                    'approval_expires_on' => ['required', 'date_format:Y-m-d']];
            }
        }
        $validated = Validator::make($input, $rules)->validate();
        $validated['request_uuid'] = strtolower($validated['request_uuid']);

        return $validated;
    }
}
