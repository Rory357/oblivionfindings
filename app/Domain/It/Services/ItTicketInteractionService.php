<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTicketCommentCancellationResult;
use App\Domain\It\Data\ItTicketCommentResult;
use App\Domain\It\Data\ItTicketResolutionInput;
use App\Domain\It\Data\ItTicketWatcherResult;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketDraftException;
use App\Models\ItAttachment;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Notifications\It\TicketRepliedNotification;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        private readonly ItTicketVersionService $versions,
    ) {}

    /** Trusted adapter command; the existing writer remains the only message creator. */
    public function addCommentCommand(ItTicket $ticket, User $actor, array $input, array $attachments = [], ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser, ?ItAttachmentWriteContext $attachmentContext = null): ItTicketCommentResult|ItTicketCommentCancellationResult
    {
        $attachmentContext?->assertActive();
        Validator::make($input, [
            'request_uuid' => ['required', 'uuid'], 'actor_user_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:'.($channel === ItTicketCommandChannel::Email ? 100000 : 5000)],
            'is_internal' => $channel !== ItTicketCommandChannel::Browser ? ['sometimes', 'boolean', 'declined'] : ['sometimes', 'boolean'],
            'draft_uuid' => $channel !== ItTicketCommandChannel::Browser ? ['prohibited'] : ['required_with:draft_revision,draft_actor_user_id', 'uuid'],
            'draft_revision' => $channel !== ItTicketCommandChannel::Browser ? ['prohibited'] : ['required_with:draft_uuid', 'integer', 'min:0'],
            'draft_actor_user_id' => $channel !== ItTicketCommandChannel::Browser ? ['prohibited'] : ['required_with:draft_uuid', 'integer', 'min:1'],
        ])->validate();
        Validator::make(['attachments' => $attachments], [
            'attachments' => ['array', 'max:5'],
            'attachments.*' => ['file', 'max:'.ItAttachment::MAX_SIZE_KB, 'mimes:'.ItAttachment::ALLOWED_MIMES],
        ])->validate();
        if ((int) $input['actor_user_id'] !== (int) $actor->id) {
            throw new AuthorizationException('The signed-in account changed. Recover this reply using its original account.');
        }
        $hash = $this->commentHash($ticket, $input, $attachments);
        $paths = [];
        $attachmentReservations = [];
        $prepared = null;
        $connection = DB::connection();
        $originalPdo = $connection->getPdo();
        $originalLevel = $connection->transactionLevel();
        try {
            return DB::transaction(function () use ($ticket, $actor, $input, $attachments, $hash, $channel, $attachmentContext, &$paths, &$attachmentReservations, &$prepared): ItTicketCommentResult|ItTicketCommentCancellationResult {
                $locked = $this->lockTicket($ticket);
                $currentEvidence = $channel === ItTicketCommandChannel::ServiceApi;
                $current = $this->versions->currentActor($actor, $currentEvidence);
                $this->guardVisible($locked, $current, $currentEvidence);
                $internal = (bool) ($input['is_internal'] ?? false);
                if ($internal && ! $this->workAccess->canWork($current, $locked)) {
                    throw new AuthorizationException('You are not allowed to add internal notes.');
                }
                $receipt = $this->commentReceiptQuery($current, $input['request_uuid'], $channel)->lockForUpdate()->first();
                if ($receipt) {
                    if (($receipt->result_metadata['state'] ?? null) === 'cancelled') {
                        $cancelled = $this->commentCancellationReplay($locked, $current, $receipt);
                        if ($cancelled->isInternal !== $internal) {
                            throw new ItTicketCommandConflict;
                        }

                        return $cancelled;
                    }
                    if (! hash_equals($receipt->request_hash, $hash)) {
                        throw new ItTicketCommandConflict;
                    }

                    return $prepared = $this->commentReplay($locked, $current, $receipt);
                }
                $this->versions->assertCurrent($locked, (int) $input['expected_version']);
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $current->id, 'channel' => $channel->value,
                    'operation' => ItTicketCommandReceipt::COMMENT_OPERATION,
                    'request_uuid' => $input['request_uuid'], 'request_hash' => $hash,
                ]);
                if ($channel === ItTicketCommandChannel::Email && ! in_array($locked->status, ItTicket::OPEN_STATUSES, true)) {
                    $locked = $this->reopenWithReason($locked, $current,
                        'Email reply received after this ticket was settled.', (int) $input['expected_version'], $channel)['ticket'];
                }
                $result = $this->addComment($locked, $current, $input['body'], $internal, $attachments, $input, $paths, $attachmentReservations, $channel, $attachmentContext);
                $locked = $result['ticket'];
                $comment = $result['comment'];
                if (! $internal) {
                    $recipients = $result['is_requester']
                        ? $locked->watchers->when($locked->assignee, fn ($users) => $users->push($locked->assignee))
                        : collect([$locked->requester])->filter();
                    app(ItEmailDeliveryService::class)->prepare(
                        $recipients->unique('id')->reject(fn ($user) => (int) $user->id === (int) $current->id),
                        new TicketRepliedNotification($locked, $result['is_requester'] ? 'agent_side' : 'requester', $comment->id),
                    );
                }
                $consumed = isset($input['draft_uuid']) ? [
                    'draft_uuid' => $input['draft_uuid'], 'submitted_revision' => (int) $input['draft_revision'],
                    'revision' => (int) $input['draft_revision'] + 1, 'state' => 'consumed',
                ] : null;
                $receipt->forceFill([
                    'it_ticket_id' => $locked->id, 'it_ticket_comment_id' => $comment->id,
                    'committed_ticket_version' => $locked->lock_version,
                    'result_metadata' => $consumed !== null ? ['draft' => $consumed] : [],
                    'committed_at' => now(),
                ])->save();

                return $prepared = new ItTicketCommentResult($locked, $comment, $input['request_uuid'], (int) $locked->lock_version, false, $consumed);
            });
        } catch (Throwable $exception) {
            // A failed commit acknowledgement is not proof of rollback. Keep
            // its files for exact receipt recovery; never delete committed evidence.
            if ($prepared !== null && $originalLevel === 0) {
                try {
                    $confirmed = $this->reconcileCommentCommand($actor, $hash, $prepared, $connection->getConfig(), $connection->getName(), $channel);
                    if ($confirmed !== null) {
                        return $confirmed;
                    }
                } catch (AuthorizationException|ModelNotFoundException $denied) {
                    throw $denied;
                } catch (Throwable) {
                    // Neither an unavailable connection nor a missing receipt
                    // proves rollback; retain possible committed file evidence.
                }
            } elseif ($prepared === null && $connection->getPdo() === $originalPdo
                && $connection->transactionLevel() === $originalLevel
                && $originalPdo->inTransaction() === ($originalLevel > 0)) {
                $this->attachmentStorage->requestRollbackCleanup($attachmentReservations);
            }
            throw $exception;
        }
    }

    /** Confirm an uncertain commit using a new connection and current authorization. */
    private function reconcileCommentCommand(User $actor, string $hash, ItTicketCommentResult $prepared, array $configuration, string $originalConnection, ItTicketCommandChannel $channel): ?ItTicketCommentResult
    {
        $recoveryConnection = 'it_comment_recovery_'.Str::uuid();
        $configuration['name'] = $recoveryConnection;
        if (isset($configuration['write'])) {
            $configuration['write']['name'] = $recoveryConnection;
        }
        try {
            DB::connectUsing($recoveryConnection, $configuration)->useWriteConnectionWhenReading();

            return DB::usingConnection($recoveryConnection, function () use ($actor, $hash, $prepared, $originalConnection, $channel): ?ItTicketCommentResult {
                return DB::transaction(function () use ($actor, $hash, $prepared, $originalConnection, $channel): ?ItTicketCommentResult {
                    $ticket = $this->lockTicket($prepared->ticket);
                    $current = $this->versions->currentActor($actor);
                    $this->guardVisible($ticket, $current);
                    $receipt = $this->commentReceiptQuery($current, $prepared->requestUuid, $channel)->lockForUpdate()->first();
                    if (! $receipt?->committed_at || ! hash_equals($receipt->request_hash, $hash)) {
                        return null;
                    }
                    $confirmed = $this->commentReplay($ticket, $current, $receipt);

                    return new ItTicketCommentResult($confirmed->ticket->setConnection($originalConnection),
                        $confirmed->comment->setConnection($originalConnection), $confirmed->requestUuid,
                        $confirmed->committedVersion, true, $confirmed->consumedDraft);
                });
            });
        } finally {
            DB::purge($recoveryConnection);
        }
    }

    /** Read only an already committed reply identity after current audience authorization. */
    public function recoverCommentCommand(ItTicket $ticket, User $actor, string $requestUuid, ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser): ItTicketCommentResult|ItTicketCommentCancellationResult
    {
        return DB::transaction(function () use ($ticket, $actor, $requestUuid, $channel): ItTicketCommentResult|ItTicketCommentCancellationResult {
            $locked = $this->lockTicket($ticket);
            $current = $this->versions->currentActor($actor);
            $this->guardVisible($locked, $current);
            $receipt = $this->commentReceiptQuery($current, $requestUuid, $channel)->lockForUpdate()->firstOrFail();

            if (($receipt->result_metadata['state'] ?? null) === 'cancelled') {
                return $this->commentCancellationReplay($locked, $current, $receipt);
            }

            return $this->commentReplay($locked, $current, $receipt);
        });
    }

    /** Serialize an explicit cancellation with the same canonical writer and receipt. */
    public function cancelCommentCommand(ItTicket $ticket, User $actor, string $requestUuid, bool $isInternal): ItTicketCommentResult|ItTicketCommentCancellationResult
    {
        Validator::make(['request_uuid' => $requestUuid], ['request_uuid' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($ticket, $actor, $requestUuid, $isInternal): ItTicketCommentResult|ItTicketCommentCancellationResult {
            $locked = $this->lockTicket($ticket);
            $current = $this->versions->currentActor($actor);
            $this->guardVisible($locked, $current);
            if ($isInternal && ! $this->workAccess->canWork($current, $locked)) {
                throw new AuthorizationException('This internal reply command is no longer available to you.');
            }
            $receipt = $this->commentReceiptQuery($current, $requestUuid)->lockForUpdate()->first();
            if ($receipt) {
                $result = ($receipt->result_metadata['state'] ?? null) === 'cancelled'
                    ? $this->commentCancellationReplay($locked, $current, $receipt)
                    : $this->commentReplay($locked, $current, $receipt);
                $actualAudience = $result instanceof ItTicketCommentCancellationResult ? $result->isInternal : (bool) $result->comment->is_internal;
                if ($actualAudience !== $isInternal) {
                    throw new ItTicketCommandConflict;
                }

                return $result;
            }
            $cancelledAt = now()->toIso8601String();
            ItTicketCommandReceipt::query()->create([
                'actor_user_id' => $current->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                'operation' => ItTicketCommandReceipt::COMMENT_OPERATION, 'request_uuid' => $requestUuid,
                'request_hash' => hash_hmac('sha256', json_encode(['cancelled', $locked->id, $isInternal, $requestUuid], JSON_THROW_ON_ERROR), (string) config('app.key')),
                'it_ticket_id' => $locked->id,
                'result_metadata' => ['state' => 'cancelled', 'is_internal' => $isInternal, 'cancelled_at' => $cancelledAt],
            ]);
            AuditLogger::logOrFail('it.ticket.comment_command.cancelled', $locked, [
                'actor_id' => $current->id, 'request_uuid' => $requestUuid, 'visibility' => $isInternal ? 'internal' : 'public',
                'application_scope' => 'single_application',
            ]);

            return new ItTicketCommentCancellationResult($locked, $requestUuid, $isInternal, $cancelledAt);
        });
    }

    private function commentCancellationReplay(ItTicket $ticket, User $actor, ItTicketCommandReceipt $receipt): ItTicketCommentCancellationResult
    {
        $metadata = $receipt->result_metadata;
        if ($receipt->committed_at !== null || $receipt->it_ticket_comment_id !== null
            || (int) $receipt->it_ticket_id !== (int) $ticket->id
            || ($metadata['state'] ?? null) !== 'cancelled' || ! is_bool($metadata['is_internal'] ?? null)
            || ! is_string($metadata['cancelled_at'] ?? null)
            || ($metadata['is_internal'] && ! $this->workAccess->canWork($actor, $ticket))) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }

        return new ItTicketCommentCancellationResult($ticket, $receipt->request_uuid, $metadata['is_internal'], $metadata['cancelled_at'], true);
    }

    /** @return Builder<ItTicketCommandReceipt> */
    private function commentReceiptQuery(User $actor, string $requestUuid, ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser): Builder
    {
        return ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
            ->where('channel', $channel->value)
            ->where('operation', ItTicketCommandReceipt::COMMENT_OPERATION)
            ->where('request_uuid', $requestUuid);
    }

    private function commentReplay(ItTicket $ticket, User $actor, ItTicketCommandReceipt $receipt): ItTicketCommentResult
    {
        $comment = $receipt->comment;
        if (! $receipt->committed_at || (int) $receipt->it_ticket_id !== (int) $ticket->id
            || ! $comment
            || (int) $comment->author_user_id !== (int) $actor->id
            || (int) $receipt->committed_ticket_version < 1
            || ($comment->is_internal && ! $this->workAccess->canWork($actor, $ticket))) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }

        // Keep the immutable command bound to its original ticket. A governed
        // merge may relocate the same comment; prove that exact current chain
        // and both audiences before revealing its canonical destination.
        $canonical = $ticket;
        $seen = [];
        while ($canonical->merged_into_ticket_id !== null) {
            if (isset($seen[(int) $canonical->id])) {
                throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
            }
            $seen[(int) $canonical->id] = true;
            $canonical = ItTicket::query()->find($canonical->merged_into_ticket_id);
            if (! $canonical) {
                throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
            }
        }
        if ((int) $comment->ticket_id !== (int) $canonical->id
            || ! $this->workAccess->canView($actor, $canonical)
            || ($comment->is_internal && ! $this->workAccess->canWork($actor, $canonical))) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }

        return new ItTicketCommentResult($ticket, $comment, $receipt->request_uuid,
            (int) $receipt->committed_ticket_version, true, $receipt->result_metadata['draft'] ?? null);
    }

    /** Receipt contains only a keyed digest of the exact immutable intent. */
    private function commentHash(ItTicket $ticket, array $input, array $attachments): string
    {
        $payload = [
            'ticket_id' => (int) $ticket->id,
            'body' => trim((string) $input['body']),
            'is_internal' => (bool) ($input['is_internal'] ?? false),
            'expected_version' => (int) $input['expected_version'],
            'draft_uuid' => $input['draft_uuid'] ?? null,
            'draft_revision' => isset($input['draft_revision']) ? (int) $input['draft_revision'] : null,
            'draft_actor_user_id' => isset($input['draft_actor_user_id']) ? (int) $input['draft_actor_user_id'] : null,
            'attachments' => array_map(fn (UploadedFile $file): array => [
                'name' => $file->getClientOriginalName(), 'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getPathname()),
            ], $attachments),
        ];

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

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
        array $command = [],
        array &$storedPaths = [],
        ?array &$attachmentReservations = null,
        ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser,
        ?ItAttachmentWriteContext $attachmentContext = null,
    ): array {
        $attachmentContext?->assertActive();
        // The typed command owns the outer receipt transaction and all cleanup.
        // Legacy callers own their reservations here. Savepoint rollback must
        // never start a second connection waiting on the surviving outer lock.
        $ownsAttachmentCleanup = $attachmentReservations === null;
        $attachmentReservations ??= [];
        $prepared = false;
        $connection = DB::connection();
        $originalPdo = $connection->getPdo();
        $originalLevel = $connection->transactionLevel();

        try {
            return DB::transaction(function () use (
                $ticket,
                $actor,
                $body,
                $isInternal,
                $attachments,
                $command,
                $channel,
                $attachmentContext,
                &$storedPaths,
                &$attachmentReservations,
                &$prepared,
            ): array {
                $locked = $this->lockTicket($ticket);
                $currentEvidence = $channel === ItTicketCommandChannel::ServiceApi;
                $actor = $this->versions->currentActor($actor, $currentEvidence);
                $this->guardVisible($locked, $actor, $currentEvidence);

                if ($isInternal && ! $this->workAccess->canWork($actor, $locked)) {
                    throw new AuthorizationException('You are not allowed to add internal notes.');
                }
                if ($locked->isMerged()) {
                    throw new DomainException('Continue this conversation on the surviving ticket.');
                }
                if (! in_array($locked->status, ItTicket::OPEN_STATUSES, true)) {
                    throw new DomainException('Reopen this ticket before adding another reply or note.');
                }

                $body = trim($body);
                if ($body === '') {
                    throw new DomainException('Enter a reply or note before posting it.');
                }
                $isRequester = in_array((int) $actor->id, [(int) $locked->requester_user_id, (int) $locked->requested_for_user_id], true);
                $isAgentSide = ! $isRequester && $this->workAccess->canWork($actor, $locked, $currentEvidence);
                $speaker = $isRequester ? 'requester' : ($isAgentSide ? 'it' : 'observer');
                $recordsConversation = ItTicket::hasConversationEvidence();
                $comment = $locked->comments()->create([
                    'author_user_id' => $actor->id,
                    'body' => $body,
                    'is_internal' => $isInternal,
                    ...($recordsConversation ? ['speaker_side' => $speaker, 'source_channel' => $channel->value] : []),
                ]);
                if ($command !== []) {
                    $purpose = $isInternal ? ItTicketDraftPurpose::InternalNote : ItTicketDraftPurpose::PublicReply;
                    $drafts = app(ItTicketDraftService::class);
                    if (isset($command['draft_uuid'])) {
                        $saved = $drafts->resume($actor, $command['draft_uuid']);
                        if ($saved['draft']['purpose'] !== $purpose->value || (int) $saved['draft']['ticket_id'] !== (int) $locked->id) {
                            throw ItTicketDraftException::unavailable();
                        }
                        if (trim((string) ($saved['payload']['fields']['body'] ?? '')) !== $body) {
                            throw new ItTicketDraftException('draft_content_changed', 409, 'Save this exact reply to its draft before submitting it. The current draft was not consumed.');
                        }
                    }
                    $drafts->consumeFromInput($actor, $command, $purpose, (int) $locked->id, attachmentTarget: $comment);
                    if ($comment->attachments()->count() + count($attachments) > 5) {
                        throw new DomainException('Use no more than five files across this reply and its saved draft.');
                    }
                }
                $attachmentReservations = $this->attachmentStorage->reserveDirect($comment, $attachments, $actor);
                $attachmentContext?->remember($attachmentReservations);
                $this->attachmentStorage->storeReservedDirect($comment, $attachments, $actor, $attachmentReservations, $storedPaths);

                $firstResponseRecorded = $isAgentSide && ! $isInternal && $locked->first_responded_at === null;
                if ($firstResponseRecorded) {
                    $locked->first_responded_at = now();
                    app(ItSlaClockService::class)->synchronize($locked, now());
                    $locked->save();
                    ItTicketEvent::record($locked, 'first_response_recorded', $actor->id, [
                        'comment_id' => $comment->id,
                    ]);
                }

                $requesterResumed = ! $isInternal && $isRequester
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

                if ($recordsConversation) {
                    $changes = [];
                    if (! $isInternal) {
                        $changes = ['last_public_comment_id' => $comment->id, 'last_public_commented_at' => $comment->created_at, 'last_public_speaker_side' => $speaker];
                        if ($speaker !== 'observer') {
                            $changes['next_response_party'] = $speaker === 'requester' ? 'it' : 'requester';
                        }
                    }
                    // Every interaction advances the aggregate's version, even
                    // an internal note that does not move conversation responsibility.
                    $locked->lock_version = (int) $locked->lock_version + 1;
                    $locked->forceFill($changes)->save();
                }

                AuditLogger::logOrFail('it.ticket.comment.added', $locked, [
                    'actor_id' => $actor->id,
                    'comment_id' => $comment->id,
                    'visibility' => $isInternal ? 'internal' : 'public',
                    'attachment_count' => $comment->attachments()->count(),
                    'first_response_recorded' => $firstResponseRecorded,
                    'requester_resumed' => $requesterResumed,
                    'application_scope' => 'single_application',
                ]);

                $result = [
                    'ticket' => $locked->refresh()->load(['assignee', 'requester', 'watchers']),
                    'comment' => $comment,
                    'is_internal' => $isInternal,
                    'is_requester' => $isRequester,
                    'stored_paths' => $storedPaths,
                ];
                $prepared = true;

                return $result;
            });
        } catch (Throwable $exception) {
            if ($ownsAttachmentCleanup && ! $prepared && $connection->getPdo() === $originalPdo
                && $connection->transactionLevel() === $originalLevel
                && $originalPdo->inTransaction() === ($originalLevel > 0)) {
                $this->attachmentStorage->requestRollbackCleanup($attachmentReservations);
            }

            throw $exception;
        }
    }

    public function resolveWithPublicNote(ItTicket $ticket, User $actor, string $note, ?int $expectedVersion = null, array $draftCommit = [], array $resolution = []): ItTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $note, $expectedVersion, $draftCommit, $resolution): ItTicket {
            $locked = $this->lockTicket($ticket);
            $actor = $this->versions->currentActor($actor);
            $this->guardVisible($locked, $actor);
            if (! $this->workAccess->canWork($actor, $locked)) {
                throw new AuthorizationException('You are not allowed to resolve this ticket.');
            }
            $this->versions->assertCurrent($locked, $expectedVersion);
            if (in_array($locked->status, ['resolved', 'closed'], true)) {
                throw new DomainException('This ticket is already resolved.');
            }
            if ($locked->isMerged()) {
                throw new DomainException('Resolve the surviving ticket instead.');
            }

            $evidence = ItTicketResolutionInput::normalize([...$resolution, 'note' => $note]);
            $note = $evidence['note'];

            app(ItTicketDraftService::class)->consumeFromInput($actor, $draftCommit, ItTicketDraftPurpose::PublicResolution, (int) $locked->id);

            $resolved = $this->transitionService->transition(
                $locked,
                new ItTransitionInput(
                    actor: $actor,
                    to: ItWorkflowState::Resolved,
                    reason: 'Technician resolution',
                    resolutionCode: $evidence['resolution_code'],
                    resolutionSummary: $note,
                    resolutionVerification: $evidence['resolution_verification'],
                    source: 'legacy_resolve',
                ),
            );

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
    public function reopenWithReason(ItTicket $ticket, User $actor, string $reason, ?int $expectedVersion = null, ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser): array
    {
        return DB::transaction(function () use ($ticket, $actor, $reason, $expectedVersion, $channel): array {
            $locked = $this->lockTicket($ticket);
            $actor = $this->versions->currentActor($actor);
            $this->guardVisible($locked, $actor);

            if (! $actor->can('reopen', $locked)) {
                throw new AuthorizationException('You are not allowed to reopen this ticket.');
            }
            $this->versions->assertCurrent($locked, $expectedVersion);
            if ($locked->isMerged()) {
                throw new DomainException('This ticket was merged into another — reopen the survivor instead.');
            }
            if (! in_array($locked->status, ['resolved', 'closed'], true)) {
                throw new DomainException('Only resolved or closed tickets can be reopened.');
            }

            $isRequester = (int) $locked->requester_user_id === (int) $actor->id;
            $reopened = $this->transitionService->transition(
                $locked,
                new ItTransitionInput(
                    actor: $actor,
                    to: ItWorkflowState::Submitted,
                    reason: $reason,
                    source: 'legacy_reopen',
                    channel: $channel,
                ),
            );
            // The event identifies this transition's comment while the parent
            // remains locked; do not infer it from the latest conversation row.
            $event = $reopened->events()->where('type', 'reopened')->latest('id')->firstOrFail();
            $comment = $reopened->comments()->findOrFail($event->payload['comment_id']);

            return [
                'ticket' => $reopened->refresh()->load(['assignee', 'requester', 'watchers']),
                'comment' => $comment,
                'is_requester' => $isRequester,
            ];
        });
    }

    public function submitCsat(ItTicket $ticket, User $actor, int $score, ?string $comment, int $expectedVersion): ItTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $score, $comment, $expectedVersion): ItTicket {
            $locked = $this->lockTicket($ticket);
            $actor = $this->versions->currentActor($actor);
            $this->guardVisible($locked, $actor);

            if (! $actor->can('csat', $locked)) {
                throw new AuthorizationException('Only the requester can rate a resolved ticket.');
            }
            $this->versions->assertCurrent($locked, $expectedVersion);
            Validator::make(['score' => $score, 'comment' => $comment], [
                'score' => ['required', 'integer', 'between:1,5'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ])->validate();
            $comment = filled($comment) ? trim($comment) : null;

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
        return $this->setWatcher($ticket, $actor, (int) $actor->id, true)->changed;
    }

    public function unwatch(ItTicket $ticket, User $actor): bool
    {
        return $this->setWatcher($ticket, $actor, (int) $actor->id, false)->changed;
    }

    /** Legacy self-watch adapters omit a version; the browser command requires it. */
    public function setWatcher(ItTicket $ticket, User $actor, int $watcherId, bool $watching, ?int $expectedVersion = null): ItTicketWatcherResult
    {
        return DB::transaction(function () use ($ticket, $actor, $watcherId, $watching, $expectedVersion): ItTicketWatcherResult {
            $locked = $this->lockTicket($ticket);
            // Two technicians can administer each other on different tickets.
            // Acquire both current users in one order rather than actor first.
            $users = User::query()->whereKey(array_unique([(int) $actor->id, $watcherId]))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            if (! $actor || $actor->approved_at === null) {
                throw new AuthorizationException('Your staff access is no longer available.');
            }
            $this->guardVisible($locked, $actor);
            if (! $this->workAccess->canWork($actor, $locked)) {
                throw new AuthorizationException('Only IT staff can change ticket watchers.');
            }
            if ($locked->isMerged()) {
                throw ValidationException::withMessages(['watcher_user_id' => 'Open the current ticket before changing its watchers.']);
            }
            $watcher = $users->get($watcherId);
            if (! $watcher) {
                throw (new ModelNotFoundException)->setModel(User::class, [$watcherId]);
            }
            if ($watching && ! $this->workAccess->canReceiveTicketUpdates($watcher, $locked)) {
                throw ValidationException::withMessages(['watcher_user_id' => 'This person cannot currently receive updates for this ticket.']);
            }

            // A retry can acknowledge its already-achieved desired state. An
            // opposite stale command cannot reverse a newer membership change.
            if ($expectedVersion !== null && $expectedVersion > (int) $locked->lock_version) {
                $this->versions->assertCurrent($locked, $expectedVersion);
            }
            $current = $locked->watchers()->whereKey($watcherId)->exists();
            if ($current === $watching) {
                return new ItTicketWatcherResult($locked, $watcherId, $watching, false);
            }
            $this->versions->assertCurrent($locked, $expectedVersion);

            $watching
                ? $locked->watchers()->syncWithoutDetaching([$watcherId])
                : $locked->watchers()->detach($watcherId);
            if (! $watching) {
                app(ItEmailDeliveryService::class)->stopRemovedWatcherDeliveries($locked, $watcherId);
            }
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            $event = $watching ? 'watcher_added' : 'watcher_removed';
            $audit = $watching ? 'it.ticket.watcher.added' : 'it.ticket.watcher.removed';
            ItTicketEvent::record($locked, $event, $actor->id, [
                'user_id' => $watcherId, 'self' => $watcherId === (int) $actor->id, 'watcher_name' => $watcher->name,
            ]);
            AuditLogger::logOrFail($audit, $locked, [
                'actor_id' => $actor->id,
                'watcher_user_id' => $watcherId,
                'application_scope' => 'single_application',
            ]);

            return new ItTicketWatcherResult($locked, $watcherId, $watching, true);
        });
    }

    private function lockTicket(ItTicket $ticket): ItTicket
    {
        return ItTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
    }

    private function guardVisible(ItTicket $ticket, User $actor, bool $currentEvidence = false): void
    {
        if (! $this->workAccess->canView($actor, $ticket, $currentEvidence)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class, [$ticket->id]);
        }
    }
}
