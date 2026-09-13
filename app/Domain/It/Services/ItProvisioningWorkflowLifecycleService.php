<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Changes canonical workflow dates/responsibility without rewriting the original work. */
final class ItProvisioningWorkflowLifecycleService
{
    public function __construct(
        private readonly ItProvisioningAccessService $access,
        private readonly ItProvisioningResponsibilityService $responsibility,
        private readonly ItProvisioningRequestLifecycleService $requests,
    ) {}

    public function lockContext(ItProvisioningWorkflow $workflow, User $actor): array
    {
        $currentWorkflow = ItProvisioningWorkflow::query()->findOrFail($workflow->id);
        HrEmployeeProfile::query()->whereKey($currentWorkflow->employee_profile_id)->lockForUpdate()->firstOrFail();
        $locked = ItProvisioningWorkflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if (! $this->access->canManageWorkflow($current, $locked)) {
            throw new AuthorizationException('This workflow is outside your current provisioning responsibility.');
        }
        if (! app(ItProvisioningReadinessService::class)->storageReady()) {
            throw new DomainException('Complete the reviewed provisioning history database update before changing this workflow.');
        }

        return [$locked, $current];
    }

    public function change(ItProvisioningWorkflow $workflow, User $actor, string $operation, array $data): ItProvisioningWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $operation, $data): ItProvisioningWorkflow {
            [$workflow, $actor] = $this->lockContext($workflow, $actor);
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw new DomainException('Explain the change and the work that needs to follow.');
            }
            if ($operation === 'assign') {
                [$owner, $cover] = $this->responsibility->pair(
                    isset($data['owner_user_id']) ? (int) $data['owner_user_id'] : null,
                    isset($data['cover_user_id']) ? (int) $data['cover_user_id'] : null,
                    $workflow->site_id_snapshot ?? $workflow->employeeProfile?->primary_site_id,
                );
                $before = ['owner_user_id' => $workflow->owner_user_id, 'cover_user_id' => $workflow->cover_user_id];
                $workflow->update(['owner_user_id' => $owner->id, 'cover_user_id' => $cover->id]);
                $this->record($workflow, $actor, 'responsibility_changed', ['before' => $before,
                    'owner_user_id' => $owner->id, 'cover_user_id' => $cover->id, 'reason' => $reason]);
            } elseif ($operation === 'reschedule') {
                if (in_array($workflow->source_type, ['hr_onboarding', 'hr_offboarding'], true)) {
                    throw new DomainException('Update the effective date in the canonical HR profile or offboarding checklist. Its unfinished IT work will be updated in the same change.');
                }
                if ($workflow->cancelled_at || $workflow->status === 'completed') {
                    throw new DomainException('Only an active workflow can change its effective date. Completed evidence keeps its recorded date.');
                }
                $effective = Carbon::parse($data['effective_date'])->startOfDay();
                $before = $workflow->effective_at;
                if (! $before) {
                    throw new DomainException('The original effective date is unavailable. Repair the source before rescheduling.');
                }
                $unfinished = $workflow->requests()->whereNotIn('status', ['done', 'cancelled'])->whereNull('reversal_of_request_id')->lockForUpdate()->get();
                if ($unfinished->isEmpty()) {
                    throw new DomainException('There are no unfinished original tasks to reschedule. Completed work and its corrective tasks keep their recorded dates.');
                }
                foreach ($unfinished as $request) {
                    $offset = $request->due_offset_days;
                    if ($offset === null && $request->due_date) {
                        $offset = (int) $before->copy()->startOfDay()->diffInDays($request->due_date->copy()->startOfDay(), false);
                    }
                    $values = ['due_offset_days' => $offset, 'due_date' => $offset !== null ? $effective->copy()->addDays($offset)->toDateString() : null];
                    if ($request->approval_required && in_array($request->approval_status, ['pending', 'approved'], true)) {
                        $values['approval_status'] = 'cancelled';
                        ItTicketEvent::record($request, 'approval_withdrawn', $actor->id, ['reason' => 'Effective date changed: '.$reason]);
                    }
                    $oldDate = $request->due_date?->toDateString();
                    $request->update($values);
                    ItTicketEvent::record($request, 'rescheduled', $actor->id, ['from' => $oldDate, 'to' => $values['due_date'], 'reason' => $reason]);
                }
                $workflow->update(['original_effective_at' => $workflow->original_effective_at ?? $before, 'effective_at' => $effective]);
                $this->record($workflow, $actor, 'rescheduled', ['from' => $before->toIso8601String(), 'to' => $effective->toIso8601String(), 'reason' => $reason]);
            } elseif ($operation === 'cancel') {
                if ($workflow->cancelled_at) {
                    throw new DomainException('This workflow is already cancelled. Review its history and corrective work.');
                }
                foreach ($workflow->requests()->whereNotIn('status', ['done', 'cancelled'])->whereNull('reversal_of_request_id')->lockForUpdate()->get() as $request) {
                    $this->requests->cancel($request, $actor, $reason);
                }
                $workflow->refresh()->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
                $this->record($workflow, $actor, 'cancelled', ['reason' => $reason, 'completed_work_retained' => $workflow->requests()->where('status', 'done')->count()]);
                if (! empty($data['create_reversals'])) {
                    $this->reverseCompleted($workflow, $actor, $reason, true);
                }
            } elseif ($operation === 'reverse') {
                $this->reverseCompleted($workflow, $actor, $reason);
            } else {
                throw new DomainException('Unsupported provisioning workflow action.');
            }

            return $workflow->refresh();
        }, 3);
    }

    private function reverseCompleted(ItProvisioningWorkflow $workflow, User $actor, string $reason, bool $allowExisting = false): void
    {
        $originals = $workflow->requests()->where('status', 'done')->whereNull('reversal_of_request_id')->lockForUpdate()->get();
        if ($originals->isEmpty()) {
            if ($allowExisting) {
                return;
            }
            throw new DomainException('There is no completed original work to reverse.');
        }
        $reversals = $workflow->requests()->whereNotNull('reversal_of_request_id')->get()->keyBy('reversal_of_request_id');
        $created = [];
        $maximumStage = (int) $originals->max('stage');
        foreach ($originals as $original) {
            if ($reversals->has($original->id)) {
                continue;
            }
            $reversal = $workflow->requests()->create([
                'employee_profile_id' => $workflow->employee_profile_id,
                'type' => $original->type, 'category' => $original->category,
                'task_key' => 'reverse-'.$original->id, 'item' => 'Reverse: '.$original->item,
                'action' => match ($original->action) {
                    'grant' => 'revoke', 'revoke', 'recover' => 'grant', default => 'change'
                },
                'stage' => $maximumStage - (int) $original->stage + 1, 'dependency_request_ids' => [],
                'responsible_team_id' => $original->responsible_team_id,
                'status' => 'pending', 'priority' => $original->priority,
                'approval_required' => true, 'approval_status' => 'pending', 'evidence_required' => true,
                'reversal_of_request_id' => $original->id, 'due_date' => today()->toDateString(),
                'notes' => 'Review and explicitly reverse the recorded action. '.$reason,
                'fulfiller_context' => ['original_request_id' => (int) $original->id,
                    'original_target_type' => $original->canonical_target_type,
                    'original_target_id' => $original->canonical_target_id],
                'created_by' => $actor->id,
            ]);
            $reversals->put($original->id, $reversal);
            $created[] = $original->id;
            ItTicketEvent::record($reversal, 'created', $actor->id, ['source' => 'explicit_reversal', 'original_request_id' => $original->id, 'reason' => $reason]);
            ItTicketEvent::record($original, 'reversal_requested', $actor->id, ['reversal_request_id' => $reversal->id, 'reason' => $reason]);
        }
        foreach ($created as $originalId) {
            $dependencies = $originals->filter(fn (ItProvisioningRequest $candidate) => in_array((int) $originalId,
                array_map('intval', $candidate->dependency_request_ids ?? []), true))
                ->map(fn (ItProvisioningRequest $candidate) => $reversals->get($candidate->id)->id)->values()->all();
            $reversals->get($originalId)->update(['dependency_request_ids' => $dependencies]);
        }
        if ($created === []) {
            if ($allowExisting) {
                return;
            }
            throw new DomainException('Corrective tasks already exist for all completed work. Continue on those tasks.');
        }
        $this->record($workflow, $actor, 'reversal_requested', ['original_request_ids' => $created, 'reason' => $reason]);
        $this->requests->reconcileWorkflow($workflow);
    }

    private function record(ItProvisioningWorkflow $workflow, User $actor, string $event, array $data): void
    {
        $workflow->events()->create(['type' => $event, 'actor_user_id' => $actor->id, 'payload' => $data]);
        AuditLogger::logOrFail('it.provisioning.workflow.'.$event, $workflow, ['actor_id' => $actor->id, ...$data]);
    }
}
