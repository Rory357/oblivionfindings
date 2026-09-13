<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTicketCreationResult;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketCommandUnavailable;
use App\Domain\It\ItStaffDirectory;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\ItProvisioningRequest;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The single write owner for human ticket intake. Scope, links, evidence,
 * routing, activity and audit either commit together or leave no ticket.
 */
final class ItTicketIntakeService
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItProvisioningAccessService $provisioningAccess,
        private readonly ItTicketRoutingService $routing,
        private readonly ItAttachmentStorageService $attachmentStorage,
        private readonly ItTicketDeviceContextService $deviceContext,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly ItEmailDeliveryService $emailDeliveries,
        private readonly ItTicketPriorityService $priority,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $attachments
     */
    public function create(User $actor, array $data, array $attachments = []): ItTicket
    {
        return isset($data['request_uuid'])
            ? $this->createCommand($actor, $data, $attachments)->ticket
            : $this->performCreate($actor, $data, $attachments)->ticket;
    }

    /**
     * The adapter command and canonical ticket commit atomically. A retry
     * returns the same current, authorized record without repeating intake.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $attachments
     */
    public function createCommand(User $actor, array $data, array $attachments = [], ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser, ?ItAttachmentWriteContext $attachmentContext = null): ItTicketCreationResult
    {
        $requestUuid = $data['request_uuid'] ?? null;
        if (! is_string($requestUuid) || ! Str::isUuid($requestUuid)) {
            throw ValidationException::withMessages([
                'request_uuid' => 'A valid request identity is required. Reopen the form to start a new request.',
            ]);
        }

        $attachmentContext?->assertActive();

        return $this->performCreate($actor, $data, $attachments, strtolower($requestUuid), $channel, $attachmentContext);
    }

    public function recoverCommand(User $actor, string $requestUuid, ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser): ItTicketCreationResult
    {
        $actor = User::query()->findOrFail($actor->getKey());
        $this->guardCreateActor($actor);
        $receipt = $this->receiptQuery($actor, strtolower($requestUuid), $channel)->firstOrFail();

        return $this->replayResult($receipt, $actor);
    }

    /**
     * Add user identity to an already-visible employee profile without confusing
     * its HR profile ID with a ticket requester. Final writes repeat the same
     * staff/Site checks inside the locked intake command.
     *
     * @return array{user_id: int, site_ids: list<int>}|null
     */
    public function requesterOption(User $actor, User $staff): ?array
    {
        if (! $actor->canDo('it.manage')) {
            return null;
        }

        $siteIds = array_values(array_filter(
            $this->workAccess->approvedSiteIds($actor),
            fn (int $siteId): bool => $this->staffMemberMatchesScope($staff, $siteId, false),
        ));

        return $siteIds === [] ? null : ['user_id' => (int) $staff->id, 'site_ids' => $siteIds];
    }

    /** @param array<string, mixed> $data @param array<int, UploadedFile> $attachments */
    private function performCreate(
        User $actor,
        array $data,
        array $attachments,
        ?string $requestUuid = null,
        ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser,
        ?ItAttachmentWriteContext $attachmentContext = null,
    ): ItTicketCreationResult {
        $storedPaths = [];
        $attachmentReservations = [];
        $requestHash = $requestUuid !== null ? $this->requestHash($data, $attachments) : null;
        $connection = DB::connection();
        $originalTransactionLevel = $connection->transactionLevel();
        $originalPdo = $connection->getPdo();
        $preparedResult = null;

        try {
            return DB::transaction(function () use ($actor, $data, $attachments, $requestUuid, $requestHash, $channel, $attachmentContext, &$storedPaths, &$attachmentReservations, &$preparedResult): ItTicketCreationResult {
                // Existing actor lock also serializes concurrent commands from
                // this browser identity; the unique index is the final boundary.
                $currentEvidence = $channel === ItTicketCommandChannel::ServiceApi;
                $actor = $currentEvidence
                    ? app(AuthorizationEvidenceLockService::class)->lockForUser($actor, ['*'])
                    : User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                $this->guardCreateActor($actor);
                $receipt = null;
                if ($requestUuid !== null) {
                    $receipt = $this->receiptQuery($actor, $requestUuid, $channel)->lockForUpdate()->first();
                    if ($receipt) {
                        // Access is checked before comparing the fingerprint:
                        // an old request key cannot disclose inaccessible work.
                        $result = $this->replayResult($receipt, $actor);
                        if (! hash_equals($receipt->request_hash, $requestHash)) {
                            throw new ItTicketCommandConflict;
                        }

                        return $preparedResult = $result;
                    }
                    $receipt = ItTicketCommandReceipt::query()->create([
                        'actor_user_id' => $actor->id,
                        'channel' => $channel->value,
                        'operation' => ItTicketCommandReceipt::CREATE_OPERATION,
                        'request_uuid' => $requestUuid,
                        'request_hash' => $requestHash,
                    ]);
                }

                $isAgent = $actor->canDo('it.manage');
                $siteId = array_key_exists('site_id', $data) || $isAgent
                    ? $this->nullableId($data['site_id'] ?? null)
                    : $this->workAccess->defaultSiteId($actor);
                $isOrganisationWide = $isAgent && (bool) ($data['is_organisation_wide'] ?? false);

                $this->guardScope($actor, $siteId, $isOrganisationWide, $isAgent, $data, $currentEvidence);
                $priority = $this->priority->decide($data, $actor);

                $requesterId = $isAgent && $this->nullableId($data['requester_user_id'] ?? null) !== null
                    ? (int) $data['requester_user_id']
                    : (int) $actor->id;
                $assigneeId = $isAgent ? $this->nullableId($data['assigned_to_user_id'] ?? null) : null;
                $watcherIds = $isAgent
                    ? collect($data['watchers'] ?? [])->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique()->values()->all()
                    : [];

                $users = $this->lockUsers([$requesterId, $assigneeId, ...$watcherIds]);
                if ($currentEvidence && $users->has($actor->id)) {
                    $users->put($actor->id, $actor);
                }
                $requester = $users->get($requesterId);
                if (! $requester || ! $this->staffMemberMatchesScope($requester, $siteId, $isOrganisationWide, $currentEvidence)) {
                    throw new AuthorizationException('The requester is not available in this ticket scope.');
                }
                if ($assigneeId !== null) {
                    $assignee = $users->get($assigneeId);
                    if (! $assignee || ! $this->agentMatchesScope($assignee, $siteId, $isOrganisationWide)) {
                        throw new AuthorizationException('The assignee is not available in this ticket scope.');
                    }
                }

                $serviceId = $isAgent ? $this->nullableId($data['it_service_id'] ?? null) : null;
                if ($serviceId !== null && ! ItService::query()
                    ->whereKey($serviceId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->exists()) {
                    throw new AuthorizationException('The selected service is not available.');
                }

                $assetId = $isAgent ? $this->nullableId($data['asset_id'] ?? null) : null;
                if ($assetId !== null && ! $this->assetIsAvailable($assetId, $siteId, $isOrganisationWide)) {
                    throw new AuthorizationException('The selected Asset is not available in this ticket scope.');
                }

                $deviceId = $isAgent ? $this->nullableId($data['device_id'] ?? null) : null;
                $device = $deviceId !== null
                    ? $this->visibleDevice($actor, $deviceId)
                    : null;
                if ($device !== null) {
                    $this->deviceContext->assertAvailableInScope(
                        $device,
                        $siteId,
                        $isOrganisationWide,
                    );
                }

                $provisioningRequestId = $isAgent
                    ? $this->nullableId($data['provisioning_request_id'] ?? null)
                    : null;
                if ($provisioningRequestId !== null) {
                    $provisioning = ItProvisioningRequest::query()
                        ->whereKey($provisioningRequestId)
                        ->lockForUpdate()
                        ->first();
                    if (! $provisioning
                        || ! $this->provisioningAccess->canView($actor, $provisioning)
                        || $this->provisioningAccess->siteIdFor($provisioning) !== $siteId) {
                        throw new AuthorizationException('The provisioning request is not available in this ticket scope.');
                    }
                }

                $ticket = ItTicket::createWithReference([
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'requester_user_id' => $requesterId,
                    'requested_for_user_id' => $requesterId,
                    'assigned_to_user_id' => $assigneeId,
                    'asset_id' => $assetId,
                    'site_id' => $siteId,
                    'is_organisation_wide' => $isOrganisationWide,
                    'it_service_id' => $serviceId,
                    'provisioning_request_id' => $provisioningRequestId,
                    'category' => $data['category'],
                    'requires_approval' => ItTicket::categoryNeedsApproval((string) $data['category']),
                    'subcategory' => $isAgent ? ($data['subcategory'] ?? null) : null,
                    ...$priority,
                    'work_type' => $isAgent ? ($data['work_type'] ?? 'incident') : 'incident',
                    'workflow_state' => 'submitted',
                    'source' => match ($channel) {
                        ItTicketCommandChannel::Email => 'email',
                        ItTicketCommandChannel::ServiceApi => 'system',
                        default => $isAgent ? 'agent' : 'portal',
                    },
                    'status' => $assigneeId !== null ? 'in_progress' : 'open',
                    ...(ItTicket::hasConversationEvidence() ? ['next_response_party' => 'it'] : []),
                ]);

                $ticket->stampSlaDueDates();
                $ticket->save();
                app(ItTicketDraftService::class)->consumeFromInput($actor, $data,
                    $isAgent ? ItTicketDraftPurpose::TechnicianIntake : ItTicketDraftPurpose::RequesterIntake,
                    requestUuid: $requestUuid, attachmentTarget: $ticket);
                if ($ticket->attachments()->count() + count($attachments) > 5) {
                    throw ValidationException::withMessages(['attachments' => 'Attach no more than five files to this submission.']);
                }
                $attachmentReservations = $this->attachmentStorage->reserveDirect($ticket, $attachments, $actor);
                $attachmentContext?->remember($attachmentReservations);
                $this->attachmentStorage->storeReservedDirect($ticket, $attachments, $actor, $attachmentReservations, $storedPaths);
                if ($device !== null) {
                    $this->deviceContext->linkAtIntake($ticket, $device, $actor);
                }

                ItTicketEvent::record($ticket, 'created', $actor->id, array_filter([
                    'source' => $ticket->source,
                    ...($channel === ItTicketCommandChannel::ServiceApi ? ['source_channel' => $channel->value] : []),
                    'command_receipt_id' => $receipt?->id,
                    'assigned_to_user_id' => $assigneeId,
                    'device_id' => $deviceId,
                    'provisioning_request_id' => $provisioningRequestId,
                    'on_behalf_of' => $requesterId !== (int) $actor->id ? $requesterId : null,
                ]));
                ItTicketEvent::record($ticket, 'priority_assessed', $actor->id, [
                    'impact' => $ticket->impact, 'urgency' => $ticket->urgency,
                    'priority' => $ticket->priority, 'decision' => $ticket->priority_decision,
                    'via' => 'intake',
                ]);
                if ($assigneeId !== null) {
                    $ticket->routing_override = $this->routing->manualOverride($ticket, $actor, [
                        'assigned_to_user_id' => $assigneeId,
                        'routing_reason' => $data['routing_reason'] ?? null,
                    ]);
                    $ticket->save();
                    ItTicketEvent::record($ticket, 'routing_override_applied', $actor->id, [
                        'fields' => ['assigned_to_user_id'], 'reason' => trim($data['routing_reason']), 'via' => 'intake',
                    ]);
                }
                $ticket = $this->routing->route($ticket, $actor->id);

                // The final canonical record, including responsibility scope,
                // determines eligibility. Membership must never grant access.
                foreach ($watcherIds as $watcherId) {
                    $watcher = $users->get($watcherId);
                    if (! $watcher || ! $this->workAccess->canReceiveTicketUpdates($watcher, $ticket)) {
                        throw new AuthorizationException('A watcher cannot currently receive updates for this ticket.');
                    }
                }
                if ($watcherIds !== []) {
                    $ticket->watchers()->syncWithoutDetaching($watcherIds);
                }

                AuditLogger::logOrFail('it.ticket.created', $ticket, [
                    'actor_id' => $actor->id,
                    'command_receipt_id' => $receipt?->id,
                    'requester_user_id' => $requesterId,
                    'site_id' => $siteId,
                    'is_organisation_wide' => $isOrganisationWide,
                    'source' => $ticket->source,
                    'work_type' => $ticket->work_type,
                    'category' => $ticket->category,
                    'priority' => $ticket->priority,
                    'service_id' => $serviceId,
                    'asset_id' => $assetId,
                    'device_id' => $deviceId,
                    'provisioning_request_id' => $provisioningRequestId,
                    'assignee_user_id' => $ticket->assigned_to_user_id,
                    'queue_id' => $ticket->queue_id,
                    'team_id' => $ticket->team_id,
                    'watcher_count' => count($watcherIds),
                    'attachment_count' => count($storedPaths),
                    'application_scope' => 'single_application',
                ]);

                // Notification intent commits with the ticket and receipt;
                // channel/provider execution happens after the response.
                $this->emailDeliveries->prepare($requester, new TicketCreatedNotification($ticket, 'receipt'));
                if ($ticket->priority === 'urgent') {
                    $agents = ItStaffDirectory::agentsForTicket($ticket)
                        ->reject(fn (User $agent): bool => (int) $agent->id === (int) $actor->id);
                    $this->emailDeliveries->prepare($agents, new TicketCreatedNotification($ticket, 'urgent_alert'));
                }

                $receipt?->forceFill([
                    'it_ticket_id' => $ticket->id,
                    'committed_at' => now(),
                ])->save();

                return $preparedResult = new ItTicketCreationResult(
                    $ticket->refresh()->load(['requester', 'assignee', 'watchers']),
                    $requestUuid,
                );
            });
        } catch (Throwable $exception) {
            if ($preparedResult !== null && $originalTransactionLevel === 0) {
                // PDO commit and after-commit callbacks can throw after the
                // database has saved the command. Never delete its files on
                // an unknown outcome or trust an uncommitted/stale connection.
                try {
                    $confirmed = $this->reconcileCompletedWrite(
                        (int) $actor->id,
                        $requestHash,
                        $preparedResult,
                        $connection->getConfig(),
                        $connection->getName(),
                        $channel,
                    );
                    if ($confirmed !== null) {
                        return $confirmed;
                    }
                } catch (AuthorizationException|ItTicketCommandUnavailable $denied) {
                    throw $denied;
                } catch (Throwable) {
                    // A failed reconciliation is not evidence of rollback.
                    // Preserve both files and the original unknown outcome.
                }
            } elseif ($preparedResult === null && $connection->transactionLevel() === $originalTransactionLevel
                && $connection->getPdo() === $originalPdo
                && $originalPdo->inTransaction() === ($originalTransactionLevel > 0)) {
                // The callback failed before commit began, and the original
                // connection returned to its prior transaction/savepoint.
                $this->attachmentStorage->requestRollbackCleanup($attachmentReservations);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $configuration */
    private function reconcileCompletedWrite(
        int $actorId,
        ?string $requestHash,
        ItTicketCreationResult $prepared,
        array $configuration,
        string $originalConnection,
        ItTicketCommandChannel $channel,
    ): ?ItTicketCreationResult {
        $recoveryConnection = 'it_command_recovery_'.Str::uuid();
        $configuration['name'] = $recoveryConnection;
        if (isset($configuration['write'])) {
            $configuration['write']['name'] = $recoveryConnection;
        }
        try {
            DB::connectUsing($recoveryConnection, $configuration)->useWriteConnectionWhenReading();

            return DB::usingConnection($recoveryConnection, function () use ($actorId, $requestHash, $prepared, $originalConnection, $channel): ?ItTicketCreationResult {
                $actor = User::query()->find($actorId);
                if (! $actor) {
                    throw new AuthorizationException('This request is no longer available to you.');
                }
                $this->guardCreateActor($actor);

                if ($prepared->requestUuid !== null) {
                    $receipt = $this->receiptQuery($actor, $prepared->requestUuid, $channel)->first();
                    if (! $receipt?->committed_at
                        || (int) $receipt->it_ticket_id !== (int) $prepared->ticket->id
                        || ! hash_equals($receipt->request_hash, (string) $requestHash)) {
                        return null;
                    }
                    $ticket = $this->replayResult($receipt, $actor)->ticket;
                } else {
                    // Legacy adapters have no receipt; only the exact newly
                    // allocated record/reference can prove their own result.
                    $ticket = ItTicket::query()->whereKey($prepared->ticket->id)
                        ->where('reference', $prepared->ticket->reference)->first();
                    if (! $ticket) {
                        return null;
                    }
                    if (! $this->workAccess->canView($actor, $ticket)) {
                        throw new AuthorizationException('This request is no longer available to you.');
                    }
                }

                return new ItTicketCreationResult(
                    $ticket->setConnection($originalConnection),
                    $prepared->requestUuid,
                    $prepared->replayed,
                );
            });
        } finally {
            DB::purge($recoveryConnection);
        }
    }

    private function guardCreateActor(User $actor): void
    {
        if ($actor->approved_at === null
            || (! $actor->canDo('it.request') && ! $actor->canDo('it.manage'))) {
            throw new AuthorizationException('You are not allowed to create IT tickets.');
        }
    }

    /** @return Builder<ItTicketCommandReceipt> */
    private function receiptQuery(User $actor, string $requestUuid, ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser): Builder
    {
        return ItTicketCommandReceipt::query()
            ->where('actor_user_id', $actor->id)
            ->where('channel', $channel->value)
            ->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)
            ->where('request_uuid', $requestUuid);
    }

    private function replayResult(ItTicketCommandReceipt $receipt, User $actor): ItTicketCreationResult
    {
        $ticket = $receipt->ticket;
        if (! $receipt->committed_at) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }
        if (! $ticket || ! $this->workAccess->canView($actor, $ticket)) {
            throw new ItTicketCommandUnavailable;
        }

        return new ItTicketCreationResult($ticket, $receipt->request_uuid, true);
    }

    /** @param array<string, mixed> $data @param array<int, UploadedFile> $attachments */
    private function requestHash(array $data, array $attachments): string
    {
        // Canonical values, not multipart boundaries/order or raw HTTP bytes.
        // Persist only the digest, never private descriptions or file content.
        $payload = [];
        foreach (['title', 'description', 'category', 'priority', 'subcategory'] as $field) {
            $payload[$field] = filled($data[$field] ?? null) ? (string) $data[$field] : null;
        }
        $payload['work_type'] = $data['work_type'] ?? 'incident';
        foreach (['it_service_id', 'site_id', 'requester_user_id', 'assigned_to_user_id', 'asset_id', 'device_id', 'provisioning_request_id'] as $field) {
            $payload[$field] = $this->nullableId($data[$field] ?? null);
        }
        $payload['is_organisation_wide'] = (bool) ($data['is_organisation_wide'] ?? false);
        $payload['watchers'] = collect($data['watchers'] ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()->sort()->values()->all();
        $payload['attachments'] = array_map(fn (UploadedFile $file): array => [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getPathname()),
        ], $attachments);
        // Absent additions must not invalidate committed W02 command receipts.
        foreach (['impact', 'urgency', 'priority_reason', 'routing_reason', 'draft_uuid', 'draft_revision', 'draft_actor_user_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] ?? null;
            }
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    private function guardScope(
        User $actor,
        ?int $siteId,
        bool $isOrganisationWide,
        bool $isAgent,
        array $data,
        bool $currentEvidence = false,
    ): void {
        if (! $this->workAccess->canAssignScope($actor, $siteId, $isOrganisationWide, $currentEvidence)) {
            if ($isAgent && (array_key_exists('site_id', $data) || $isOrganisationWide)) {
                throw new AuthorizationException('The selected ticket scope is not available.');
            }

            throw ValidationException::withMessages([
                'site_id' => 'Choose an active approved Site for this ticket.',
            ]);
        }

        if ($siteId !== null && ! Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->exists()) {
            throw new AuthorizationException('The selected Site is no longer operational.');
        }
    }

    /** @param array<int, int|null> $ids */
    private function lockUsers(array $ids): Collection
    {
        $ids = collect($ids)->filter(fn (mixed $id): bool => is_int($id) && $id > 0)->unique()->sort()->values();

        return User::query()
            ->whereKey($ids->all())
            ->whereNotNull('approved_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function staffMemberMatchesScope(User $staff, ?int $siteId, bool $isOrganisationWide, bool $currentEvidence = false): bool
    {
        if ($staff->approved_at === null
            || $staff->hasRole('client')
            || $staff->hasRole('next_of_kin')
            || in_array($staff->role, ['client', 'next_of_kin'], true)) {
            return false;
        }

        return $isOrganisationWide
            ? $siteId === null
            : $siteId !== null && in_array($siteId, $this->workAccess->approvedSiteIds($staff, $currentEvidence), true);
    }

    private function agentMatchesScope(User $agent, ?int $siteId, bool $isOrganisationWide): bool
    {
        if (! ItStaffDirectory::agents()->contains('id', $agent->id)) {
            return false;
        }

        return $isOrganisationWide
            ? $siteId === null && $agent->canDo('it.organisationWide')
            : $this->staffMemberMatchesScope($agent, $siteId, false);
    }

    private function assetIsAvailable(int $assetId, ?int $siteId, bool $isOrganisationWide): bool
    {
        $asset = Asset::query()
            ->whereKey($assetId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        return $asset !== null && ($isOrganisationWide
            ? $siteId === null
            : $siteId !== null && (int) $asset->site_id === $siteId);
    }

    private function visibleDevice(User $actor, int $deviceId): Device
    {
        if (! $actor->canDo('securityDevices.devices.view')) {
            throw new AuthorizationException('The selected Device is not available.');
        }

        $device = $this->deviceAccess->visibleDevices($actor)
            ->whereKey($deviceId)
            ->lockForUpdate()
            ->first();

        if (! $device) {
            throw new AuthorizationException('The selected Device is not available.');
        }

        return $device;
    }

    private function nullableId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
