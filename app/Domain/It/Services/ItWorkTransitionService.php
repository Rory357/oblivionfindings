<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTicketResolutionInput;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Enums\ItWorkType;
use App\Domain\It\Exceptions\ItSettlementBlocked;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ItWorkTransitionService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItTicketVersionService $versions,
        private readonly ItWorkTaskReadinessService $taskReadiness,
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
            $input = $input->withActor($this->versions->currentActor($input->actor, $input->source === 'service_api'));

            $from = $this->currentState($locked);
            $to = $input->to->value;
            $isReopen = $this->isReopening($locked, $input->to);

            $this->authorizeActor($locked, $input, $from);
            $this->versions->assertCurrent($locked, $input->expectedVersion);

            if ($input->to === ItWorkflowState::Closed) {
                if ($locked->isMerged()) {
                    throw new DomainException('This ticket was merged and cannot be closed again.');
                }
                if ($locked->status === 'closed') {
                    throw new DomainException('This ticket is already closed.');
                }
                $reason = $input->source === 'requester_confirmation'
                    ? 'Requester confirmed the fix'
                    : trim($input->reason ?? '');
                if ($reason === '' || mb_strlen($reason) > 1000) {
                    throw new DomainException('Record a reason of up to 1000 characters before closing the ticket.');
                }
                $input = $input->withReason($reason);
            }

            if (in_array($input->source, ['reopen', 'legacy_reopen'], true) && ! $isReopen) {
                throw new DomainException('Only settled work can return through the reopen action.');
            }
            if ($isReopen) {
                if ($locked->isMerged()) {
                    throw new DomainException('Reopen the surviving ticket instead.');
                }
                $reason = trim($input->reason ?? '');
                if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) {
                    throw new DomainException('Explain what still needs attention in 5 to 2000 characters before reopening the ticket.');
                }
                $input = $input->withReason($reason);
            }

            if ($from === $to) {
                if ($to === ItWorkflowState::Waiting->value
                    && ($input->waitingParty !== null || $input->reason !== null || $input->nextAction !== null)) {
                    return $this->reviseWaitingContext($locked, $input);
                }

                return $locked;
            }

            if (! $this->isAllowed($locked, $input, $from, $to)) {
                throw new DomainException("The {$from} to {$to} transition is not allowed for this work type.");
            }

            $targetStatus = $this->normalizedStatus($input->to);
            $this->guardWaiting($targetStatus, $input);
            $this->guardSettlement($locked, $input);

            $fromStatus = (string) $locked->status;
            $previousResolution = $isReopen
                    ? $locked->only(['resolution_code', 'resolution_summary', 'resolution_verification'])
                    : null;
            $this->applyState($locked, $input, $targetStatus, $isReopen);
            $locked->save();
            $reopenComment = $isReopen ? $this->recordReopenReason($locked, $input) : null;

            ItTicketEvent::record(
                $locked,
                $isReopen ? 'reopened' : $this->eventType($input->source),
                $input->actor->id,
                [
                    'from' => $fromStatus,
                    'to' => $locked->status,
                    'from_workflow_state' => $from,
                    'to_workflow_state' => $to,
                    'reason' => $input->reason,
                    'via' => $input->source,
                    ...($targetStatus === 'resolved' ? ['resolution' => $locked->only([
                        'resolution_code', 'resolution_summary', 'resolution_verification',
                    ])] : []),
                    ...($previousResolution !== null ? ['previous_resolution' => $previousResolution] : []),
                    ...($reopenComment !== null ? [
                        'comment_id' => (int) $reopenComment->id,
                        'comment_visibility' => $reopenComment->is_internal ? 'internal' : 'public',
                    ] : []),
                ],
            );

            AuditLogger::logOrFail('it.work.transitioned', $locked, [
                'actor_id' => $input->actor->id,
                'from_status' => $fromStatus,
                'to_status' => $locked->status,
                'from_workflow_state' => $from,
                'to_workflow_state' => $to,
                'reason' => $input->reason,
                'source' => $input->source,
                'resolution_code' => $locked->resolution_code,
                'application_scope' => 'single_application',
            ]);

            if ($this->requiresPublicResolution($locked, $input)) {
                $this->recordPublicResolution($locked, $input->actor);
            }

            return $locked->refresh();
        });
    }

    /**
     * The scheduler's candidate list is advisory. Recheck under the same parent
     * lock as reopen, task and approval commands before making a system close.
     */
    public function autoCloseResolved(int $ticketId, CarbonInterface $cutoff, int $days): bool
    {
        return DB::transaction(function () use ($ticketId, $cutoff, $days): bool {
            $ticket = ItTicket::query()->whereKey($ticketId)->lockForUpdate()->first();
            if (! $ticket || $ticket->isMerged() || $ticket->status !== 'resolved'
                || $ticket->resolved_at === null || $ticket->resolved_at->greaterThan($cutoff)) {
                return false;
            }

            $this->guardRequiredWork($ticket);
            $from = $this->currentState($ticket);
            app(ItSlaClockService::class)->synchronize($ticket, now());
            $ticket->forceFill([
                'status' => 'closed',
                'workflow_state' => ItWorkflowState::Closed->value,
                'closed_at' => now(),
                'waiting_party' => null,
                'waiting_reason' => null,
                'next_action' => null,
            ])->save();

            ItTicketEvent::record($ticket, 'closed', null, [
                'via' => 'auto_close', 'after_days' => $days,
                'from' => 'resolved', 'to' => 'closed',
                'from_workflow_state' => $from, 'to_workflow_state' => 'closed',
            ]);
            AuditLogger::logOrFail('it.ticket.auto_closed', $ticket, [
                'source' => 'auto_close', 'after_days' => $days,
                'from_status' => 'resolved', 'to_status' => 'closed',
                'from_workflow_state' => $from, 'to_workflow_state' => 'closed',
                'resolution_code' => $ticket->resolution_code,
                'application_scope' => 'single_application',
            ], systemActor: true);

            return true;
        });
    }

    private function reviseWaitingContext(ItTicket $ticket, ItTransitionInput $input): ItTicket
    {
        $this->guardWaiting('waiting', $input);

        $before = $ticket->only(['waiting_party', 'waiting_reason', 'next_action']);
        $ticket->waiting_party = $input->waitingParty;
        $ticket->waiting_reason = $input->reason;
        $ticket->status_reason = $input->reason;
        $ticket->next_action = $input->nextAction;
        $ticket->waiting_since ??= now();

        if (! $ticket->isDirty()) {
            return $ticket;
        }

        $ticket->save();
        $changedFields = collect(['waiting_party', 'waiting_reason', 'next_action'])
            ->filter(fn (string $field): bool => $before[$field] !== $ticket->getAttribute($field))
            ->values()
            ->all();

        ItTicketEvent::record($ticket, 'waiting_updated', $input->actor->id, [
            'waiting_party' => $ticket->waiting_party,
            'changed_fields' => $changedFields,
            'reason_recorded' => true,
            'next_action_recorded' => filled($ticket->next_action),
            'via' => $input->source,
        ]);
        AuditLogger::logOrFail('it.work.waiting.updated', $ticket, [
            'actor_id' => $input->actor->id,
            'waiting_party' => $ticket->waiting_party,
            'changed_fields' => $changedFields,
            'reason_recorded' => true,
            'next_action_recorded' => filled($ticket->next_action),
            'source' => $input->source,
            'application_scope' => 'single_application',
        ]);

        return $ticket->refresh();
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
            && in_array((int) $input->actor->id, [(int) $ticket->requester_user_id, (int) $ticket->requested_for_user_id], true)
            && $this->workAccess->canView($input->actor, $ticket)
            && $from === ItWorkflowState::Waiting->value
            && $input->to === ItWorkflowState::InProgress;

        $requesterReopen = in_array($input->source, ['reopen', 'legacy_reopen'], true)
            && $this->isReopening($ticket, $input->to)
            && $input->actor->can('reopen', $ticket);

        $requesterConfirmation = $input->source === 'requester_confirmation'
            && $input->to === ItWorkflowState::Closed
            && $input->actor->can('confirmResolution', $ticket);

        if ($input->source === 'requester_confirmation' && ! $requesterConfirmation) {
            throw new DomainException('Only the requester can confirm this resolved ticket.');
        }

        if (! $requesterReply
            && ! $requesterReopen
            && ! $requesterConfirmation
            && ! $this->workAccess->canWork($input->actor, $ticket, $input->source === 'service_api')) {
            throw new DomainException('The actor is not allowed to transition this work item.');
        }
    }

    private function isAllowed(ItTicket $ticket, ItTransitionInput $input, string $from, string $to): bool
    {
        if ($input->source === 'requester_confirmation') {
            return $ticket->status === 'resolved' && $to === 'closed';
        }

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

        // A requester can confirm without gaining access to the private task
        // graph. The internal gate is identical to automatic closure's gate.
        $this->guardRequiredWork($ticket, $input->source === 'requester_confirmation' ? null : $input->actor);

        $requiresResolutionEvidence = $input->to !== ItWorkflowState::Closed;
        if ($requiresResolutionEvidence
            && (blank($input->resolutionCode) || blank($input->resolutionSummary))) {
            throw new DomainException('A resolution code and summary are required before settlement.');
        }
        if ($this->requiresPublicResolution($ticket, $input)) {
            ItTicketResolutionInput::normalize([
                'resolution_code' => $input->resolutionCode,
                'note' => $input->resolutionSummary,
                'resolution_verification' => $input->resolutionVerification,
            ]);
        }
    }

    private function guardRequiredWork(ItTicket $ticket, ?User $actor = null): void
    {
        if ($ticket->requires_approval && $ticket->approvalState() !== 'approved') {
            throw new ItSettlementBlocked('Required approval must be approved before settlement.', (int) $ticket->id, 'approval', $ticket->approvals()->latest('id')->value('id'));
        }

        if ($unfinished = $ticket->tasks()->where('is_required', true)->where('status', '!=', 'completed')->orderBy('sort_order')->orderBy('id')->first()) {
            throw new ItSettlementBlocked('All required tasks must be completed before settlement. Review task “'.$unfinished->title.'”.', (int) $ticket->id, 'task', (int) $unfinished->id);
        }
        if ($ticket->tasks()->where('is_required', true)->exists()) {
            $work = $actor === null
                ? $this->taskReadiness->forSettlement($ticket)
                : $this->taskReadiness->forTicket($ticket, $actor, lock: true);
            foreach ($work['tasks']->where('is_required', true) as $task) {
                $verdict = $work['verdicts'][$task->id];
                if ($verdict['completion'] === 'invalid') {
                    throw new ItSettlementBlocked('Required task evidence is no longer current. Review task “'.$task->title.'” in Tasks & evidence before resolving this ticket.', (int) $ticket->id, 'task', (int) $task->id);
                }
                // Missing legacy provenance remains explicitly unknown. The
                // separately reviewed legacy policy must not be inferred here.
            }
        }

    }

    /** Post-implementation reviews continue the existing lifecycle, without reopening it. */
    private function isReopening(ItTicket $ticket, ItWorkflowState $to): bool
    {
        return in_array((string) $ticket->status, ['resolved', 'closed'], true)
            && ! in_array($this->normalizedStatus($to), ['resolved', 'closed'], true)
            && $to !== ItWorkflowState::Review;
    }

    private function applyState(ItTicket $ticket, ItTransitionInput $input, string $targetStatus, bool $isReopen): void
    {
        app(ItSlaClockService::class)->synchronize($ticket, now());
        $wasSettled = in_array((string) $ticket->status, ['resolved', 'closed'], true);

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
            $evidence = $this->requiresPublicResolution($ticket, $input) ? ItTicketResolutionInput::normalize([
                'resolution_code' => $input->resolutionCode,
                'note' => $input->resolutionSummary,
                'resolution_verification' => $input->resolutionVerification,
            ]) : null;
            $ticket->resolved_at = now();
            $ticket->resolution_code = $evidence['resolution_code'] ?? $input->resolutionCode;
            $ticket->resolution_summary = $evidence['note'] ?? $input->resolutionSummary;
            $ticket->resolution_verification = $evidence['resolution_verification'] ?? $input->resolutionVerification;
        }

        if ($input->to === ItWorkflowState::Closed) {
            $ticket->closed_at = now();
        }

        if ($isReopen && $wasSettled) {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
            $ticket->resolution_code = null;
            $ticket->resolution_summary = null;
            $ticket->resolution_verification = null;
            $ticket->reopened_count = (int) $ticket->reopened_count + 1;
            if (ItTicket::hasConversationEvidence()) {
                $ticket->next_response_party = 'it';
            }
        }
        app(ItSlaClockService::class)->synchronize($ticket, now());
    }

    /** Every reopen route records one reason under the canonical parent lock. */
    private function recordReopenReason(ItTicket $ticket, ItTransitionInput $input): ItTicketComment
    {
        $isRequester = (int) $ticket->requester_user_id === (int) $input->actor->id;
        $comment = $ticket->comments()->create([
            'author_user_id' => $input->actor->id,
            'body' => $input->reason,
            'is_internal' => ! $isRequester,
            ...(ItTicket::hasConversationEvidence() ? [
                'speaker_side' => $isRequester ? 'requester' : 'it',
                'source_channel' => $input->channel?->value ?? (in_array($input->source, ['legacy_reopen', 'workspace'], true) ? 'browser' : null),
            ] : []),
        ]);
        if (ItTicket::hasConversationEvidence() && $isRequester) {
            $ticket->forceFill([
                'last_public_comment_id' => $comment->id,
                'last_public_commented_at' => $comment->created_at,
                'last_public_speaker_side' => 'requester',
                'next_response_party' => 'it',
            ])->save();
        }
        AuditLogger::logOrFail('it.ticket.reopened', $ticket, [
            'actor_id' => $input->actor->id,
            'comment_id' => $comment->id,
            'comment_visibility' => $isRequester ? 'public' : 'internal',
            'reason_recorded' => true,
            'source' => $input->source,
            'application_scope' => 'single_application',
        ]);

        return $comment;
    }

    private function requiresPublicResolution(ItTicket $ticket, ItTransitionInput $input): bool
    {
        return in_array($input->to, [ItWorkflowState::Resolved, ItWorkflowState::Fulfilled], true)
            && ($input->source === 'legacy_resolve' || in_array($ticket->work_type, [
                ItWorkType::Incident->value, ItWorkType::ServiceRequest->value, ItWorkType::SecurityRequest->value,
            ], true));
    }

    /** Shared by the public Resolve action and generic ticket settlement. */
    private function recordPublicResolution(ItTicket $ticket, User $actor): void
    {
        $requesterSide = in_array((int) $actor->id, [(int) $ticket->requester_user_id, (int) $ticket->requested_for_user_id], true);
        $comment = $ticket->comments()->create([
            'author_user_id' => $actor->id,
            'body' => $ticket->resolution_summary."\n\nHow it was checked: ".$ticket->resolution_verification,
            'is_internal' => false,
            ...(ItTicket::hasConversationEvidence() ? ['speaker_side' => $requesterSide ? 'requester' : 'it', 'source_channel' => 'browser'] : []),
        ]);
        if (ItTicket::hasConversationEvidence()) {
            $ticket->forceFill([
                'last_public_comment_id' => $comment->id, 'last_public_commented_at' => $comment->created_at,
                'last_public_speaker_side' => $comment->speaker_side, 'next_response_party' => $requesterSide ? 'it' : 'requester',
            ])->save();
        }
        if ($ticket->first_responded_at === null && ! $requesterSide) {
            $ticket->first_responded_at = $comment->created_at;
            app(ItSlaClockService::class)->synchronize($ticket, now());
            $ticket->save();
            ItTicketEvent::record($ticket, 'first_response_recorded', $actor->id, ['comment_id' => $comment->id]);
        }
        AuditLogger::logOrFail('it.ticket.resolved', $ticket, [
            'actor_id' => $actor->id, 'comment_id' => $comment->id, 'resolution_code' => $ticket->resolution_code,
            'public_resolution_recorded' => true, 'verification_recorded' => true, 'application_scope' => 'single_application',
        ]);
    }

    private function eventType(string $source): string
    {
        return match ($source) {
            'legacy_resolve' => 'resolved',
            'requester_confirmation' => 'resolution_confirmed',
            'legacy_close', 'bulk_close' => 'closed',
            'legacy_reopen' => 'reopened',
            'legacy_status', 'bulk_status', 'requester_reply' => 'status_changed',
            default => 'workflow_transitioned',
        };
    }
}
