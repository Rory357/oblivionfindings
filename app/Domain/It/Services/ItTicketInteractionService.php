<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single write owner for human interaction around a ticket. A ticket row
 * lock serializes replies with settlement, first-response stamping, feedback
 * and watcher changes so the conversation and its audit history cannot drift.
 */
final class ItTicketInteractionService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItWorkTransitionService $transitionService,
        private readonly ItAttachmentStorageService $attachmentStorage,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $attachments
     * @return array{ticket: ItTicket, comment: ItTicketComment, is_internal: bool, is_requester: bool}
     */
    public function addComment(
        ItTicket $ticket,
        User $actor,
        string $body,
        bool $isInternal,
        array $attachments = [],
    ): array {
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $ticket,
                $actor,
                $body,
                $isInternal,
                $attachments,
                &$storedPaths,
            ): array {
                $locked = $this->lockTicket($ticket);
                $this->guardVisible($locked, $actor);

                if ($isInternal && ! $this->workAccess->canWork($actor, $locked)) {
                    throw new AuthorizationException('You are not allowed to add internal notes.');
                }
                if ($locked->isMerged()) {
                    throw new DomainException('Continue this conversation on the surviving ticket.');
                }
                if (! in_array($locked->status, ItTicket::OPEN_STATUSES, true)) {
                    throw new DomainException('Reopen this ticket before adding another reply or note.');
                }

                $isRequester = (int) $locked->requester_user_id === (int) $actor->id;
                $isAgentSide = $this->workAccess->canWork($actor, $locked);
                $comment = $locked->comments()->create([
                    'author_user_id' => $actor->id,
                    'body' => $body,
                    'is_internal' => $isInternal,
                ]);
                $this->attachmentStorage->store($comment, $attachments, $actor, $storedPaths);

                $firstResponseRecorded = $isAgentSide && ! $isInternal && $locked->first_responded_at === null;
                if ($firstResponseRecorded) {
                    $locked->forceFill(['first_responded_at' => now()])->save();
                    ItTicketEvent::record($locked, 'first_response_recorded', $actor->id, [
                        'comment_id' => $comment->id,
                    ]);
                }

                $requesterResumed = $isRequester
                    && $locked->status === 'waiting'
                    && $locked->workflow_state !== ItWorkflowState::ApprovalPending->value
                    && in_array($locked->waiting_party, [null, 'requester'], true);
                if ($requesterResumed) {
                    $locked = $this->transitionService->transition(
                        $locked,
                        new ItTransitionInput(
                            actor: $actor,
                            to: ItWorkflowState::InProgress,
                            reason: 'Requester replied',
                            source: 'requester_reply',
                        ),
                    );
                }

                AuditLogger::logOrFail('it.ticket.comment.added', $locked, [
                    'actor_id' => $actor->id,
                    'comment_id' => $comment->id,
                    'visibility' => $isInternal ? 'internal' : 'public',
                    'attachment_count' => count($attachments),
                    'first_response_recorded' => $firstResponseRecorded,
                    'requester_resumed' => $requesterResumed,
                    'application_scope' => 'single_application',
                ]);

                return [
                    'ticket' => $locked->refresh()->load(['assignee', 'requester', 'watchers']),
                    'comment' => $comment,
                    'is_internal' => $isInternal,
                    'is_requester' => $isRequester,
                ];
            });
        } catch (Throwable $exception) {
            $this->attachmentStorage->deleteStored($storedPaths);

            throw $exception;
        }
    }

    public function resolveWithPublicNote(ItTicket $ticket, User $actor, string $note): ItTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $note): ItTicket {
            $locked = $this->lockTicket($ticket);
            $this->guardVisible($locked, $actor);
            if (! $this->workAccess->canWork($actor, $locked)) {
                throw new AuthorizationException('You are not allowed to resolve this ticket.');
            }
            if (in_array($locked->status, ['resolved', 'closed'], true)) {
                throw new DomainException('This ticket is already resolved.');
            }
            if ($locked->isMerged()) {
                throw new DomainException('Resolve the surviving ticket instead.');
            }

            $note = trim($note);
            if ($note === '') {
                throw new DomainException('Explain what fixed the issue before resolving it.');
            }

            $resolved = $this->transitionService->transition(
                $locked,
                new ItTransitionInput(
                    actor: $actor,
                    to: ItWorkflowState::Resolved,
                    reason: 'Technician resolution',
                    resolutionCode: (string) ($locked->resolution_code ?: 'restored'),
                    resolutionSummary: $note,
                    source: 'legacy_resolve',
                ),
            );
            $comment = $resolved->comments()->create([
                'author_user_id' => $actor->id,
                'body' => $note,
                'is_internal' => false,
            ]);

            AuditLogger::logOrFail('it.ticket.resolved', $resolved, [
                'actor_id' => $actor->id,
                'comment_id' => $comment->id,
                'resolution_code' => $resolved->resolution_code,
                'public_resolution_recorded' => true,
                'application_scope' => 'single_application',
            ]);

            return $resolved->refresh()->load(['assignee', 'requester', 'watchers']);
        });
    }

    /**
     * Return settled work to the queue with evidence the next technician can
     * act on. Requester explanations remain public; technician explanations
     * are internal notes and never cross the requester payload boundary.
     *
     * @return array{ticket: ItTicket, comment: ItTicketComment, is_requester: bool}
     */
    public function reopenWithReason(ItTicket $ticket, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($ticket, $actor, $reason): array {
            $locked = $this->lockTicket($ticket);
            $this->guardVisible($locked, $actor);

            if (! $actor->can('reopen', $locked)) {
                throw new AuthorizationException('You are not allowed to reopen this ticket.');
            }
            if ($locked->isMerged()) {
                throw new DomainException('This ticket was merged into another — reopen the survivor instead.');
            }
            if (! in_array($locked->status, ['resolved', 'closed'], true)) {
                throw new DomainException('Only resolved or closed tickets can be reopened.');
            }

            $reason = trim($reason);
            if (mb_strlen($reason) < 5) {
                throw new DomainException('Explain what still needs attention before reopening the ticket.');
            }

            $isRequester = (int) $locked->requester_user_id === (int) $actor->id;
            $reopened = $this->transitionService->transition(
                $locked,
                new ItTransitionInput(
                    actor: $actor,
                    to: ItWorkflowState::Submitted,
                    reason: $reason,
                    source: 'legacy_reopen',
                ),
            );
            $comment = $reopened->comments()->create([
                'author_user_id' => $actor->id,
                'body' => $reason,
                'is_internal' => ! $isRequester,
            ]);

            AuditLogger::logOrFail('it.ticket.reopened', $reopened, [
                'actor_id' => $actor->id,
                'comment_id' => $comment->id,
                'comment_visibility' => $isRequester ? 'public' : 'internal',
                'reason_recorded' => true,
                'application_scope' => 'single_application',
            ]);

            return [
                'ticket' => $reopened->refresh()->load(['assignee', 'requester', 'watchers']),
                'comment' => $comment,
                'is_requester' => $isRequester,
            ];
        });
    }

    public function submitCsat(ItTicket $ticket, User $actor, int $score, ?string $comment): ItTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $score, $comment): ItTicket {
            $locked = $this->lockTicket($ticket);
            $this->guardVisible($locked, $actor);

            if ((int) $locked->requester_user_id !== (int) $actor->id || $locked->status !== 'resolved') {
                throw new AuthorizationException('Only the requester can rate a resolved ticket.');
            }

            $firstSubmission = $locked->csat_submitted_at === null;
            $changed = $firstSubmission
                || (int) $locked->csat_score !== $score
                || $locked->csat_comment !== $comment;
            if (! $changed) {
                return $locked;
            }

            $locked->forceFill([
                'csat_score' => $score,
                'csat_comment' => $comment,
                'csat_submitted_at' => $locked->csat_submitted_at ?? now(),
            ])->save();

            $event = $firstSubmission ? 'csat_submitted' : 'csat_updated';
            $audit = $firstSubmission ? 'it.ticket.csat.submitted' : 'it.ticket.csat.updated';
            ItTicketEvent::record($locked, $event, $actor->id, ['score' => $score]);
            AuditLogger::logOrFail($audit, $locked, [
                'actor_id' => $actor->id,
                'score' => $score,
                'comment_recorded' => $comment !== null,
                'application_scope' => 'single_application',
            ]);

            return $locked->refresh();
        });
    }

    public function watch(ItTicket $ticket, User $actor): bool
    {
        return $this->mutateWatcher($ticket, $actor, true);
    }

    public function unwatch(ItTicket $ticket, User $actor): bool
    {
        return $this->mutateWatcher($ticket, $actor, false);
    }

    private function mutateWatcher(ItTicket $ticket, User $actor, bool $watching): bool
    {
        return DB::transaction(function () use ($ticket, $actor, $watching): bool {
            $locked = $this->lockTicket($ticket);
            $this->guardVisible($locked, $actor);
            if (! $this->workAccess->canWork($actor, $locked)) {
                throw new AuthorizationException('Only IT staff can change ticket watchers.');
            }

            $changed = $watching
                ? ! empty($locked->watchers()->syncWithoutDetaching([$actor->id])['attached'])
                : $locked->watchers()->detach($actor->id) > 0;
            if (! $changed) {
                return false;
            }

            $event = $watching ? 'watcher_added' : 'watcher_removed';
            $audit = $watching ? 'it.ticket.watcher.added' : 'it.ticket.watcher.removed';
            ItTicketEvent::record($locked, $event, $actor->id, ['user_id' => $actor->id]);
            AuditLogger::logOrFail($audit, $locked, [
                'actor_id' => $actor->id,
                'watcher_user_id' => $actor->id,
                'application_scope' => 'single_application',
            ]);

            return true;
        });
    }

    private function lockTicket(ItTicket $ticket): ItTicket
    {
        return ItTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
    }

    private function guardVisible(ItTicket $ticket, User $actor): void
    {
        if (! $this->workAccess->canView($actor, $ticket)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class, [$ticket->id]);
        }
    }
}
