<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Models\ItAttachment;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ItTicketMergeService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** Bounded, explainable suggestions; selection still requires the canonical merge review. */
    public function candidates(ItTicket $source, User $actor): array
    {
        $actor = User::query()->whereKey($actor->getKey())->whereNotNull('approved_at')->first();
        $source = ItTicket::query()->find($source->getKey());
        if (! $actor || ! $source || ! $this->workAccess->canWork($actor, $source)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class);
        }
        if ($source->isMerged() || $source->status === 'closed') {
            return [];
        }

        return $this->workAccess->applyViewScope(ItTicket::query(), $actor)
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->whereNull('merged_into_ticket_id')->whereKeyNot($source->id)
            ->where('requester_user_id', $source->requester_user_id)
            ->latest('id')->limit(100)->get()
            ->filter(fn (ItTicket $candidate) => $this->workAccess->canWork($actor, $candidate)
                && $this->sharesConversationAudience($source, $candidate))
            ->map(fn (ItTicket $candidate): array => [
                'id' => (int) $candidate->id, 'reference' => $candidate->reference,
                'title' => $candidate->title, 'priority' => $candidate->priority,
                'status' => $candidate->status, 'lock_version' => (int) $candidate->lock_version,
                'duplicate_reasons' => $this->duplicateReasons($source, $candidate),
            ])
            ->sortByDesc(fn (array $candidate): int => $candidate['duplicate_reasons'] === [] ? 0 : 1)
            ->take(50)->values()->all();
    }

    /** Discovery never grants access or decides that records should merge. */
    public function duplicateSuggestions(User $actor, array $draft = [], ?ItTicket $source = null): array
    {
        $actor = User::query()->whereKey($actor->getKey())->whereNotNull('approved_at')->first();
        if (! $actor) {
            throw new AuthorizationException;
        }
        if ($source) {
            $source = ItTicket::query()->find($source->getKey());
            if (! $source || ! $this->workAccess->canWork($actor, $source)) {
                throw (new ModelNotFoundException)->setModel(ItTicket::class);
            }
            if ($source->isMerged() || ! in_array($source->status, ItTicket::OPEN_STATUSES, true)) {
                return [];
            }
        } else {
            Gate::forUser($actor)->authorize('create', ItTicket::class);
            Validator::make($draft, ['title' => ['required', 'string', 'min:3', 'max:255'],
                'site_id' => ['nullable', 'integer', 'min:1'], 'is_organisation_wide' => ['sometimes', 'boolean'],
                'work_type' => ['required', 'in:'.implode(',', ItTicket::INTAKE_WORK_TYPES)],
                'it_service_id' => ['nullable', 'integer', 'min:1']])->validate();
            $siteId = isset($draft['site_id']) ? (int) $draft['site_id'] : null;
            $wide = (bool) ($draft['is_organisation_wide'] ?? false);
            if (! $this->workAccess->canAssignScope($actor, $siteId, $wide)) {
                throw new AuthorizationException('This Site is not available for ticket suggestions.');
            }
            $serviceId = isset($draft['it_service_id']) ? (int) $draft['it_service_id'] : null;
            if (! $actor->canDo('it.manage') && ($serviceId !== null || $draft['work_type'] !== 'incident')) {
                throw new AuthorizationException;
            }
            if ($serviceId !== null && ! ItService::query()->whereKey($serviceId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['it_service_id' => 'Choose an available service.']);
            }
            // Unsaved matching context only. No ticket, draft or command is created by this read.
            $source = new ItTicket(['title' => $draft['title'], 'site_id' => $siteId,
                'is_organisation_wide' => $wide, 'work_type' => $draft['work_type'], 'it_service_id' => $serviceId]);
        }

        return $this->workAccess->applyViewScope(ItTicket::query(), $actor)
            ->whereIn('status', ItTicket::OPEN_STATUSES)->whereNull('merged_into_ticket_id')
            ->where('site_id', $source->site_id)->where('is_organisation_wide', $source->is_organisation_wide)
            ->where('work_type', $source->work_type)->when($source->exists, fn ($query) => $query->whereKeyNot($source->id))
            ->latest('id')->limit(100)->get()
            ->map(function (ItTicket $candidate) use ($source): ?array {
                $reasons = $this->duplicateReasons($source, $candidate);

                return $reasons === [] ? null : ['id' => (int) $candidate->id, 'reference' => $candidate->reference,
                    'title' => $candidate->title, 'status' => $candidate->status, 'reasons' => $reasons,
                    'href' => route('it.tickets.show', $candidate, false)];
            })->filter()->take(10)->values()->all();
    }

    /** Match only visible title/context fields; never inspect conversations or execute AI. */
    private function duplicateReasons(ItTicket $source, ItTicket $candidate): array
    {
        if ($source->site_id !== $candidate->site_id
            || $source->is_organisation_wide !== $candidate->is_organisation_wide
            || $source->work_type !== $candidate->work_type) {
            return [];
        }
        $normalize = fn (string $title): string => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)) ?? '');
        $sourceTitle = $normalize($source->title);
        $candidateTitle = $normalize($candidate->title);
        if ($sourceTitle !== '' && $sourceTitle === $candidateTitle) {
            return ['same_title'];
        }
        if ($source->it_service_id === null || $source->it_service_id !== $candidate->it_service_id) {
            return [];
        }
        $words = fn (string $title): array => array_unique(array_filter(explode(' ', $title),
            fn (string $word): bool => mb_strlen($word) >= 4));

        return count(array_intersect($words($sourceTitle), $words($candidateTitle))) >= 2
            ? ['same_service_and_title_words'] : [];
    }

    /**
     * Resolve navigation only: a merge never grants access to another record.
     * Keep the original IDs on commands, approvals and other historical evidence.
     */
    public function destinationForViewer(ItTicket $source, User $viewer): ?ItTicket
    {
        $viewer = User::query()->whereKey($viewer->getKey())->whereNotNull('approved_at')->first();
        if (! $viewer) {
            return null;
        }

        $current = ItTicket::query()->find($source->getKey());
        $seen = [];
        while ($current instanceof ItTicket) {
            $id = (int) $current->getKey();
            if (isset($seen[$id]) || ! $this->workAccess->canView($viewer, $current)) {
                return null;
            }
            $seen[$id] = true;
            if (! $current->isMerged()) {
                return $id === (int) $source->getKey() ? null : $current;
            }
            $current = ItTicket::query()->find($current->merged_into_ticket_id);
        }

        return null;
    }

    /** Direct predecessors form a navigable history without flattening or copying evidence. */
    public function originalRecords(ItTicket $ticket, User $viewer, int $page = 1): array
    {
        $viewer = User::query()->whereKey($viewer->getKey())->whereNotNull('approved_at')->first();
        $ticket = ItTicket::query()->find($ticket->getKey());
        if (! $viewer || ! $ticket || ! $this->workAccess->canView($viewer, $ticket)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class);
        }
        $records = $this->workAccess->applyViewScope(ItTicket::query(), $viewer)
            ->where('merged_into_ticket_id', $ticket->id)->whereKeyNot($ticket->id)
            ->orderByDesc('id')->simplePaginate(10, ['*'], 'originals_page', max(1, $page));
        $path = route($ticket->isMerged() ? 'it.tickets.original' : 'it.tickets.show', $ticket, false);
        $records->withPath($path);

        return [
            'data' => $records->getCollection()->map(function (ItTicket $original) use ($viewer): array {
                $href = route('it.tickets.original', $original, false);
                $canWork = $this->workAccess->canWork($viewer, $original);

                return ['id' => (int) $original->id, 'reference' => $original->reference, 'title' => $original->title,
                    'href' => $href, 'history_href' => $href.'?tab=history',
                    'tasks_href' => $canWork ? $href.'?tab=tasks' : null,
                    'approvals_href' => $canWork ? $href.'?tab=approvals' : null,
                    'links_href' => $canWork ? $href.'?tab=links' : null];
            })->all(),
            'previous_page_url' => $records->previousPageUrl(),
            'next_page_url' => $records->nextPageUrl(),
        ];
    }

    /** Read both canonical parents together; a preview never authorizes a later write. */
    public function preview(ItTicket $source, ItTicket $target, User $actor, int $sourceVersion, int $targetVersion): array
    {
        return DB::transaction(function () use ($source, $target, $actor, $sourceVersion, $targetVersion): array {
            $ids = [(int) $source->id, (int) $target->id];
            $parents = ItTicket::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $parents->get($ids[0]);
            $target = $parents->get($ids[1]);
            $actor = app(ItTicketVersionService::class)->currentActor($actor);
            if (! $actor->canDo('it.manage')) {
                throw new AuthorizationException('You are not allowed to review a ticket merge.');
            }
            if (! $source || ! $target
                || ! $this->workAccess->canWork($actor, $source)
                || ! $this->workAccess->canWork($actor, $target)) {
                throw (new ModelNotFoundException)->setModel(ItTicket::class, $ids);
            }
            app(ItTicketVersionService::class)->assertCurrent($source, $sourceVersion);
            app(ItTicketVersionService::class)->assertCurrent($target, $targetVersion);

            $blockers = [];
            try {
                $this->guard($source, $target, $actor);
            } catch (DomainException $exception) {
                $blockers[] = $exception->getMessage();
            }

            $audienceFields = ['site_id', 'is_organisation_wide', 'is_sensitive',
                'requester_user_id', 'requested_for_user_id', 'assigned_to_user_id',
                'owner_user_id', 'team_id', 'queue_id'];
            $differences = [];
            foreach ($audienceFields as $field) {
                $left = $source->getAttribute($field);
                $right = $target->getAttribute($field);
                if ($left !== $right) {
                    $differences[] = ['field' => $field, 'source' => $left, 'target' => $right];
                }
            }

            $review = ['source' => $this->previewRecord($source), 'target' => $this->previewRecord($target),
                'access_scope_differences' => $differences, 'lifecycle_blockers' => $blockers];

            return [...$review, 'review_token' => Crypt::encryptString(json_encode([
                'actor_id' => (int) $actor->id, 'source_id' => (int) $source->id, 'target_id' => (int) $target->id,
                'source_version' => $sourceVersion, 'target_version' => $targetVersion,
                'inventory_hash' => $this->reviewHash($review), 'expires_at' => now()->addMinutes(10)->timestamp,
            ], JSON_THROW_ON_ERROR))];
        });
    }

    private function reviewHash(array $review): string
    {
        unset($review['review_token']);

        return hash('sha256', json_encode($review, JSON_THROW_ON_ERROR));
    }

    /** Read-only authorization for the existing bounded RAM store, never a persisted merge draft. */
    public function validateCandidate(ItTicket $source, User $actor, array $input): array
    {
        return DB::transaction(function () use ($source, $actor, $input): array {
            $targetIds = collect($input['bound_scopes'] ?? [])->pluck('target_ticket_id')
                ->push($input['fields']['target_ticket_id'] ?? null)->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $ids = $targetIds->push((int) $source->id)->unique()->sort()->values();
            $parents = ItTicket::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = app(ItTicketVersionService::class)->currentActor($actor);
            if ((int) $input['actor_user_id'] !== (int) $actor->id || (int) $input['ticket_id'] !== (int) $source->id) {
                throw new AuthorizationException('Recover this merge proposal using its original account and ticket.');
            }
            foreach ($ids as $id) {
                if (! $parents->has($id) || ! $this->workAccess->canWork($actor, $parents->get($id))) {
                    throw (new ModelNotFoundException)->setModel(ItTicket::class);
                }
            }
            $source = $parents->get($source->id);
            $version = (int) $input['base_ticket_version'];
            if ($version > (int) $source->lock_version) {
                throw ValidationException::withMessages(['base_ticket_version' => 'Keep the original ticket version with this proposal.']);
            }

            return ['candidate' => ['kind' => 'memory', 'memory_uuid' => $input['memory_uuid'], 'candidate_uuid' => $input['candidate_uuid'],
                'actor_user_id' => (int) $actor->id, 'purpose' => 'merge_work', 'context_key' => 'ticket:'.$source->id,
                'base_ticket_version' => $version, 'current_ticket_version' => (int) $source->lock_version,
                'authorized' => true, 'capabilities' => ['submit' => false],
                'blocker' => ['code' => 'merge_review_required', 'message' => 'Review both current tickets before using this retained proposal.']]];
        });
    }

    /** Durable receipts reuse the canonical actor/operation inventory; no duplicate merge system. */
    public function execute(ItTicket $source, ItTicket $target, User $actor, array $input): array
    {
        return DB::transaction(function () use ($source, $target, $actor, $input): array {
            [$source, $target, $actor] = $this->commandContext($source, $target, $actor);
            Validator::make($input, [
                'actor_user_id' => ['required', 'integer', 'in:'.$actor->id], 'request_uuid' => ['required', 'uuid'],
                'source_version' => ['required', 'integer', 'min:1'], 'target_version' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:1000'], 'review_token' => ['required', 'string', 'max:10000'],
            ])->validate();
            $reason = trim($input['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Explain why these tickets are duplicates.']);
            }
            $hash = hash('sha256', json_encode([(int) $source->id, (int) $target->id,
                (int) $input['source_version'], (int) $input['target_version'], $reason], JSON_THROW_ON_ERROR));
            $receipt = $this->receipt($actor, $input['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                $result = $this->commandResult($source, $target, $actor, $receipt);
                if ($result['status'] !== 'cancelled' && ! hash_equals($receipt->request_hash, $hash)) {
                    throw new ItTicketCommandConflict;
                }

                return $result;
            }
            try {
                $proof = json_decode(Crypt::decryptString($input['review_token']), true, flags: JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                $proof = null;
            }
            $review = $this->preview($source, $target, $actor, (int) $input['source_version'], (int) $input['target_version']);
            if (! is_array($proof) || ($proof['actor_id'] ?? null) !== (int) $actor->id
                || ($proof['source_id'] ?? null) !== (int) $source->id || ($proof['target_id'] ?? null) !== (int) $target->id
                || ($proof['source_version'] ?? null) !== (int) $input['source_version']
                || ($proof['target_version'] ?? null) !== (int) $input['target_version']
                || ! is_int($proof['expires_at'] ?? null) || $proof['expires_at'] <= now()->timestamp
                || ! is_string($proof['inventory_hash'] ?? null) || ! hash_equals($proof['inventory_hash'], $this->reviewHash($review))) {
                throw ValidationException::withMessages(['review_token' => 'The merge review changed or expired. Review both tickets again before merging.']);
            }
            $receipt = $this->receipt($actor, $input['request_uuid'])->create([
                'actor_user_id' => $actor->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                'operation' => ItTicketCommandReceipt::MERGE_OPERATION, 'request_uuid' => $input['request_uuid'], 'request_hash' => $hash,
            ]);
            $target = $this->merge($source, $target, $actor, $reason, (int) $input['source_version'], (int) $input['target_version']);
            $source->refresh();
            $receipt->forceFill(['it_ticket_id' => $source->id, 'committed_at' => now(),
                'committed_ticket_version' => $source->lock_version, 'result_metadata' => [
                    'state' => 'committed', 'target_id' => (int) $target->id, 'target_version' => (int) $target->lock_version,
                ]])->save();

            return $this->commandResult($source, $target, $actor, $receipt, false);
        });
    }

    public function recover(ItTicket $source, ItTicket $target, User $actor, string $uuid, bool $cancel = false): array
    {
        return DB::transaction(function () use ($source, $target, $actor, $uuid, $cancel): array {
            [$source, $target, $actor] = $this->commandContext($source, $target, $actor);
            Validator::make(['request_uuid' => $uuid], ['request_uuid' => ['required', 'uuid']])->validate();
            $receipt = $this->receipt($actor, $uuid)->lockForUpdate()->first();
            $created = false;
            if (! $receipt && $cancel) {
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $actor->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                    'operation' => ItTicketCommandReceipt::MERGE_OPERATION, 'request_uuid' => $uuid,
                    'request_hash' => hash('sha256', json_encode(['cancelled', (int) $source->id, (int) $target->id], JSON_THROW_ON_ERROR)),
                    'it_ticket_id' => $source->id, 'result_metadata' => ['state' => 'cancelled', 'target_id' => (int) $target->id,
                        'cancelled_at' => now()->toIso8601String()],
                ]);
                AuditLogger::logOrFail('it.ticket.merge.command.cancelled', $source, [
                    'actor_id' => $actor->id, 'target_ticket_id' => $target->id, 'request_uuid' => $uuid,
                    'application_scope' => 'single_application',
                ]);
                $created = true;
            }
            if (! $receipt) {
                throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
            }

            return $this->commandResult($source, $target, $actor, $receipt, ! $created);
        });
    }

    private function commandContext(ItTicket $source, ItTicket $target, User $actor): array
    {
        $parents = ItTicket::query()->whereKey([$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = $parents->get($source->id);
        $target = $parents->get($target->id);
        $actor = app(ItTicketVersionService::class)->currentActor($actor);
        if (! $source || ! $target || ! $this->workAccess->canWork($actor, $source) || ! $this->workAccess->canWork($actor, $target)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class);
        }
        if (! Schema::hasColumns('it_ticket_command_receipts', ['result_metadata', 'committed_ticket_version'])) {
            throw ValidationException::withMessages(['form' => 'Merge recovery setup is incomplete. Keep your work and retry after setup is complete.']);
        }

        return [$source, $target, $actor];
    }

    private function receipt(User $actor, string $uuid): Builder
    {
        return ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
            ->where('channel', ItTicketCommandReceipt::CHANNEL)->where('operation', ItTicketCommandReceipt::MERGE_OPERATION)->where('request_uuid', $uuid);
    }

    private function commandResult(ItTicket $source, ItTicket $target, User $actor, ItTicketCommandReceipt $receipt, bool $replayed = true): array
    {
        $metadata = $receipt->result_metadata ?? [];
        if ((int) $receipt->it_ticket_id !== (int) $source->id || ($metadata['target_id'] ?? null) !== (int) $target->id) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }
        $data = ['viewer_user_id' => (int) $actor->id, 'source_id' => (int) $source->id, 'target_id' => (int) $target->id,
            'operation' => ItTicketCommandReceipt::MERGE_OPERATION, 'request_uuid' => $receipt->request_uuid, 'replayed' => $replayed];
        if (($metadata['state'] ?? null) === 'cancelled' && $receipt->committed_at === null && is_string($metadata['cancelled_at'] ?? null)) {
            return ['status' => 'cancelled', 'data' => [...$data, 'cancelled_at' => $metadata['cancelled_at']]];
        }
        if (($metadata['state'] ?? null) !== 'committed' || $receipt->committed_at === null
            || (int) $source->merged_into_ticket_id !== (int) $target->id
            || (int) $receipt->committed_ticket_version < 1 || ! is_int($metadata['target_version'] ?? null)
            || $metadata['target_version'] < 1) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }

        return ['status' => 'committed', 'data' => [...$data, 'source_version' => (int) $receipt->committed_ticket_version,
            'target_version' => $metadata['target_version'], 'url' => '/it/tickets/'.$target->id.'?merged_from='.$source->id]];
    }

    /** Counts describe recorded state; they are not promises that every record will move. */
    private function previewRecord(ItTicket $ticket): array
    {
        $commentFiles = ItAttachment::query()
            ->where('attachable_type', (new ItTicketComment)->getMorphClass())
            ->whereIn('attachable_id', $ticket->comments()->select('id'))
            ->count();

        return ['id' => (int) $ticket->id, 'reference' => $ticket->reference, 'title' => $ticket->title,
            'lock_version' => (int) $ticket->lock_version, 'status' => $ticket->status,
            'workflow_state' => $ticket->workflow_state, 'work_type' => $ticket->work_type,
            'inventory' => [
                'public_comments' => $ticket->comments()->where('is_internal', false)->count(),
                'internal_notes' => $ticket->comments()->where('is_internal', true)->count(),
                'ticket_files' => $ticket->attachments()->count(), 'comment_files' => $commentFiles,
                'watchers' => $ticket->watchers()->count(), 'links' => $ticket->links()->count(),
                'tasks' => $ticket->tasks()->count(),
                'unfinished_required_tasks' => $ticket->tasks()->where('is_required', true)->where('status', '!=', 'completed')->count(),
                'approval_requests' => $ticket->approvals()->count(),
                'pending_approval_requests' => $ticket->approvals()->where('status', 'pending')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
                'expired_approval_requests' => $ticket->approvals()->where(fn ($query) => $query->where('status', 'expired')
                    ->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '<=', now())))->count(),
            ]];
    }

    public function merge(
        ItTicket $source,
        ItTicket $target,
        User $actor,
        string $reason,
        ?int $sourceVersion = null,
        ?int $targetVersion = null,
    ): ItTicket {
        return DB::transaction(function () use ($source, $target, $actor, $reason, $sourceVersion, $targetVersion): ItTicket {
            $sourceId = (int) $source->getKey();
            $targetId = (int) $target->getKey();
            $ids = array_values(array_unique([$sourceId, $targetId]));
            sort($ids, SORT_NUMERIC);

            // A stable ascending lock order prevents opposite-direction merge
            // requests from deadlocking or creating a merge cycle.
            $locked = ItTicket::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $locked->get($sourceId);
            $target = $locked->get($targetId);
            if (! $source instanceof ItTicket || ! $target instanceof ItTicket) {
                throw (new ModelNotFoundException)->setModel(ItTicket::class, $ids);
            }

            $actor = app(ItTicketVersionService::class)->currentActor($actor);
            $this->guard($source, $target, $actor);
            app(ItTicketVersionService::class)->assertCurrent($source, $sourceVersion);
            app(ItTicketVersionService::class)->assertCurrent($target, $targetVersion);

            $commentIds = $source->comments()->orderBy('id')->pluck('id')->all();
            $fileIds = $source->attachments()->orderBy('id')->pluck('id')->all();
            $watchers = $source->watchers()->orderBy('users.id')->get();
            foreach ($watchers as $watcher) {
                if (! $this->workAccess->canReceiveTicketUpdates($watcher, $target)) {
                    throw new DomainException('Review the original ticket’s watchers before merging. A watcher is no longer eligible for the surviving ticket.');
                }
            }

            // Preserve the canonical settlement guards, paused clocks and state history.
            // Completed tasks, approvals and linked context retain their original IDs/parents.
            $source = app(ItWorkTransitionService::class)->transition($source, new ItTransitionInput(
                actor: $actor, to: ItWorkflowState::Closed, reason: $reason, source: 'legacy_close',
                expectedVersion: (int) $source->lock_version,
            ));

            $source->comments()->update(['ticket_id' => $target->id]);
            $source->attachments()->update(['attachable_id' => $target->id]);

            $watcherIds = $watchers->modelKeys();
            if ($watcherIds !== []) {
                $target->watchers()->syncWithoutDetaching($watcherIds);
                $source->watchers()->detach();
            }

            $source->forceFill([
                'merged_into_ticket_id' => $target->id,
                'merged_at' => now(),
            ])->save();

            // Moved public history changes the conversation evidence, never its first-response clock.
            if (ItTicket::hasConversationEvidence()) {
                $latestPublic = $target->comments()->where('is_internal', false)->orderByDesc('created_at')->orderByDesc('id')->first();
                if ($latestPublic) {
                    $target->last_public_comment_id = $latestPublic->id;
                    $target->last_public_commented_at = $latestPublic->created_at;
                    $target->last_public_speaker_side = $latestPublic->speaker_side;
                }
                if (in_array($target->status, ItTicket::OPEN_STATUSES, true)) {
                    // Observer messages do not hand responsibility back. Unknown legacy
                    // provenance must remain unknown rather than borrowing an older party.
                    $latestHandoff = $target->comments()->where('is_internal', false)
                        ->where(fn ($query) => $query->whereNull('speaker_side')->orWhere('speaker_side', '!=', 'observer'))
                        ->orderByDesc('created_at')->orderByDesc('id')->first();
                    if ($latestHandoff) {
                        $target->next_response_party = match ($latestHandoff->speaker_side) {
                            'requester' => 'it', 'it' => 'requester', default => null,
                        };
                    }
                }
            }
            $target->lock_version = (int) $target->lock_version + 1;
            $target->save();

            ItTicketEvent::record($source, 'merged', $actor->id, [
                'direction' => 'into',
                'target_id' => $target->id,
                'target_reference' => $target->reference,
                'reason' => $reason,
                'moved_comment_ids' => $commentIds,
                'moved_ticket_file_ids' => $fileIds,
                'moved_watcher_ids' => $watcherIds,
                'source_version' => (int) $source->lock_version,
                'target_version' => (int) $target->lock_version,
            ]);
            ItTicketEvent::record($target, 'merged', $actor->id, [
                'direction' => 'from',
                'source_id' => $source->id,
                'source_reference' => $source->reference,
                'reason' => $reason,
            ]);

            AuditLogger::logOrFail('it.ticket.merged', $source, [
                'actor_id' => $actor->id,
                'target_ticket_id' => $target->id,
                'reason_recorded' => true,
                'application_scope' => 'single_application',
            ]);

            return $target->refresh();
        });
    }

    public function sharesConversationAudience(ItTicket $source, ItTicket $target): bool
    {
        $sourceRequester = (int) $source->requester_user_id;
        $targetRequester = (int) $target->requester_user_id;
        $sourceSubject = (int) ($source->requested_for_user_id ?: $sourceRequester);
        $targetSubject = (int) ($target->requested_for_user_id ?: $targetRequester);

        return $sourceRequester > 0
            && $sourceRequester === $targetRequester
            && $sourceSubject > 0
            && $sourceSubject === $targetSubject;
    }

    /** Compare the authorization predicates, including responsibility grants outside assigned sites. */
    public function sharesStaffAccessScope(ItTicket $source, ItTicket $target): bool
    {
        if ((int) $source->site_id !== (int) $target->site_id
            || (bool) $source->is_organisation_wide !== (bool) $target->is_organisation_wide
            || (bool) $source->is_sensitive !== (bool) $target->is_sensitive) {
            return false;
        }
        if ($source->site_id === null) {
            return true;
        }
        $people = static fn (ItTicket $ticket): array => collect([$ticket->assigned_to_user_id, $ticket->owner_user_id])
            ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $people($source) === $people($target)
            && (int) $source->team_id === (int) $target->team_id
            && (int) $source->queue_id === (int) $target->queue_id;
    }

    private function guard(ItTicket $source, ItTicket $target, User $actor): void
    {
        if (! $actor->canDo('it.manage')) {
            throw new AuthorizationException('You are not allowed to merge tickets.');
        }
        if (! $this->workAccess->canWork($actor, $source)
            || ! $this->workAccess->canWork($actor, $target)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class, [$source->id, $target->id]);
        }
        if ((int) $source->id === (int) $target->id) {
            throw new DomainException('A ticket cannot be merged into itself.');
        }
        if ($source->merged_into_ticket_id !== null) {
            throw new DomainException('This ticket has already been merged.');
        }
        if ($target->merged_into_ticket_id !== null) {
            throw new DomainException('The target ticket has already been merged.');
        }
        if ($source->status === 'closed') {
            throw new DomainException('A closed ticket cannot be merged.');
        }
        if ($target->status === 'closed') {
            throw new DomainException('The target ticket is closed. Choose an open ticket.');
        }
        if (! $this->sharesConversationAudience($source, $target)) {
            throw new DomainException('Tickets with different requesters cannot be merged because their conversations are private.');
        }
        if (! $this->sharesStaffAccessScope($source, $target)) {
            throw new DomainException('These tickets have different staff access boundaries. Align their site, sensitivity and responsibility settings, or keep them as related tickets.');
        }
        if ($source->tasks()->whereNotIn('status', ['completed', 'cancelled'])->exists()
            || $source->tasks()->where('is_required', true)->where('status', '!=', 'completed')->exists()) {
            throw new DomainException('Complete the original ticket’s required work and complete or cancel its remaining tasks before merging.');
        }
        if ($source->approvals()->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
            throw new DomainException('Finish or withdraw the original ticket’s pending approval requests before merging.');
        }
        if ($source->requires_approval && $source->approvalState() !== 'approved') {
            throw new DomainException('The original ticket’s required approval must be approved before merging.');
        }
    }
}
