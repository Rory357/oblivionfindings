<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Enums\ItWorkType;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ItWorkTransitionService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /**
     * Allowed type-specific state changes. Shared legacy routes are adapted
     * separately so existing deep links remain compatible while every write
     * still receives the same transactional gates and audit.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const TRANSITIONS = [
        'incident' => [
            'submitted' => ['triaged', 'in_progress', 'waiting', 'resolved', 'closed'],
            'triaged' => ['in_progress', 'waiting', 'resolved', 'closed'],
            'in_progress' => ['waiting', 'resolved', 'closed'],
            'waiting' => ['in_progress', 'resolved', 'closed'],
            'resolved' => ['closed', 'submitted'],
            'closed' => ['submitted'],
        ],
        'service_request' => [
            'submitted' => ['approval_pending', 'fulfilling', 'waiting', 'fulfilled', 'cancelled', 'closed'],
            'approval_pending' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['fulfilling', 'cancelled'],
            'fulfilling' => ['waiting', 'fulfilled', 'cancelled'],
            'waiting' => ['fulfilling', 'fulfilled', 'cancelled'],
            'fulfilled' => ['closed', 'submitted'],
            'rejected' => ['closed'],
            'cancelled' => ['closed'],
            'closed' => ['submitted'],
        ],
        'security_request' => [
            'submitted' => ['approval_pending', 'approved', 'fulfilling', 'waiting', 'rejected', 'cancelled'],
            'approval_pending' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['fulfilling', 'cancelled'],
            'fulfilling' => ['waiting', 'fulfilled', 'cancelled'],
            'waiting' => ['fulfilling', 'fulfilled', 'cancelled'],
            'fulfilled' => ['closed', 'submitted'],
            'rejected' => ['closed'],
            'cancelled' => ['closed'],
            'closed' => ['submitted'],
        ],
        'problem' => [
            'submitted' => ['investigating', 'closed'],
            'investigating' => ['waiting', 'known_error', 'resolved', 'closed'],
            'waiting' => ['investigating', 'resolved', 'closed'],
            'known_error' => ['investigating', 'resolved', 'closed'],
            'resolved' => ['closed', 'submitted'],
            'closed' => ['submitted'],
        ],
        'change' => [
            'draft' => ['assessment', 'cancelled'],
            'assessment' => ['approval_pending', 'approved', 'cancelled'],
            'approval_pending' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['scheduled', 'implementing', 'cancelled'],
            'scheduled' => ['implementing', 'cancelled'],
            'implementing' => ['validation', 'failed', 'backed_out'],
            'validation' => ['completed', 'failed', 'backed_out'],
            'completed' => ['review', 'closed'],
            'failed' => ['review', 'closed'],
            'backed_out' => ['review', 'closed'],
            'review' => ['closed'],
            'rejected' => ['closed'],
            'cancelled' => ['closed'],
            'closed' => ['draft'],
        ],
        'task' => [
            'submitted' => ['in_progress', 'waiting', 'completed', 'cancelled', 'closed'],
            'in_progress' => ['waiting', 'completed', 'cancelled'],
            'waiting' => ['in_progress', 'completed', 'cancelled'],
            'completed' => ['closed', 'submitted'],
            'cancelled' => ['closed'],
            'closed' => ['submitted'],
        ],
        'major_incident' => [
            'declared' => ['responding', 'monitoring', 'restored', 'resolved', 'closed'],
            'responding' => ['monitoring', 'restored', 'resolved', 'closed'],
            'monitoring' => ['responding', 'restored', 'resolved', 'closed'],
            'restored' => ['resolved', 'review', 'closed'],
            'resolved' => ['responding', 'review', 'closed'],
            'review' => ['closed'],
            'closed' => ['declared'],
        ],
    ];

    public function transition(ItTicket $ticket, ItTransitionInput $input): ItTicket
    {
        return DB::transaction(function () use ($ticket, $input): ItTicket {
            $locked = ItTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            $from = $this->currentState($locked);
            $to = $input->to->value;

            $this->authorizeActor($locked, $input, $from);

            if ($from === $to) {
                return $locked;
            }

            if (! $this->isAllowed($locked, $input, $from, $to)) {
                throw new DomainException("The {$from} to {$to} transition is not allowed for this work type.");
            }

            $targetStatus = $this->normalizedStatus($input->to);
            $this->guardWaiting($targetStatus, $input);
            $this->guardSettlement($locked, $input);

            $fromStatus = (string) $locked->status;
            $this->applyState($locked, $input, $targetStatus);
            $locked->save();

            ItTicketEvent::record(
                $locked,
                $this->eventType($input->source),
                $input->actor->id,
                [
                    'from' => $fromStatus,
                    'to' => $locked->status,
                    'from_workflow_state' => $from,
                    'to_workflow_state' => $to,
                    'reason' => $input->reason,
                    'via' => $input->source,
                ],
            );

            return $locked->refresh();
        });
    }

    private function currentState(ItTicket $ticket): string
    {
        $state = (string) ($ticket->workflow_state ?: '');

        if ($ticket->status === 'waiting' && $state !== ItWorkflowState::ApprovalPending->value) {
            return ItWorkflowState::Waiting->value;
        }

        if ($ticket->status === 'resolved'
            && ! in_array($state, ['resolved', 'fulfilled', 'completed'], true)) {
            return ItWorkflowState::Resolved->value;
        }

        if ($ticket->status === 'closed'
            && ! in_array($state, ['closed', 'rejected', 'cancelled', 'failed', 'backed_out'], true)) {
            return ItWorkflowState::Closed->value;
        }

        if ($state !== '') {
            return $state;
        }

        return match ((string) $ticket->status) {
            'in_progress' => ItWorkflowState::InProgress->value,
            'waiting' => ItWorkflowState::Waiting->value,
            'resolved' => ItWorkflowState::Resolved->value,
            'closed' => ItWorkflowState::Closed->value,
            default => ItWorkflowState::Submitted->value,
        };
    }

    private function authorizeActor(ItTicket $ticket, ItTransitionInput $input, string $from): void
    {
        $requesterReply = $input->source === 'requester_reply'
            && (int) $ticket->requester_user_id === (int) $input->actor->id
            && $from === ItWorkflowState::Waiting->value
            && $input->to === ItWorkflowState::InProgress;

        $requesterReopen = in_array($input->source, ['reopen', 'legacy_reopen'], true)
            && $input->actor->can('reopen', $ticket);

        if (! $requesterReply
            && ! $requesterReopen
            && ! $this->workAccess->canWork($input->actor, $ticket)) {
            throw new DomainException('The actor is not allowed to transition this work item.');
        }
    }

    private function isAllowed(ItTicket $ticket, ItTransitionInput $input, string $from, string $to): bool
    {
        if ($input->source === 'requester_reply') {
            return $from === 'waiting' && $to === 'in_progress';
        }

        if (in_array($input->source, ['legacy_resolve'], true)) {
            return ! in_array($from, ['resolved', 'fulfilled', 'completed', 'closed'], true)
                && $to === 'resolved';
        }

        if (in_array($input->source, ['legacy_close', 'bulk_close'], true)) {
            return $from !== 'closed' && $to === 'closed';
        }

        if (in_array($input->source, ['legacy_reopen'], true)) {
            return in_array($from, ['resolved', 'fulfilled', 'completed', 'closed'], true)
                && $to === 'submitted';
        }

        if (in_array($input->source, ['legacy_status', 'bulk_status'], true)) {
            return in_array($to, ['submitted', 'in_progress', 'waiting'], true)
                && in_array((string) $ticket->status, ItTicket::OPEN_STATUSES, true);
        }

        $workType = ItWorkType::tryFrom((string) $ticket->work_type);
        if (! $workType || $workType === ItWorkType::Provisioning) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$workType->value][$from] ?? [], true);
    }

    private function normalizedStatus(ItWorkflowState $state): string
    {
        return match ($state) {
            ItWorkflowState::Submitted,
            ItWorkflowState::Draft,
            ItWorkflowState::Declared => 'open',

            ItWorkflowState::Waiting,
            ItWorkflowState::ApprovalPending => 'waiting',

            ItWorkflowState::Resolved,
            ItWorkflowState::Fulfilled,
            ItWorkflowState::Completed => 'resolved',

            ItWorkflowState::Closed,
            ItWorkflowState::Rejected,
            ItWorkflowState::Cancelled,
            ItWorkflowState::Failed,
            ItWorkflowState::BackedOut => 'closed',

            default => 'in_progress',
        };
    }

    private function guardWaiting(string $targetStatus, ItTransitionInput $input): void
    {
        if ($targetStatus !== 'waiting') {
            return;
        }

        if (blank($input->waitingParty) || blank($input->reason)) {
            throw new DomainException('A waiting party and reason are required before pausing work.');
        }
    }

    private function guardSettlement(ItTicket $ticket, ItTransitionInput $input): void
    {
        $settles = in_array($input->to, [
            ItWorkflowState::Resolved,
            ItWorkflowState::Fulfilled,
            ItWorkflowState::Completed,
            ItWorkflowState::Closed,
        ], true);

        if (! $settles) {
            return;
        }

        if ($ticket->requires_approval && $ticket->approvalState() !== 'approved') {
            throw new DomainException('Required approval must be approved before settlement.');
        }

        if ($ticket->tasks()->where('is_required', true)->where('status', '!=', 'completed')->exists()) {
            throw new DomainException('All required tasks must be completed before settlement.');
        }

        $requiresResolutionEvidence = $input->to !== ItWorkflowState::Closed;
        if ($requiresResolutionEvidence
            && (blank($input->resolutionCode) || blank($input->resolutionSummary))) {
            throw new DomainException('A resolution code and summary are required before settlement.');
        }
    }

    private function applyState(ItTicket $ticket, ItTransitionInput $input, string $targetStatus): void
    {
        $wasSettled = in_array((string) $ticket->status, ['resolved', 'closed'], true);
        $isReopen = in_array($input->source, ['reopen', 'legacy_reopen'], true);

        if ($targetStatus === 'waiting') {
            if ($ticket->status !== 'waiting') {
                $ticket->startWaiting();
            }
            $ticket->waiting_party = $input->waitingParty;
            $ticket->waiting_reason = $input->reason;
        } else {
            if ($ticket->status === 'waiting') {
                $ticket->stopWaiting($targetStatus);
            } else {
                $ticket->status = $targetStatus;
            }
            $ticket->waiting_party = null;
            $ticket->waiting_reason = null;
        }

        $ticket->workflow_state = $input->to->value;
        $ticket->status_reason = $input->reason;
        $ticket->next_action = $input->nextAction;

        if (in_array($input->to, [
            ItWorkflowState::Resolved,
            ItWorkflowState::Fulfilled,
            ItWorkflowState::Completed,
        ], true)) {
            $ticket->resolved_at = now();
            $ticket->resolution_code = $input->resolutionCode;
            $ticket->resolution_summary = $input->resolutionSummary;
            $ticket->first_responded_at ??= now();

            if ($ticket->resolution_due_at
                && now()->lte($ticket->resolution_due_at->copy()->addMinutes((int) $ticket->sla_paused_minutes))) {
                $ticket->sla_state = 'met';
            }
        }

        if ($input->to === ItWorkflowState::Closed) {
            $ticket->closed_at = now();
        }

        if ($isReopen && $wasSettled) {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
            $ticket->resolution_code = null;
            $ticket->resolution_summary = null;
            $ticket->reopened_count = (int) $ticket->reopened_count + 1;
            $ticket->sla_state = 'ok';
        }
    }

    private function eventType(string $source): string
    {
        return match ($source) {
            'legacy_resolve' => 'resolved',
            'legacy_close', 'bulk_close' => 'closed',
            'legacy_reopen' => 'reopened',
            'legacy_status', 'bulk_status', 'requester_reply' => 'status_changed',
            default => 'workflow_transitioned',
        };
    }
}
