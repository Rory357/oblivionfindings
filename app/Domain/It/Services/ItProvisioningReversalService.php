<?php

namespace App\Domain\It\Services;

use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Explicit corrective work for completed provisioning. A reversal is always a
 * new approval- and evidence-gated task bound to its original; the original
 * record and its evidence are never edited. One reversal per original.
 */
final class ItProvisioningReversalService
{
    /**
     * @param  Collection<int, ItProvisioningRequest>|null  $originals  completed originals to reverse; null = every completed original in the workflow
     * @return list<int> original request ids that received a new reversal task
     */
    public function reverse(
        ItProvisioningWorkflow $workflow,
        User $actor,
        string $reason,
        ?Collection $originals = null,
        bool $allowExisting = false,
        string $source = 'explicit_reversal',
    ): array {
        $originals ??= $workflow->requests()->where('status', 'done')->whereNull('reversal_of_request_id')->lockForUpdate()->get();
        if ($originals->isEmpty()) {
            if ($allowExisting) {
                return [];
            }
            throw new DomainException('There is no completed original work to reverse.');
        }
        foreach ($originals as $original) {
            if ($original->status !== 'done' || $original->reversal_of_request_id !== null
                || (int) $original->provisioning_workflow_id !== (int) $workflow->id) {
                throw new DomainException('Only completed original tasks in this workflow can be reversed.');
            }
        }
        $reversals = $workflow->requests()->whereNotNull('reversal_of_request_id')->get()->keyBy('reversal_of_request_id');
        $created = [];
        $maximumStage = (int) $workflow->requests()->whereNull('reversal_of_request_id')->max('stage');
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
                'stage' => max(1, $maximumStage - (int) $original->stage + 1), 'dependency_request_ids' => [],
                'responsible_team_id' => $original->responsible_team_id,
                'assigned_to_user_id' => $original->assigned_to_user_id,
                'status' => $original->assigned_to_user_id ? 'in_progress' : 'pending', 'priority' => $original->priority,
                'approval_required' => true, 'approval_status' => 'pending', 'evidence_required' => true,
                'reversal_of_request_id' => $original->id, 'due_date' => today()->toDateString(),
                'notes' => 'Review and explicitly reverse the recorded action. '.$reason,
                'fulfiller_context' => ['original_request_id' => (int) $original->id,
                    'original_target_type' => $original->canonical_target_type,
                    'original_target_id' => $original->canonical_target_id],
                'created_by' => $actor->id,
            ]);
            $reversals->put($original->id, $reversal);
            $created[] = (int) $original->id;
            ItTicketEvent::record($reversal, 'created', $actor->id, ['source' => $source, 'original_request_id' => $original->id, 'reason' => $reason]);
            ItTicketEvent::record($original, 'reversal_requested', $actor->id, ['reversal_request_id' => $reversal->id, 'reason' => $reason]);
        }
        // A reversal depends on the reversals of the originals that depended on
        // its original, so grants are undone in reverse dependency order.
        $allOriginals = $workflow->requests()->whereNull('reversal_of_request_id')->get();
        foreach ($created as $originalId) {
            $dependencies = $allOriginals->filter(fn (ItProvisioningRequest $candidate) => in_array((int) $originalId,
                array_map('intval', $candidate->dependency_request_ids ?? []), true))
                ->map(fn (ItProvisioningRequest $candidate) => $reversals->get($candidate->id)?->id)->filter()->values()->all();
            $reversals->get($originalId)->update(['dependency_request_ids' => $dependencies]);
        }
        if ($created === []) {
            if ($allowExisting) {
                return [];
            }
            throw new DomainException('Corrective tasks already exist for all completed work. Continue on those tasks.');
        }
        $workflow->events()->create(['type' => 'reversal_requested', 'actor_user_id' => $actor->id,
            'payload' => ['original_request_ids' => $created, 'reason' => $reason, 'source' => $source]]);
        AuditLogger::logOrFail('it.provisioning.workflow.reversal_requested', $workflow,
            ['actor_id' => $actor->id, 'original_request_ids' => $created, 'reason' => $reason, 'source' => $source]);
        $routing = app(ItProvisioningApprovalRoutingService::class);
        foreach ($created as $originalId) {
            $routing->route($reversals->get($originalId), $actor, 'Corrective work: '.$reason, source: $source);
        }
        app(ItProvisioningRequestLifecycleService::class)->reconcileWorkflow($workflow);
        app(ItProvisioningNotifier::class)->reversalRequested($workflow, $actor);

        return $created;
    }
}
