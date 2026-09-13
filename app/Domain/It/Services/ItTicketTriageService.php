<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItBulkActionResult;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Enums\ItTicketDraftPurpose;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\ItStaffDirectory;
use App\Models\Asset;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Notifications\It\TicketAssignedNotification;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The single lifecycle owner for mutable ticket triage properties and closure.
 * Every decision is repeated against the locked canonical ticket so a stale
 * properties rail or bulk selection cannot overwrite newer scope or state.
 */
final class ItTicketTriageService
{
    private const PROPERTY_FIELDS = [
        'status',
        'priority',
        'impact',
        'urgency',
        'priority_decision',
        'routing_override',
        'queue_id',
        'team_id',
        'owner_user_id',
        'work_type',
        'it_service_id',
        'category',
        'subcategory',
        'asset_id',
        'site_id',
        'is_organisation_wide',
        'assigned_to_user_id',
        'requires_approval',
    ];

    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItWorkTransitionService $transitionService,
        private readonly ItEmailDeliveryService $emailDeliveries,
        private readonly ItTicketRoutingService $routing,
        private readonly ItTicketVersionService $versions,
        private readonly ItTicketPriorityService $priority,
    ) {}

    /** @param array<string, mixed> $data */
    public function update(ItTicket $ticket, User $actor, array $data, string $source = 'workspace'): ItTicket
    {
        return $this->mutate($ticket, $actor, $data, $source)['ticket'];
    }

    /** Stable command identity for adapters; the existing mutation remains the property owner. */
    public function updateCommand(ItTicket $ticket, User $actor, array $data, ItTicketCommandChannel $channel): ItTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $data, $channel): ItTicket {
            $currentEvidence = $channel === ItTicketCommandChannel::ServiceApi;
            $actor = $this->versions->currentActor($actor, $currentEvidence);
            $ticket = $this->lockTicket($ticket);
            $this->guardActor($ticket, $actor, $currentEvidence);
            Validator::make($data, ['request_uuid' => ['required', 'uuid'],
                'expected_version' => ['required', 'integer', 'min:1']])->validate();
            $payload = Arr::except($data, ['request_uuid']);
            ksort($payload);
            $hash = hash('sha256', json_encode([(int) $ticket->id, $payload], JSON_THROW_ON_ERROR));
            $receipt = ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
                ->where('channel', $channel->value)->where('operation', ItTicketCommandReceipt::UPDATE_OPERATION)
                ->where('request_uuid', $data['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                if ((int) $receipt->it_ticket_id !== (int) $ticket->id
                    || ! hash_equals((string) $receipt->request_hash, $hash)) {
                    throw new ItTicketCommandConflict;
                }
                if ($receipt->committed_at === null || (int) $receipt->committed_ticket_version < 1) {
                    throw new DomainException('This update has no confirmed outcome. Review its command history before retrying.');
                }

                return $ticket;
            }
            $saved = $this->update($ticket, $actor, Arr::except($data, ['request_uuid']), $channel->value);
            ItTicketCommandReceipt::query()->create([
                'actor_user_id' => $actor->id, 'channel' => $channel->value,
                'operation' => ItTicketCommandReceipt::UPDATE_OPERATION, 'request_uuid' => $data['request_uuid'],
                'request_hash' => $hash, 'it_ticket_id' => $saved->id, 'committed_at' => now(),
                'committed_ticket_version' => $saved->lock_version,
            ]);

            return $saved;
        });
    }

    /**
     * A bulk action treats a stale or no-longer-eligible row as unchanged while
     * leaving every other selected ticket independently serialised.
     *
     * @param  array<string, mixed>  $data
     */
    public function bulkUpdate(ItTicket $ticket, User $actor, array $data, string $source): bool
    {
        return $this->bulkOutcome($ticket, $actor, $data, $source)['status'] === 'updated';
    }

    /** @param array<string, mixed> $data @return array{status: string, message: string} */
    public function bulkOutcome(ItTicket $ticket, User $actor, array $data, string $source): array
    {
        return ItBulkActionResult::capture(fn (): bool => $this->mutate($ticket, $actor, $data, $source)['changed']);
    }

    public function close(
        ItTicket $ticket,
        User $actor,
        string $reason,
        string $source = 'legacy_close',
        bool $staleIsUnchanged = false,
        ?int $expectedVersion = null,
    ): bool {
        try {
            $this->closeWithReason($ticket, $actor, $reason, $source, $expectedVersion);

            return true;
        } catch (Throwable $exception) {
            if ($staleIsUnchanged && ($exception instanceof AuthorizationException
                || $exception instanceof DomainException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof ValidationException
                || $exception instanceof ItTicketVersionConflict)) {
                return false;
            }

            throw $exception;
        }
    }

    /** Return the committed snapshot while retaining the legacy boolean adapter. */
    public function closeWithReason(
        ItTicket $ticket,
        User $actor,
        string $reason,
        string $source = 'legacy_close',
        ?int $expectedVersion = null,
    ): ItTicket {
        return DB::transaction(function () use ($ticket, $actor, $reason, $source, $expectedVersion): ItTicket {
            $locked = $this->lockTicket($ticket);
            $actor = $this->versions->currentActor($actor);
            $this->guardActor($locked, $actor);
            $this->versions->assertCurrent($locked, $expectedVersion);

            if ($locked->status === 'closed') {
                throw new DomainException('This ticket is already closed.');
            }
            if ($locked->isMerged()) {
                throw new DomainException('This ticket was merged and cannot be closed again.');
            }

            $closed = $this->transitionService->transition(
                $locked,
                new ItTransitionInput(
                    actor: $actor,
                    to: ItWorkflowState::Closed,
                    reason: $reason,
                    source: $source,
                ),
            );

            AuditLogger::logOrFail('it.ticket.closed', $closed, [
                'actor_id' => $actor->id,
                'source' => $source,
                'reason_recorded' => true,
                'application_scope' => 'single_application',
            ]);

            return $closed;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ticket: ItTicket, changed: bool}
     */
    private function mutate(ItTicket $ticket, User $actor, array $data, string $source): array
    {
        return DB::transaction(function () use ($ticket, $actor, $data, $source): array {
            $currentEvidence = $source === ItTicketCommandChannel::ServiceApi->value;
            $actor = $this->versions->currentActor($actor, $currentEvidence);
            $locked = $this->lockTicket($ticket);
            $this->guardActor($locked, $actor, $currentEvidence);
            $this->versions->assertCurrent($locked, isset($data['expected_version']) ? (int) $data['expected_version'] : null);

            if ($locked->isMerged()) {
                throw new DomainException('This ticket was merged and can no longer be changed.');
            }
            if ($locked->status === 'closed') {
                throw new DomainException('Reopen this ticket before changing its triage properties.');
            }
            if ($source === 'bulk' && ! in_array($locked->status, ItTicket::OPEN_STATUSES, true)) {
                throw new DomainException('Settled tickets keep their existing triage history.');
            }

            app(ItTicketDraftService::class)->consumeFromInput($actor, $data, ItTicketDraftPurpose::TicketEdit, (int) $locked->id);

            $before = $locked->only(self::PROPERTY_FIELDS);
            if ($currentEvidence && array_key_exists('priority', $data)) {
                // API priority changes use the current assessment, including for
                // legacy tickets; they cannot borrow intake's compatibility path.
                $data = ['impact' => $locked->impact ?? 'individual',
                    'urgency' => $locked->urgency ?? 'normal', ...$data];
            }
            $manualOwnership = Arr::only($data, ['queue_id', 'owner_user_id', 'assigned_to_user_id']);
            $releaseRouting = (bool) ($data['release_routing_override'] ?? false);
            if ($releaseRouting && $manualOwnership !== []) {
                throw ValidationException::withMessages(['routing_reason' => 'Release the override separately from choosing new ownership.']);
            }
            if ($manualOwnership !== [] || $releaseRouting) {
                $this->routing->reason($data['routing_reason'] ?? null);
            }
            $waitingBefore = $locked->only(['waiting_party', 'waiting_reason', 'next_action']);
            [$siteId, $isApplicationWide] = $this->prospectiveScope($locked, $data, $actor);
            $this->releaseIneligibleAssigneeAfterScopeChange(
                $locked,
                $data,
                $siteId,
                $isApplicationWide,
            );
            $this->guardAssignee($data, $siteId, $isApplicationWide);
            $this->guardAsset($locked, $data, $siteId, $isApplicationWide);
            $this->guardService($data);

            $targetStatus = $data['status'] ?? null;
            $waitingContextSupplied = array_key_exists('waiting_party', $data)
                || array_key_exists('waiting_reason', $data)
                || array_key_exists('next_action', $data);
            if (is_string($targetStatus)
                && ($targetStatus !== $locked->status
                    || ($targetStatus === 'waiting' && $waitingContextSupplied))) {
                $locked = $this->transitionService->transition(
                    $locked,
                    new ItTransitionInput(
                        actor: $actor,
                        to: $this->workingState($targetStatus),
                        reason: $data['waiting_reason']
                            ?? ($targetStatus === 'waiting' ? 'Waiting on requester' : 'Ticket properties updated'),
                        waitingParty: $targetStatus === 'waiting'
                            ? ($data['waiting_party'] ?? 'requester')
                            : null,
                        nextAction: $data['next_action'] ?? null,
                        source: $source === 'bulk' ? 'bulk_status' : 'legacy_status',
                    ),
                );
            }

            $properties = Arr::only($data, self::PROPERTY_FIELDS);
            // Decisions are owned by the services, never accepted as JSON input.
            unset($properties['priority_decision'], $properties['routing_override'], $properties['team_id']);
            if (array_intersect(array_keys($data), ['impact', 'urgency', 'priority', 'release_priority_override']) !== []) {
                $properties = [...$properties, ...$this->priority->decide($data, $actor, $locked)];
            }
            unset(
                $properties['status'],
                $properties['waiting_reason'],
                $properties['waiting_party'],
                $properties['next_action'],
                $properties['resolution_code'],
                $properties['resolution_summary'],
                $properties['expected_version'],
            );

            $locked->fill($properties);
            if (array_key_exists('category', $properties)
                && ItTicket::categoryNeedsApproval((string) $properties['category'])) {
                // Category policy may tighten after intake. Never silently
                // clear a catalogue or prior approval requirement here.
                $locked->requires_approval = true;
            }
            if ($locked->priority !== $before['priority']) {
                $locked->stampSlaDueDates();
            }
            if ($locked->isDirty()) {
                $locked->save();
            }

            if ($releaseRouting && $locked->routing_override !== null) {
                $locked->routing_override = null;
                $locked->save();
                ItTicketEvent::record($locked, 'routing_override_released', $actor->id, [
                    'reason' => trim($data['routing_reason']), 'via' => $source,
                ]);
            } elseif ($manualOwnership !== []) {
                $locked->routing_override = $this->routing->manualOverride($locked, $actor, [
                    ...$manualOwnership, 'routing_reason' => $data['routing_reason'] ?? null,
                ]);
                if ($locked->isDirty('routing_override')) {
                    $locked->save();
                    ItTicketEvent::record($locked, 'routing_override_applied', $actor->id, [
                        'fields' => array_keys($manualOwnership), 'reason' => trim($data['routing_reason']), 'via' => $source,
                    ]);
                }
            }

            if ($releaseRouting || $manualOwnership !== []
                || collect(['work_type', 'it_service_id', 'category', 'site_id', 'is_organisation_wide', 'priority'])
                    ->contains(fn (string $field): bool => array_key_exists($field, $properties))) {
                $locked = $this->routing->route($locked, $actor->id);
            }
            $locked->refresh();

            $changedFields = collect(self::PROPERTY_FIELDS)
                ->filter(fn (string $field): bool => $before[$field] !== $locked->getAttribute($field))
                ->values()
                ->all();
            $waitingContextChanged = $waitingBefore !== $locked->only([
                'waiting_party',
                'waiting_reason',
                'next_action',
            ]);

            if ($changedFields === [] && ! $waitingContextChanged) {
                return ['ticket' => $locked, 'changed' => false];
            }

            if ($changedFields !== []) {
                $this->recordPropertyEvents($locked, $actor, $before, $changedFields, $source);
                AuditLogger::logOrFail('it.ticket.triage.updated', $locked, [
                    'actor_id' => $actor->id,
                    'changed_fields' => $changedFields,
                    'source' => $source,
                    'application_scope' => 'single_application',
                ]);
            }

            if (in_array('assigned_to_user_id', $changedFields, true)
                && $locked->assignee
                && (int) $locked->assigned_to_user_id !== (int) $actor->id) {
                $this->emailDeliveries->send($locked->assignee, new TicketAssignedNotification($locked));
            }

            return ['ticket' => $locked, 'changed' => true];
        });
    }

    private function lockTicket(ItTicket $ticket): ItTicket
    {
        return ItTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
    }

    private function guardActor(ItTicket $ticket, User $actor, bool $lockForUpdate = false): void
    {
        if (! $actor->canDo('it.manage')) {
            throw new AuthorizationException('You are not allowed to manage ticket triage.');
        }
        if (! $this->workAccess->canWork($actor, $ticket, $lockForUpdate)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class, [$ticket->id]);
        }
    }

    /** @param array<string, mixed> $data @return array{0: int|null, 1: bool} */
    private function prospectiveScope(ItTicket $ticket, array &$data, User $actor): array
    {
        $siteWasSupplied = array_key_exists('site_id', $data);
        $wideWasSupplied = array_key_exists('is_organisation_wide', $data);
        $siteId = $siteWasSupplied
            ? ($data['site_id'] !== null ? (int) $data['site_id'] : null)
            : ($ticket->site_id !== null ? (int) $ticket->site_id : null);
        $isApplicationWide = $wideWasSupplied
            ? (bool) $data['is_organisation_wide']
            : (bool) $ticket->is_organisation_wide;

        if (($data['is_organisation_wide'] ?? false) && (! $siteWasSupplied || $siteId !== null)) {
            throw ValidationException::withMessages([
                'site_id' => 'Application-wide tickets cannot also have a Site.',
            ]);
        }
        if ($siteWasSupplied && $siteId !== null && ! $wideWasSupplied) {
            $isApplicationWide = false;
            $data['is_organisation_wide'] = false;
        }
        if (($siteWasSupplied || $wideWasSupplied)
            && ! $this->workAccess->canAssignScope($actor, $siteId, $isApplicationWide)) {
            throw new AuthorizationException('You cannot assign this ticket to that operational scope.');
        }

        return [$siteId, $isApplicationWide];
    }

    /** @param array<string, mixed> $data */
    private function guardAssignee(array $data, ?int $siteId, bool $isApplicationWide): void
    {
        if (! array_key_exists('assigned_to_user_id', $data) || $data['assigned_to_user_id'] === null) {
            return;
        }

        $assignee = User::query()->whereNotNull('approved_at')->find((int) $data['assigned_to_user_id']);
        $eligible = $assignee
            && ItStaffDirectory::agents()->contains('id', $assignee->id)
            && ($isApplicationWide
                ? $siteId === null && $assignee->canDo('it.organisationWide')
                : $siteId !== null && in_array($siteId, $this->workAccess->approvedSiteIds($assignee), true));

        if (! $eligible) {
            throw new AuthorizationException('Choose a current IT technician for this ticket scope.');
        }
    }

    /** @param array<string, mixed> $data */
    private function releaseIneligibleAssigneeAfterScopeChange(
        ItTicket $ticket,
        array &$data,
        ?int $siteId,
        bool $isApplicationWide,
    ): void {
        $scopeChanged = array_key_exists('site_id', $data)
            || array_key_exists('is_organisation_wide', $data);
        if (! $scopeChanged
            || array_key_exists('assigned_to_user_id', $data)
            || $ticket->assigned_to_user_id === null) {
            return;
        }

        $assignee = User::query()
            ->whereNotNull('approved_at')
            ->find((int) $ticket->assigned_to_user_id);
        $eligible = $assignee
            && ItStaffDirectory::agents()->contains('id', $assignee->id)
            && ($isApplicationWide
                ? $siteId === null && $assignee->canDo('it.organisationWide')
                : $siteId !== null && in_array($siteId, $this->workAccess->approvedSiteIds($assignee), true));

        if (! $eligible) {
            // Site scope is authoritative. Routing may now choose a suitable
            // default assignee for the new scope; it must never retain an
            // assignee who can no longer work the ticket.
            $data['assigned_to_user_id'] = null;
        }
    }

    /** @param array<string, mixed> $data */
    private function guardAsset(
        ItTicket $ticket,
        array $data,
        ?int $siteId,
        bool $isApplicationWide,
    ): void {
        $assetWasSupplied = array_key_exists('asset_id', $data);
        if ($assetWasSupplied && $data['asset_id'] === null) {
            return;
        }

        $scopeChanged = array_key_exists('site_id', $data)
            || array_key_exists('is_organisation_wide', $data);
        $assetId = $assetWasSupplied
            ? (int) $data['asset_id']
            : ($scopeChanged && $ticket->asset_id !== null ? (int) $ticket->asset_id : null);
        if ($assetId === null) {
            return;
        }

        $asset = Asset::query()->whereKey($assetId)->where('status', 'active')->first();
        $eligible = $asset && ($isApplicationWide
            ? $siteId === null
            : $siteId !== null && (int) $asset->site_id === $siteId);
        if (! $eligible) {
            if (! $assetWasSupplied) {
                throw new DomainException('Remove or change the linked Asset before changing the ticket Site.');
            }

            throw new AuthorizationException('Choose an active Asset within the ticket scope.');
        }
    }

    /** @param array<string, mixed> $data */
    private function guardService(array $data): void
    {
        if (! array_key_exists('it_service_id', $data) || $data['it_service_id'] === null) {
            return;
        }

        if (! ItService::query()
            ->whereKey((int) $data['it_service_id'])
            ->where('is_active', true)
            ->lockForUpdate()
            ->exists()) {
            throw new DomainException('The selected service is not available.');
        }
    }

    private function workingState(string $status): ItWorkflowState
    {
        return match ($status) {
            'open' => ItWorkflowState::Submitted,
            'in_progress' => ItWorkflowState::InProgress,
            'waiting' => ItWorkflowState::Waiting,
            default => throw new DomainException('Use the governed resolve or close journey to settle a ticket.'),
        };
    }

    /** @param array<string, mixed> $before @param list<string> $changedFields */
    private function recordPropertyEvents(
        ItTicket $ticket,
        User $actor,
        array $before,
        array $changedFields,
        string $source,
    ): void {
        if (in_array('priority_decision', $changedFields, true)) {
            ItTicketEvent::record($ticket, 'priority_assessed', $actor->id, [
                'impact' => $ticket->impact, 'urgency' => $ticket->urgency,
                'priority' => $ticket->priority, 'decision' => $ticket->priority_decision,
                'via' => $source,
            ]);
        }
        if (in_array('priority', $changedFields, true)) {
            ItTicketEvent::record($ticket, 'priority_changed', $actor->id, [
                'from' => $before['priority'],
                'to' => $ticket->priority,
                'via' => $source,
            ]);
        }
        if (in_array('assigned_to_user_id', $changedFields, true)) {
            ItTicketEvent::record($ticket, 'assigned', $actor->id, [
                'from' => $before['assigned_to_user_id'],
                'to' => $ticket->assigned_to_user_id,
                'via' => $source,
            ]);
        }

        $propertyFields = array_values(array_diff(
            $changedFields,
            ['status', 'priority', 'assigned_to_user_id'],
        ));
        if ($propertyFields !== []) {
            ItTicketEvent::record($ticket, 'properties_updated', $actor->id, [
                'fields' => $propertyFields,
                'via' => $source,
            ]);
        }
    }
}
