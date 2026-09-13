<?php

namespace App\Domain\It\Services;

use App\Domain\It\Enums\ItTicketDraftPurpose as Purpose;
use App\Domain\It\Exceptions\ItTicketDraftException as DraftError;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\ItTicketDraft;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Encrypted, actor-owned recovery. Canonical ticket services still own commits. */
final class ItTicketDraftService
{
    public function __construct(
        private readonly ItWorkAccessService $access,
        private readonly ItTicketDraftPayload $payloads,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('it.drafts.enabled', false)
            && $this->days('retention_days') !== null
            && $this->days('terminal_retention_days') !== null;
    }

    /** Initializes metadata only; never hydrates or overwrites saved fields. */
    public function initialize(User $actor, Purpose $purpose, ?int $ticketId, ?string $requestUuid): array
    {
        $this->requireEnabled();
        if ($purpose->requiresTicket() ? $ticketId === null || $requestUuid !== null : $ticketId !== null || ! Str::isUuid($requestUuid)) {
            throw ValidationException::withMessages(['context' => 'Choose the canonical context for this draft purpose.']);
        }
        $requestUuid = $requestUuid !== null ? strtolower($requestUuid) : null;

        return DB::transaction(function () use ($actor, $purpose, $ticketId, $requestUuid): array {
            $ticket = $ticketId !== null ? ItTicket::query()->whereKey($ticketId)->lockForUpdate()->first() : null;
            $current = $this->currentActor($actor);
            $this->authorize($current, $purpose, $ticket);
            $contextKey = $ticketId !== null ? 'ticket:'.$ticketId : 'request:'.$requestUuid;
            $draft = ItTicketDraft::query()->where('actor_user_id', $current->id)
                ->where('purpose', $purpose->value)->where('context_key', $contextKey)
                ->where('audience', $purpose->audience())->lockForUpdate()->first();
            if (! $draft) {
                $draft = ItTicketDraft::query()->create([
                    'actor_user_id' => $current->id, 'purpose' => $purpose,
                    'context_key' => $contextKey, 'audience' => $purpose->audience(),
                    'draft_uuid' => (string) Str::uuid(), 'it_ticket_id' => $ticketId, 'request_uuid' => $requestUuid,
                    'state' => 'active', 'revision' => 0, 'base_ticket_version' => $ticket?->lock_version,
                    'bound_scope' => [], 'expires_at' => now()->addDays($this->days('retention_days')),
                ]);
                $this->audit($draft, $current, 'initialized');
            }
            $this->payloads->assertScope($current, $ticket, $draft->bound_scope ?? []);

            return $this->metadata($draft, $current, $ticket);
        });
    }

    public function inspect(User $actor, string $uuid): array
    {
        return $this->locked($actor, $uuid, fn ($draft, $current, $ticket): array => $this->metadata($draft, $current, $ticket));
    }

    /** Only explicit resume is permitted to return decrypted fields. */
    public function resume(User $actor, string $uuid): array
    {
        return $this->locked($actor, $uuid, function ($draft, $current, $ticket): array {
            $this->requireActive($draft, $current, $ticket);
            $this->audit($draft, $current, 'resumed');

            return [
                'draft' => $this->metadata($draft, $current, $ticket),
                'payload' => $draft->encrypted_payload ?? ['fields' => [], 'step_index' => 0],
                'attachments' => app(ItTicketDraftAttachmentService::class)->forResume($draft),
            ];
        });
    }

    public function save(User $actor, string $uuid, int $expectedRevision, array $fields, int $stepIndex, ?int $baseVersion): array
    {
        Validator::make(['expected_revision' => $expectedRevision, 'step_index' => $stepIndex], [
            'expected_revision' => ['integer', 'min:0'], 'step_index' => ['integer', 'between:0,20'],
        ])->validate();

        return $this->locked($actor, $uuid, function ($draft, $current, $ticket) use ($expectedRevision, $fields, $stepIndex, $baseVersion): array {
            $this->requireActive($draft, $current, $ticket);
            $metadata = $this->metadata($draft, $current, $ticket);
            if (! $metadata['capabilities']['save']) {
                throw new DraftError('draft_submission_exists', 409, 'Check the saved request before changing this draft.', ['current' => $metadata]);
            }
            $safe = $this->payloads->validate($draft->purpose, $fields);
            $scope = $this->payloads->bind($current, $ticket, $safe, $draft->bound_scope ?? []);
            $baseVersion = $this->baseVersionFor($draft, $ticket, $baseVersion);
            $payload = ['fields' => $safe, 'step_index' => $stepIndex];
            $hash = $this->fingerprint([$payload, $baseVersion]);
            if ($expectedRevision !== $draft->revision) {
                if ($draft->last_mutation === 'saved' && $draft->last_base_revision === $expectedRevision
                    && $draft->payload_hash !== null && hash_equals($draft->payload_hash, $hash)) {
                    return $metadata;
                }
                $this->conflict($draft, $current, $ticket);
            }
            if ($draft->payload_hash !== null && hash_equals($draft->payload_hash, $hash)) {
                return $metadata;
            }
            $draft->forceFill([
                'encrypted_payload' => $payload, 'payload_hash' => $hash, 'bound_scope' => $scope,
                'base_ticket_version' => $baseVersion, 'last_base_revision' => $expectedRevision,
                'revision' => $draft->revision + 1, 'last_mutation' => 'saved',
                'saved_at' => now(), 'expires_at' => now()->addDays($this->days('retention_days')),
            ])->save();
            $this->audit($draft, $current, 'saved');

            return $this->metadata($draft, $current, $ticket);
        });
    }

    /** Reauthorize an unsaved RAM snapshot without hydrating or changing a draft. */
    public function validateCandidate(User $actor, string $uuid, int $expectedRevision, string $candidateUuid, array $fields, int $stepIndex, ?int $baseVersion, array $boundScopes = []): array
    {
        Validator::make(['expected_revision' => $expectedRevision, 'candidate_uuid' => $candidateUuid, 'step_index' => $stepIndex], [
            'expected_revision' => ['integer', 'min:0'], 'candidate_uuid' => ['uuid'], 'step_index' => ['integer', 'between:0,20'],
        ])->validate();

        return $this->locked($actor, $uuid, function ($draft, $current, $ticket) use ($expectedRevision, $candidateUuid, $fields, $baseVersion, $boundScopes): array {
            $this->requireActive($draft, $current, $ticket);
            $metadata = $this->metadata($draft, $current, $ticket);
            if (! $metadata['capabilities']['save']) {
                throw new DraftError('draft_submission_exists', 409, 'Check the saved request before recovering this unsent work.', ['current' => $metadata]);
            }
            // Saved metadata may have older bindings than the latest local
            // edit. Authorize both before disclosing even a conflict response.
            $safe = $this->payloads->validate($draft->purpose, $fields);
            $this->assertCandidateScopes($current, $ticket, $boundScopes);
            $this->payloads->bind($current, $ticket, $safe, $draft->bound_scope ?? []);
            $baseVersion = $this->baseVersionFor($draft, $ticket, $baseVersion);
            if ($expectedRevision !== $draft->revision) {
                $this->conflict($draft, $current, $ticket);
            }

            return ['draft' => $metadata, 'candidate' => [
                'candidate_uuid' => $candidateUuid, 'actor_user_id' => (int) $current->id,
                'draft_uuid' => $draft->draft_uuid, 'purpose' => $draft->purpose->value,
                'context_key' => $draft->context_key, 'revision' => $draft->revision,
                'base_ticket_version' => $baseVersion, 'authorized' => true,
            ]];
        });
    }

    private function baseVersionFor(ItTicketDraft $draft, ?ItTicket $ticket, ?int $baseVersion): ?int
    {
        $baseVersion ??= $draft->base_ticket_version;
        if (! $ticket && $baseVersion !== null) {
            throw ValidationException::withMessages(['base_ticket_version' => 'An intake draft has no existing ticket version.']);
        }
        if ($ticket && $baseVersion !== null && ($baseVersion < 1 || $baseVersion > $ticket->lock_version)) {
            throw ValidationException::withMessages(['base_ticket_version' => 'Use a version that you have reviewed for this ticket.']);
        }

        return $baseVersion;
    }

    /**
     * Permission proof for an existing document-memory buffer. This reads no
     * draft row and grants no write; it remains available with storage disabled.
     * The client must bind the nonce to its exact frozen snapshot and consume
     * the proof once. Canonical mutations still reauthorize every submission.
     */
    public function validateLocalCandidate(User $actor, Purpose $purpose, ?int $ticketId, ?string $requestUuid,
        string $memoryUuid, string $candidateUuid, array $fields, int $stepIndex, ?int $baseVersion, array $boundScopes = []): array
    {
        if ($purpose->requiresTicket() ? $ticketId === null || $requestUuid !== null : $ticketId !== null || ! Str::isUuid($requestUuid)) {
            throw ValidationException::withMessages(['context' => 'Choose the canonical context for this draft purpose.']);
        }
        Validator::make(['memory_uuid' => $memoryUuid, 'candidate_uuid' => $candidateUuid, 'step_index' => $stepIndex, 'bound_scopes' => $boundScopes], [
            'memory_uuid' => ['uuid'], 'candidate_uuid' => ['uuid'], 'step_index' => ['integer', 'between:0,20'],
            'bound_scopes' => ['array', 'list', 'max:100'], 'bound_scopes.*' => ['array'],
        ])->validate();
        $requestUuid = $requestUuid !== null ? strtolower($requestUuid) : null;

        return DB::transaction(function () use ($actor, $purpose, $ticketId, $requestUuid, $memoryUuid, $candidateUuid, $fields, $baseVersion, $boundScopes): array {
            $ticket = $ticketId !== null ? ItTicket::query()->whereKey($ticketId)->lockForUpdate()->first() : null;
            $current = $this->currentActor($actor);
            $this->authorize($current, $purpose, $ticket);
            $safe = $this->payloads->validate($purpose, $fields);
            $this->assertCandidateScopes($current, $ticket, $boundScopes);
            $this->payloads->bind($current, $ticket, $safe);
            if ((! $ticket && $baseVersion !== null) || ($ticket && $baseVersion !== null && ($baseVersion < 1 || $baseVersion > $ticket->lock_version))) {
                throw ValidationException::withMessages(['base_ticket_version' => 'Keep the original reviewed ticket version with this work.']);
            }
            if ($requestUuid !== null) {
                $receipt = ItTicketCommandReceipt::query()->where('actor_user_id', $current->id)
                    ->where('channel', ItTicketCommandReceipt::CHANNEL)->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)
                    ->where('request_uuid', $requestUuid)->whereNotNull('committed_at')->first();
                if ($receipt) {
                    if (! $receipt->ticket || ! $this->access->canView($current, $receipt->ticket)) {
                        throw DraftError::unavailable();
                    }
                    throw new DraftError('draft_submission_exists', 409, 'Check the saved request before recovering this unsent work.', [
                        'recovery_url' => route('it.ticket-commands.show', ['requestUuid' => $requestUuid], false),
                    ]);
                }
            }
            $blocker = null;
            if ($ticket?->isMerged()) {
                $blocker = ['code' => 'ticket_merged', 'message' => 'Continue on the surviving ticket. This work stays with its original ticket.'];
            } elseif ($ticket && ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
                $blocker = ['code' => 'ticket_settled', 'message' => 'Reopen this ticket before submitting this work.'];
            } elseif ($ticket && in_array($purpose, [Purpose::TicketEdit, Purpose::PublicResolution], true)
                && $baseVersion !== (int) $ticket->lock_version) {
                $blocker = ['code' => 'ticket_changed', 'message' => 'Review the current ticket before applying this work.'];
            }

            return ['candidate' => [
                'kind' => 'memory', 'memory_uuid' => $memoryUuid, 'candidate_uuid' => $candidateUuid,
                'actor_user_id' => (int) $current->id, 'purpose' => $purpose->value,
                'context_key' => $ticketId !== null ? 'ticket:'.$ticketId : 'request:'.$requestUuid,
                'base_ticket_version' => $baseVersion, 'current_ticket_version' => $ticket?->lock_version,
                'authorized' => true, 'capabilities' => ['submit' => $blocker === null], 'blocker' => $blocker,
            ]];
        });
    }

    private function assertCandidateScopes(User $actor, ?ItTicket $ticket, array $scopes): void
    {
        Validator::make(['bound_scopes' => $scopes], [
            'bound_scopes' => ['array', 'list', 'max:100'], 'bound_scopes.*' => ['array'],
        ])->validate();
        foreach ($scopes as $scope) {
            $this->payloads->assertScope($actor, $ticket, $this->payloads->validateScope($scope));
        }
    }

    public function discard(User $actor, string $uuid, int $expectedRevision): array
    {
        $this->locked($actor, $uuid, function ($draft, $current, $ticket) use ($expectedRevision): array {
            if ($draft->state === 'discarded' && in_array($expectedRevision, [$draft->revision, $draft->last_base_revision], true)) {
                return $this->metadata($draft, $current, $ticket);
            }
            if ($expectedRevision !== $draft->revision) {
                $this->conflict($draft, $current, $ticket);
            }
            if ($draft->state === 'consumed') {
                return $this->metadata($draft, $current, $ticket);
            }
            $this->terminate($draft, $current, 'discarded');

            return $this->metadata($draft, $current, $ticket);
        });

        $cleanup = $this->cleanupGeneration($uuid);

        return [...$this->inspect($actor, $uuid), 'cleanup' => $cleanup];
    }

    /** The old UUID/revision must name a terminal generation, never active work. */
    public function startNew(User $actor, string $uuid, int $expectedRevision): array
    {
        $oldId = null;
        $result = $this->locked($actor, $uuid, function ($draft, $current, $ticket) use ($expectedRevision, &$oldId): array {
            if ($expectedRevision !== $draft->revision || ($draft->state === 'active' && ! $draft->isExpired())) {
                $this->conflict($draft, $current, $ticket);
            }
            if ($this->submission($draft, $current) !== null) {
                throw new DraftError('draft_submission_exists', 409, 'This request is already saved. Start a different request from the service desk.');
            }
            $oldId = (int) $draft->id;
            $draft->forceFill([
                'draft_uuid' => (string) Str::uuid(), 'state' => 'active', 'revision' => $draft->revision + 1,
                'last_base_revision' => $expectedRevision, 'last_mutation' => 'restarted',
                'encrypted_payload' => null, 'payload_hash' => null, 'bound_scope' => [],
                'saved_at' => null, 'consumed_at' => null, 'discarded_at' => null,
                'base_ticket_version' => $ticket?->lock_version,
                'expires_at' => now()->addDays($this->days('retention_days')),
            ])->save();
            $this->audit($draft, $current, 'restarted');

            return $this->metadata($draft, $current, $ticket);
        });

        return [...$result, 'cleanup' => app(ItTicketDraftAttachmentService::class)->cleanupGeneration($oldId, $uuid)];
    }

    /** Optional adapter used by canonical writers, never a second write endpoint. */
    public function consumeFromInput(User $actor, array $input, Purpose $purpose, ?int $ticketId = null, ?string $requestUuid = null, ItTicket|ItTicketComment|null $attachmentTarget = null): void
    {
        if (array_intersect(['draft_uuid', 'draft_revision', 'draft_actor_user_id'], array_keys($input)) === []) {
            return;
        }
        $data = Validator::make($input, [
            'draft_uuid' => ['required', 'uuid'], 'draft_revision' => ['required', 'integer', 'min:0'],
            'draft_actor_user_id' => ['required', 'integer', 'min:1'],
        ])->validate();
        if ((int) $data['draft_actor_user_id'] !== (int) $actor->id) {
            throw new DraftError('access_unavailable', 403, 'The signed-in account changed. This draft cannot be submitted by this account.');
        }
        $this->consume($actor, $data['draft_uuid'], (int) $data['draft_revision'], $purpose, $ticketId, $requestUuid, $attachmentTarget);
    }

    /** Called only inside a successful canonical write's enclosing transaction. */
    public function consume(User $actor, string $uuid, int $expectedRevision, Purpose $purpose, ?int $ticketId, ?string $requestUuid, ItTicket|ItTicketComment|null $attachmentTarget = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Draft consumption must share the canonical commit transaction.');
        }
        $this->locked($actor, $uuid, function ($draft, $current, $ticket) use ($expectedRevision, $purpose, $ticketId, $requestUuid, $attachmentTarget): void {
            if ($draft->purpose !== $purpose || (int) $draft->it_ticket_id !== (int) $ticketId || $draft->request_uuid !== $requestUuid) {
                throw DraftError::unavailable();
            }
            // Replays must be resolved by the canonical business receipt or
            // version guard. A consumed generation cannot authorize a new write.
            $this->requireActive($draft, $current, $ticket);
            if ($draft->revision !== $expectedRevision) {
                $this->conflict($draft, $current, $ticket);
            }
            $metadata = $this->metadata($draft, $current, $ticket);
            if (! $metadata['capabilities']['submit']) {
                throw new DraftError('draft_submission_blocked', 409, 'Review the current request before submitting this draft.', ['current' => $metadata]);
            }
            app(ItTicketDraftAttachmentService::class)->transfer($draft, $attachmentTarget);
            $this->terminate($draft, $current, 'consumed');
        });
    }

    /** @internal Canonical attachment lifecycle; callers never bypass reauthorization. */
    public function forAttachmentMutation(User $actor, string $uuid, Closure $operation, bool $write = true): mixed
    {
        return $this->locked($actor, $uuid, function ($draft, $current, $ticket) use ($operation, $write): mixed {
            $this->requireActive($draft, $current, $ticket);
            $metadata = $this->metadata($draft, $current, $ticket);
            if ($write && ! $metadata['capabilities']['save']) {
                throw new DraftError('draft_submission_exists', 409, 'Check the saved request before changing this draft.', ['current' => $metadata]);
            }

            return $operation($draft, $current, $ticket);
        });
    }

    /** @internal Shared revision boundary for the serialized attachment writer. */
    public function assertAttachmentRevision(ItTicketDraft $draft, User $actor, int $expectedRevision): void
    {
        if ($draft->revision !== $expectedRevision) {
            $this->conflict($draft, $actor, $draft->ticket);
        }
    }

    /** @internal Called while holding the authorized draft lock. */
    public function recordAttachmentChange(ItTicketDraft $draft, User $actor, string $action): void
    {
        $draft->forceFill([
            'last_base_revision' => $draft->revision, 'revision' => $draft->revision + 1, 'last_mutation' => $action,
            'saved_at' => now(), 'expires_at' => now()->addDays($this->days('retention_days')),
        ])->save();
        $this->audit($draft, $actor, $action);
    }

    private function cleanupGeneration(string $uuid): array
    {
        $draft = ItTicketDraft::query()->where('draft_uuid', $uuid)->first();
        if ($draft) {
            return app(ItTicketDraftAttachmentService::class)->cleanupGeneration((int) $draft->id, $uuid);
        }

        return ['deleted' => 0, 'failed' => 0];
    }

    private function locked(User $actor, string $uuid, Closure $operation): mixed
    {
        $this->requireEnabled();
        // Peek only at the authenticated owner's binding to select the lock
        // order. Re-read UUID and owner under the lock before any decryption.
        $peek = ItTicketDraft::query()->where('draft_uuid', $uuid)->where('actor_user_id', $actor->id)->first();
        if (! $peek) {
            throw DraftError::unavailable();
        }

        return DB::transaction(function () use ($actor, $uuid, $peek, $operation): mixed {
            $ticket = $peek->it_ticket_id !== null ? ItTicket::query()->whereKey($peek->it_ticket_id)->lockForUpdate()->first() : null;
            $current = $this->currentActor($actor);
            $draft = ItTicketDraft::query()->whereKey($peek->id)->where('draft_uuid', $uuid)
                ->where('actor_user_id', $current->id)->lockForUpdate()->first();
            if (! $draft) {
                throw DraftError::unavailable();
            }
            $this->authorize($current, $draft->purpose, $ticket);
            $this->payloads->assertScope($current, $ticket, $draft->bound_scope ?? []);

            return $operation($draft, $current, $ticket);
        });
    }

    private function currentActor(User $actor): User
    {
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        if (! $current || $current->approved_at === null || (! $current->canDo('it.request') && ! $current->canDo('it.view'))) {
            throw new DraftError('access_unavailable', 403, 'Your staff access is no longer available.');
        }

        return $current;
    }

    private function authorize(User $actor, Purpose $purpose, ?ItTicket $ticket): void
    {
        if ($purpose->requiresTicket()) {
            if (! $ticket || ! ($purpose->requiresManage() ? $this->access->canWork($actor, $ticket) : $this->access->canView($actor, $ticket))) {
                throw DraftError::unavailable();
            }
        } elseif ($purpose->requiresManage() ? ! $actor->canDo('it.manage') : ! $actor->can('create', ItTicket::class)) {
            throw DraftError::unavailable();
        }
    }

    private function metadata(ItTicketDraft $draft, User $actor, ?ItTicket $ticket): array
    {
        $expired = $draft->isExpired();
        $active = $draft->state === 'active' && ! $expired;
        $submission = $this->submission($draft, $actor);
        $fileCounts = $draft->attachments()->where('draft_generation_uuid', $draft->draft_uuid)
            ->selectRaw('draft_storage_state, COUNT(*) AS file_count')->groupBy('draft_storage_state')
            ->pluck('file_count', 'draft_storage_state');
        $readyFiles = (int) $fileCounts->get('ready', 0);
        $pendingFiles = (int) $fileCounts->get('reserved', 0) + (int) $fileCounts->get('failed', 0);
        $blocker = null;
        if ($submission !== null) {
            $blocker = ['code' => 'request_committed', 'message' => 'This request is already saved. Check the saved request.',
                'recovery_url' => route('it.ticket-commands.show', ['requestUuid' => $draft->request_uuid], false)];
        } elseif ($ticket?->isMerged()) {
            $blocker = ['code' => 'ticket_merged', 'message' => 'Continue on the surviving ticket. This draft stays with its original context.'];
        } elseif ($ticket && ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)) {
            $blocker = ['code' => 'ticket_settled', 'message' => 'Reopen this ticket before submitting this draft.'];
        } elseif ($ticket && in_array($draft->purpose, [Purpose::TicketEdit, Purpose::PublicResolution], true)
            && $draft->base_ticket_version !== (int) $ticket->lock_version) {
            $blocker = ['code' => 'ticket_changed', 'message' => 'Review the current ticket before applying this draft.'];
        } elseif ($pendingFiles > 0) {
            $blocker = ['code' => 'draft_files_pending', 'message' => 'Finish or remove the pending file uploads before submitting.'];
        }

        return [
            'draft_uuid' => $draft->draft_uuid, 'purpose' => $draft->purpose->value,
            'context_key' => $draft->context_key, 'audience' => $draft->audience,
            'ticket_id' => $draft->it_ticket_id, 'request_uuid' => $draft->request_uuid,
            'revision' => $draft->revision, 'state' => $expired ? 'expired' : $draft->state,
            'has_content' => $active && ($draft->payload_hash !== null || $readyFiles + $pendingFiles > 0),
            'files' => ['ready' => $readyFiles, 'pending' => $pendingFiles, 'cleanup_pending' => (int) $fileCounts->get('cleanup_pending', 0)],
            'saved_at' => $draft->saved_at?->toIso8601String(), 'expires_at' => $draft->expires_at->toIso8601String(),
            'base_ticket_version' => $draft->base_ticket_version, 'current_ticket_version' => $ticket?->lock_version,
            'capabilities' => ['read' => $active, 'save' => $active && $submission === null,
                'submit' => $active && $blocker === null, 'discard' => $draft->state !== 'consumed',
                'start_new' => ! $active && $submission === null],
            'blocker' => $blocker,
        ];
    }

    private function submission(ItTicketDraft $draft, User $actor): ?ItTicketCommandReceipt
    {
        if ($draft->request_uuid === null) {
            return null;
        }
        $receipt = ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
            ->where('channel', ItTicketCommandReceipt::CHANNEL)->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)
            ->where('request_uuid', $draft->request_uuid)->whereNotNull('committed_at')->first();
        if ($receipt && (! $receipt->ticket || ! $this->access->canView($actor, $receipt->ticket))) {
            throw DraftError::unavailable();
        }

        return $receipt;
    }

    private function terminate(ItTicketDraft $draft, User $actor, string $state): void
    {
        $draft->forceFill([
            'state' => $state, 'last_mutation' => $state, 'last_base_revision' => $draft->revision,
            'revision' => $draft->revision + 1, 'encrypted_payload' => null, 'payload_hash' => null,
            $state === 'consumed' ? 'consumed_at' : 'discarded_at' => now(),
            'expires_at' => now()->addDays($this->days('terminal_retention_days')),
        ])->save();
        $this->audit($draft, $actor, $state);
    }

    private function requireActive(ItTicketDraft $draft, User $actor, ?ItTicket $ticket): void
    {
        if ($draft->state !== 'active' || $draft->isExpired()) {
            throw new DraftError('draft_terminal', 409, 'This draft generation is no longer editable.', ['current' => $this->metadata($draft, $actor, $ticket)]);
        }
    }

    private function conflict(ItTicketDraft $draft, User $actor, ?ItTicket $ticket): never
    {
        throw new DraftError('draft_conflict', 409, 'This draft changed in another request. Review the current saved version before reapplying your text.',
            ['current' => $this->metadata($draft, $actor, $ticket)]);
    }

    private function audit(ItTicketDraft $draft, User $actor, string $action): void
    {
        AuditLogger::logOrFail('it.draft.'.$action, $draft, [
            'actor_id' => (int) $actor->id, 'purpose' => $draft->purpose->value,
            'draft_uuid' => $draft->draft_uuid, 'revision' => $draft->revision, 'state' => $draft->state,
        ]);
    }

    private function fingerprint(array $value): string
    {
        $sort = function (array $array) use (&$sort): array {
            if (! array_is_list($array)) {
                ksort($array);
            }
            foreach ($array as $key => $item) {
                if (is_array($item)) {
                    $array[$key] = $sort($item);
                }
            }

            return $array;
        };

        return hash_hmac('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function days(string $key): ?int
    {
        $value = config('it.drafts.'.$key);

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false ? (int) $value : null;
    }

    private function requireEnabled(): void
    {
        if (! $this->enabled()) {
            throw new DraftError('drafts_disabled', 503, 'Saved draft recovery is not enabled. Keep this form open to retain your work.');
        }
    }
}
