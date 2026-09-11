<?php

namespace App\Domain\It\Services;

use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\Monitoring\Services\MonitoringWorkRouting;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\ItTicketLink;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\Fleet\FleetSignalService;
use App\Services\UserSiteAccessService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ItTicketLinkService
{
    public const MONITORING_PRINCIPAL = 'oblivion_monitoring_ticketing';

    public const MONITORING_OPERATION = 'work:create-monitoring';

    public const MONITORING_ORIGIN_EVENTS = ['created_from_monitoring', 'monitoring_handoff_bound'];

    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly UserSiteAccessService $siteAccess,
        private readonly ControlRoomAlertProvenanceService $alertProvenance,
    ) {}

    /** Staff-only discovery. Neither counts nor labels reveal inaccessible counterpart records. */
    public function relatedWork(ItTicket $source, User $actor, string $search = '', int $linksPage = 1, int $candidatesPage = 1): array
    {
        $actor = User::query()->whereKey($actor->getKey())->whereNotNull('approved_at')->first();
        $source = ItTicket::query()->find($source->getKey());
        if (! $actor || ! $source || ! $this->workAccess->canWork($actor, $source)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class);
        }
        $links = $this->visibleRelatedLinks($source, $actor)
            ->with('linkable')->orderByDesc('id')->simplePaginate(20, ['*'], 'links_page', max(1, $linksPage));
        $candidates = $this->workAccess->applyWorkScope(ItTicket::query(), $actor)
            ->whereKeyNot($source->id)->whereNull('merged_into_ticket_id')
            ->when(trim($search) !== '', fn ($query) => $query->where(fn ($matches) => $matches
                ->where('reference', 'like', '%'.trim($search).'%')->orWhere('title', 'like', '%'.trim($search).'%')))
            ->latest('id')->simplePaginate(20, ['*'], 'candidates_page', max(1, $candidatesPage));
        $record = fn (ItTicket $ticket): array => ['id' => (int) $ticket->id, 'reference' => $ticket->reference,
            'title' => $ticket->title, 'status' => $ticket->status, 'work_type' => $ticket->work_type,
            'lock_version' => (int) $ticket->lock_version,
            'href' => route($ticket->isMerged() ? 'it.tickets.original' : 'it.tickets.show', $ticket, false)];
        $canChange = ! $source->isMerged() && in_array($source->status, ItTicket::OPEN_STATUSES, true);

        return ['viewer_user_id' => (int) $actor->id, 'source' => $record($source), 'can_change' => $canChange,
            'links' => ['data' => $links->getCollection()->map(fn (ItTicketLink $link): array => [
                'id' => (int) $link->id, 'relationship' => $link->relationship, 'ticket' => $record($link->linkable),
                'can_remove' => $canChange && ! $link->linkable->isMerged() && $this->workAccess->canWork($actor, $link->linkable),
            ])->all(), 'page' => $links->currentPage(), 'has_more' => $links->hasMorePages()],
            'candidates' => ['data' => $candidates->getCollection()->map($record)->all(),
                'page' => $candidates->currentPage(), 'has_more' => $candidates->hasMorePages()]];
    }

    public function relatedCount(ItTicket $source, User $actor): int
    {
        $actor = User::query()->whereKey($actor->getKey())->whereNotNull('approved_at')->first();

        return $actor && $this->workAccess->canWork($actor, $source)
            ? $this->visibleRelatedLinks($source, $actor)->count() : 0;
    }

    private function visibleRelatedLinks(ItTicket $source, User $actor): Builder
    {
        return ItTicketLink::query()->where('ticket_id', $source->id)->whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)
            ->whereHasMorph('linkable', [ItTicket::class], fn ($query) => $this->workAccess->applyViewScope($query, $actor));
    }

    /** Both directions are canonical link rows, never copied tickets or specialized memberships. */
    public function changeRelated(
        ItTicket $source,
        ItTicket $target,
        User $actor,
        array $input,
        ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser,
    ): array {
        return DB::transaction(function () use ($source, $target, $actor, $input, $channel): array {
            [$source, $target, $actor] = $this->relatedCommandContext($source, $target, $actor, $channel);
            $this->validateRelatedIdentity($actor, $input);
            Validator::make($input, ['source_version' => ['required', 'integer', 'min:1'],
                'target_version' => ['required', 'integer', 'min:1']])->validate();
            $hash = hash('sha256', json_encode([(int) $source->id, (int) $target->id,
                $input['action'], $input['relationship'], (int) $input['source_version'],
                (int) $input['target_version']], JSON_THROW_ON_ERROR));
            $receipt = $this->relatedReceipt($actor, $input['request_uuid'], $channel)->lockForUpdate()->first();
            if ($receipt) {
                $result = $this->relatedResult($source, $target, $actor, $input, $receipt);
                if ($result['status'] !== 'cancelled' && ! hash_equals($receipt->request_hash, $hash)) {
                    throw new ItTicketCommandConflict;
                }

                return $result;
            }
            app(ItTicketVersionService::class)->assertCurrent($source, (int) $input['source_version']);
            app(ItTicketVersionService::class)->assertCurrent($target, (int) $input['target_version']);
            if ($source->isMerged() || $target->isMerged() || ! in_array($source->status, ItTicket::OPEN_STATUSES, true)) {
                throw ValidationException::withMessages(['form' => 'Use an open original ticket and an unmerged related record. Reopen the source ticket if necessary.']);
            }

            $changed = false;
            foreach ([[$source, $target], [$target, $source]] as [$parent, $other]) {
                $rows = $parent->links()->where('linkable_type', $other->getMorphClass())->where('linkable_id', $other->id);
                if ($input['action'] === 'add') {
                    if ((clone $rows)->whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)
                        ->where('relationship', '!=', $input['relationship'])->exists()) {
                        throw ValidationException::withMessages(['relationship' => 'These tickets already have another relationship. Remove that link before changing its type.']);
                    }
                    $link = $this->persist($parent, $other, $input['relationship'], [
                        'source' => $channel === ItTicketCommandChannel::Browser
                            ? 'ticket_workspace'
                            : $channel->value,
                    ], $actor->id);
                    $changed = $link->wasRecentlyCreated || $changed;
                } else {
                    $changed = $rows->where('relationship', $input['relationship'])->delete() > 0 || $changed;
                }
            }
            if ($changed) {
                foreach ([[$source, $target], [$target, $source]] as [$parent, $other]) {
                    // A same-second touch can be clean; every changed relationship
                    // must invalidate both open editors even under a frozen clock.
                    $parent->forceFill(['lock_version' => (int) $parent->lock_version + 1])->save();
                    ItTicketEvent::record($parent, $input['action'] === 'add' ? 'related_work_linked' : 'related_work_unlinked', $actor->id,
                        [
                            'relationship' => $input['relationship'],
                            'target_id' => (int) $other->id,
                            'target_reference' => $other->reference,
                            ...($channel === ItTicketCommandChannel::Browser ? [] : ['source_channel' => $channel->value]),
                        ]);
                    AuditLogger::logOrFail('it.ticket.relationship.'.$input['action'], $parent,
                        ['actor_id' => (int) $actor->id, 'target_ticket_id' => (int) $other->id,
                            'relationship' => $input['relationship'], 'request_uuid' => $input['request_uuid'],
                            ...($channel === ItTicketCommandChannel::Browser ? [] : ['source' => $channel->value])]);
                }
            }
            $receipt = ItTicketCommandReceipt::query()->create([
                'actor_user_id' => $actor->id, 'channel' => $channel->value,
                'operation' => ItTicketCommandReceipt::RELATIONSHIP_OPERATION, 'request_uuid' => $input['request_uuid'],
                'request_hash' => $hash, 'it_ticket_id' => $source->id, 'committed_at' => now(),
                'committed_ticket_version' => $source->lock_version, 'result_metadata' => [
                    'state' => 'committed', 'target_id' => (int) $target->id, 'action' => $input['action'],
                    'relationship' => $input['relationship'], 'target_version' => (int) $target->lock_version, 'changed' => $changed,
                ],
            ]);

            return $this->relatedResult($source, $target, $actor, $input, $receipt, false);
        });
    }

    /** Cancellation records a tombstone so a late request cannot apply a cancelled intent. */
    public function recoverRelated(ItTicket $source, ItTicket $target, User $actor, array $identity, bool $cancel = false): array
    {
        return DB::transaction(function () use ($source, $target, $actor, $identity, $cancel): array {
            [$source, $target, $actor] = $this->relatedCommandContext($source, $target, $actor);
            $this->validateRelatedIdentity($actor, $identity);
            $receipt = $this->relatedReceipt($actor, $identity['request_uuid'])->lockForUpdate()->first();
            $created = false;
            if (! $receipt && $cancel) {
                $receipt = ItTicketCommandReceipt::query()->create([
                    'actor_user_id' => $actor->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
                    'operation' => ItTicketCommandReceipt::RELATIONSHIP_OPERATION, 'request_uuid' => $identity['request_uuid'],
                    'request_hash' => hash('sha256', 'cancelled'), 'it_ticket_id' => $source->id,
                    'result_metadata' => ['state' => 'cancelled', 'target_id' => (int) $target->id,
                        'action' => $identity['action'], 'relationship' => $identity['relationship'], 'cancelled_at' => now()->toIso8601String()],
                ]);
                AuditLogger::logOrFail('it.ticket.relationship.command.cancelled', $source,
                    ['actor_id' => $actor->id, 'target_ticket_id' => $target->id, 'request_uuid' => $identity['request_uuid']]);
                $created = true;
            }
            if (! $receipt) {
                return ['status' => 'unconfirmed', 'data' => $this->relatedResultIdentity($source, $target, $actor, $identity)];
            }

            return $this->relatedResult($source, $target, $actor, $identity, $receipt, ! $created);
        });
    }

    private function validateRelatedIdentity(User $actor, array $input): void
    {
        Validator::make($input, ['actor_user_id' => ['required', 'integer', 'in:'.$actor->id],
            'request_uuid' => ['required', 'uuid'], 'action' => ['required', Rule::in(['add', 'remove'])],
            'relationship' => ['required', Rule::in(ItTicketLink::BROWSER_RELATIONSHIPS)]])->validate();
    }

    private function relatedCommandContext(
        ItTicket $source,
        ItTicket $target,
        User $actor,
        ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser,
    ): array {
        $currentEvidence = $channel === ItTicketCommandChannel::ServiceApi;
        $actor = app(ItTicketVersionService::class)->currentActor($actor, $currentEvidence);
        [$source, $target] = $this->lockCanonicalRecords($source, $target);
        if (! $this->workAccess->canWork($actor, $source, $currentEvidence)
            || ! $this->workAccess->canWork($actor, $target, $currentEvidence)) {
            throw (new ModelNotFoundException)->setModel(ItTicket::class);
        }
        if ($source->is($target)) {
            throw ValidationException::withMessages(['target_ticket_id' => 'Choose a different ticket.']);
        }
        if (! Schema::hasColumns('it_ticket_command_receipts', ['result_metadata', 'committed_ticket_version'])) {
            throw ValidationException::withMessages(['form' => 'Relationship recovery setup is incomplete. Retry after setup is complete.']);
        }

        return [$source, $target, $actor];
    }

    private function relatedReceipt(
        User $actor,
        string $uuid,
        ItTicketCommandChannel $channel = ItTicketCommandChannel::Browser,
    ): Builder {
        return ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)
            ->where('channel', $channel->value)->where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)
            ->where('request_uuid', $uuid);
    }

    private function relatedResultIdentity(ItTicket $source, ItTicket $target, User $actor, array $identity): array
    {
        return ['viewer_user_id' => (int) $actor->id, 'source_id' => (int) $source->id, 'target_id' => (int) $target->id,
            'request_uuid' => $identity['request_uuid'], 'operation' => ItTicketCommandReceipt::RELATIONSHIP_OPERATION,
            'action' => $identity['action'], 'relationship' => $identity['relationship']];
    }

    private function relatedResult(ItTicket $source, ItTicket $target, User $actor, array $identity, ItTicketCommandReceipt $receipt, bool $replayed = true): array
    {
        $meta = $receipt->result_metadata ?? [];
        if ((int) $receipt->it_ticket_id !== (int) $source->id || ($meta['target_id'] ?? null) !== (int) $target->id) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }
        if (($meta['relationship'] ?? null) !== $identity['relationship'] || ($meta['action'] ?? null) !== $identity['action']) {
            throw new ItTicketCommandConflict;
        }
        $data = [...$this->relatedResultIdentity($source, $target, $actor, $identity), 'replayed' => $replayed];
        if (($meta['state'] ?? null) === 'cancelled' && $receipt->committed_at === null && is_string($meta['cancelled_at'] ?? null)) {
            return ['status' => 'cancelled', 'data' => [...$data, 'cancelled_at' => $meta['cancelled_at']]];
        }
        if (($meta['state'] ?? null) !== 'committed' || $receipt->committed_at === null || ! is_bool($meta['changed'] ?? null)
            || (int) $receipt->committed_ticket_version < 1 || ! is_int($meta['target_version'] ?? null) || $meta['target_version'] < 1) {
            throw (new ModelNotFoundException)->setModel(ItTicketCommandReceipt::class);
        }

        // Historical outcome: a later authorized remove must never make an old add replay apply again.
        return ['status' => 'committed', 'data' => [...$data, 'changed' => $meta['changed'],
            'source_version' => (int) $receipt->committed_ticket_version, 'target_version' => $meta['target_version']]];
    }

    /** @param array<string, mixed> $context */
    public function link(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        array $context = [],
        ?int $actorUserId = null,
    ): ItTicketLink {
        return DB::transaction(function () use ($ticket, $target, $relationship, $context, $actorUserId): ItTicketLink {
            $actor = $this->responsibleActor($actorUserId);
            [$canonicalTicket, $canonicalTarget] = $this->lockCanonicalRecords($ticket, $target);
            $this->assertHumanLinkAccess($actor, $canonicalTicket, $canonicalTarget, $relationship);

            return $this->persist(
                $canonicalTicket,
                $canonicalTarget,
                $relationship,
                $context,
                $actor->id,
            );
        });
    }

    public function unlink(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        ?int $actorUserId = null,
    ): bool {
        return DB::transaction(function () use ($ticket, $target, $relationship, $actorUserId): bool {
            $actor = $this->responsibleActor($actorUserId);
            [$canonicalTicket, $canonicalTarget] = $this->lockCanonicalRecords($ticket, $target);
            $this->assertHumanLinkAccess($actor, $canonicalTicket, $canonicalTarget, $relationship);

            return $canonicalTicket->links()
                ->where('relationship', $relationship)
                ->where('linkable_type', $canonicalTarget->getMorphClass())
                ->where('linkable_id', $canonicalTarget->getKey())
                ->delete() > 0;
        });
    }

    /**
     * Dedicated least-privileged operation used only by the native monitoring
     * listener. It cannot link arbitrary records or organisation-wide work.
     *
     * @param  array<string, mixed>  $context
     */
    public function linkMonitoringEvidence(
        ItTicket $ticket,
        Device $device,
        ?ControlRoomAlert $alert,
        array $context = [],
        ?DeviceEvent $sourceEvent = null,
    ): void {
        DB::transaction(function () use ($ticket, $device, $alert, $context, $sourceEvent): void {
            $ticket = ItTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();
            $device = Device::query()->whereKey($device->getKey())->lockForUpdate()->first();
            $requiresAlert = $alert !== null;
            $alert = $alert ? ControlRoomAlert::query()->whereKey($alert->getKey())->lockForUpdate()->first() : null;
            if (! $ticket || ! $device || ($requiresAlert && ! $alert)
                || ! $this->isMonitoringWork($ticket, $alert)
                || $ticket->work_type !== 'incident'
                || $ticket->site_id === null
                || $ticket->is_organisation_wide
                || $device->domain !== 'it_infrastructure') {
                throw new DomainException('Monitoring ticket context is not canonical.');
            }

            $siteId = $alert ? $this->canonicalMonitoringSiteId($device, $alert, true) : $this->canonicalDeviceSiteId($device, true);
            if ($siteId === null || $siteId !== (int) $ticket->site_id) {
                throw new DomainException('Monitoring Device, Site, and alert evidence do not agree.');
            }
            if ($alert === null) {
                $sourceEvent = $sourceEvent ? DeviceEvent::query()->whereKey($sourceEvent->id)->lockForUpdate()->first() : null;
                if ($sourceEvent === null || (int) $sourceEvent->device_id !== (int) $device->id
                    || $sourceEvent->event_type !== 'offline'
                    || ! MonitoringWorkRouting::hasDirectSourceEvidence($sourceEvent, $siteId)) {
                    throw new DomainException('Direct monitoring work requires canonical source observation evidence.');
                }
            }

            $principalContext = [
                ...$context,
                'system_principal' => self::MONITORING_PRINCIPAL,
                'operation' => self::MONITORING_OPERATION,
                'site_id' => $siteId,
            ];
            $this->persistMonitoring($ticket, $device, 'affected_device', $principalContext);
            if ($alert !== null) {
                $this->persistMonitoring($ticket, $alert, 'source_alert', $principalContext);
            }
        });
    }

    public function canonicalDeviceSiteId(Device $device, bool $lockForUpdate = false): ?int
    {
        $assignments = $device->assignments()->active();
        if ($lockForUpdate) {
            $assignments->lockForUpdate();
        }
        $assignments = $assignments->get();
        if ($assignments->count() !== 1) {
            return null;
        }

        $assignment = $assignments->first();
        $siteId = match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => (int) $assignment->assignable_id,
            DeviceAssignment::TARGET_ROOM => (int) (SiteRoom::query()
                ->whereKey($assignment->assignable_id)
                ->value('site_id') ?? 0),
            default => 0,
        };

        if ($siteId < 1) {
            return null;
        }

        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first(['id']);

        return $site ? $siteId : null;
    }

    public function canonicalFleetSiteId(FleetSignal $offline, ?ControlRoomAlert $alert): ?int
    {
        if (! app(FleetSignalService::class)->offlineScopeIsCurrent($offline)) {
            return null;
        }
        $siteId = data_get($offline->payload, 'availability.scope.site_id');
        $signal = Signal::query()->where('external_ref', 'fleet_signal_'.$offline->id)
            ->where('signal_type_code', 'fleet_device_offline')->where('site_id', $siteId)
            ->where('asset_id', $offline->asset_id)
            ->whereHas('signalSource', fn ($query) => $query->where('slug', 'queclink_fleet'))
            ->lockForUpdate()->first();
        if (! $signal || data_get($signal->normalized_data, 'fleet_signal_id') !== (int) $offline->id
            || ($alert !== null && ((int) $alert->site_id !== $siteId || (int) $alert->asset_id !== (int) $offline->asset_id
                || (int) ($signal->alert_id ?? $signal->correlated_alert_id) !== (int) $alert->id))
            || ($alert === null && MonitoringWorkRouting::directFleetDecision($signal, $offline) === null)) {
            return null;
        }

        return $siteId;
    }

    public function linkFleetMonitoringEvidence(ItTicket $ticket, FleetSignal $offline, ?ControlRoomAlert $alert): void
    {
        DB::transaction(function () use ($ticket, $offline, $alert): void {
            $ticket = ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $offline = FleetSignal::query()->whereKey($offline->id)->lockForUpdate()->firstOrFail();
            $alert = $alert ? ControlRoomAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail() : null;
            $siteId = $this->canonicalFleetSiteId($offline, $alert);
            if ($siteId === null || ! $this->isMonitoringWork($ticket, $alert) || $ticket->work_type !== 'incident'
                || $ticket->is_organisation_wide || (int) $ticket->site_id !== $siteId) {
                throw new DomainException('source_scope_changed');
            }
            $device = Device::query()->whereKey($offline->device_id)->lockForUpdate()->firstOrFail();
            $asset = Asset::query()->whereKey($offline->asset_id)->lockForUpdate()->firstOrFail();
            $context = ['source' => 'fleet', 'system_principal' => self::MONITORING_PRINCIPAL,
                'operation' => self::MONITORING_OPERATION, 'site_id' => $siteId,
                'fleet_signal_id' => (int) $offline->id, 'availability_episode_key' => $offline->idempotency_key];
            $this->persistMonitoring($ticket, $device, 'affected_device', $context);
            $this->persistMonitoring($ticket, $asset, 'affected_asset', $context);
            if ($alert !== null) {
                $this->persistMonitoring($ticket, $alert, 'source_alert', $context);
            }
        });
    }

    public function canonicalMonitoringSiteId(
        Device $device,
        ControlRoomAlert $alert,
        bool $lockForUpdate = false,
    ): ?int {
        $siteId = $this->canonicalDeviceSiteId($device, $lockForUpdate);
        $alertSiteId = $this->alertProvenance->authoritativeSiteId($alert);
        $alertDeviceId = $this->alertProvenance->authoritativeCanonicalDeviceId($alert);

        return $siteId !== null
            && $alertSiteId === $siteId
            && $alertDeviceId === (int) $device->id
                ? $siteId
                : null;
    }

    /** System attachment may extend a real handoff, but cannot invent a human selection. */
    public function isMonitoringWork(ItTicket $ticket, ?ControlRoomAlert $alert): bool
    {
        return $ticket->source === 'system' || ($alert !== null && $this->hasHumanHandoff($ticket, $alert));
    }

    public function hasHumanHandoff(ItTicket $ticket, ControlRoomAlert $alert): bool
    {
        $link = $ticket->links()->where('relationship', 'source_alert')
            ->where('linkable_type', $alert->getMorphClass())->where('linkable_id', $alert->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
        if (! $link || $link->created_by_user_id === null) {
            return false;
        }
        $context = ($link->context['source'] ?? null) === ItControlRoomHandoffService::SOURCE
            ? $link->context : ($link->context['handoff'] ?? []);

        return ($context['source'] ?? null) === ItControlRoomHandoffService::SOURCE
            && ($context['operation'] ?? null) === ItControlRoomHandoffService::OPERATION
            && ($context['site_id'] ?? null) === (int) $alert->site_id
            && ItTicketCommandReceipt::query()->where('operation', ItControlRoomHandoffService::OPERATION)
                ->where('channel', 'browser')->where('actor_user_id', $link->created_by_user_id)
                ->where('it_ticket_id', $ticket->id)->whereNotNull('committed_at')
                ->where('result_metadata->state', 'committed')->where('result_metadata->alert_id', $alert->id)
                ->whereIn('result_metadata->outcome', ['created', 'linked'])
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->exists();
    }

    /** Caller owns the source alert lock. A handoff binds once per alert, not to every later episode. */
    public function unboundHumanMonitoringTicket(ControlRoomAlert $alert): ?ItTicket
    {
        $ticket = ItTicket::query()->where('site_id', $alert->site_id)->where('is_organisation_wide', false)
            ->where('work_type', 'incident')
            ->whereHas('links', fn ($links) => $links->where('relationship', 'source_alert')
                ->where('linkable_type', $alert->getMorphClass())->where('linkable_id', $alert->id)
                ->whereNotNull('created_by_user_id')->lockForUpdate())
            ->whereDoesntHave('events', fn ($events) => $events->where('type', 'monitoring_handoff_bound')
                ->where('payload->control_room_alert_id', $alert->id)->lockForUpdate())
            ->orderBy('id')->lockForUpdate()->get()
            ->first(fn (ItTicket $ticket): bool => $this->hasHumanHandoff($ticket, $alert));
        if ($ticket?->isMerged()) {
            throw new DomainException('technical_routing_unavailable');
        }

        return $ticket;
    }

    /** Shared query boundary; human origin stays distinct from unattended creation. */
    public function monitoringTickets(): Builder
    {
        return ItTicket::query()->where(fn ($tickets) => $tickets->where('source', 'system')
            ->orWhereHas('events', fn ($events) => $events->where('type', 'monitoring_handoff_bound')
                ->where('payload->system_principal', self::MONITORING_PRINCIPAL)
                ->where('payload->operation', self::MONITORING_OPERATION)));
    }

    private function responsibleActor(?int $actorUserId): User
    {
        $actor = $actorUserId === null
            ? null
            : User::query()->whereKey($actorUserId)->whereNotNull('approved_at')->first();
        if (! $actor) {
            throw new DomainException('A current responsible actor is required for ticket links.');
        }

        return $actor;
    }

    /** @return array{0: ItTicket, 1: Model} */
    private function lockCanonicalRecords(ItTicket $ticket, Model $target): array
    {
        if ($target instanceof ItTicket) {
            $ids = collect([(int) $ticket->getKey(), (int) $target->getKey()])
                ->unique()
                ->sort()
                ->values()
                ->all();
            $lockedTickets = ItTicket::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $canonicalTicket = $lockedTickets->get((int) $ticket->getKey());
            $canonicalTarget = $lockedTickets->get((int) $target->getKey());
        } else {
            $canonicalTicket = ItTicket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->first();
            $canonicalTarget = $target->newQuery()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();
        }

        if (! $canonicalTicket || ! $canonicalTarget) {
            throw new DomainException('The ticket or linked record is no longer available.');
        }

        return [$canonicalTicket, $canonicalTarget];
    }

    private function assertHumanLinkAccess(
        User $actor,
        ItTicket $ticket,
        Model $target,
        string $relationship,
    ): void {
        if (! in_array($relationship, ItTicketLink::RELATIONSHIPS, true)) {
            throw new DomainException('That ticket-link relationship is not supported.');
        }
        if (! $this->workAccess->canWork($actor, $ticket)
            || ! $this->targetIsAccessible($actor, $target, $relationship)) {
            throw new DomainException('The ticket or linked record is not accessible to this actor.');
        }
    }

    private function targetIsAccessible(User $actor, Model $target, string $relationship): bool
    {
        if ($target instanceof Device) {
            return $relationship === 'affected_device'
                && $actor->canDo('securityDevices.devices.view')
                && $this->deviceAccess->visibleDevices($actor)->whereKey($target->getKey())->exists();
        }

        if ($target instanceof ControlRoomAlert) {
            return $relationship === 'source_alert'
                && app(ControlRoomAlertAccessService::class)->canView($target, $actor);
        }

        if ($target instanceof ItTicket) {
            return in_array($relationship, [
                'related_incident',
                'related_problem',
                'related_change',
                'major_incident_member',
            ], true)
                && Gate::forUser($actor)->allows('view', $target);
        }

        if ($target instanceof Site) {
            return $relationship === 'affected_site'
                && in_array((int) $target->getKey(), $this->workAccess->approvedSiteIds($actor), true);
        }

        return $target instanceof ItService
            && $relationship === 'affected_service'
            && $target->is_active
            && $actor->canDo('it.manage');
    }

    /** @param array<string, mixed> $context */
    private function persist(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        array $context,
        ?int $actorUserId,
    ): ItTicketLink {
        return $ticket->links()->firstOrCreate([
            'relationship' => $relationship,
            'linkable_type' => $target->getMorphClass(),
            'linkable_id' => $target->getKey(),
        ], [
            'context' => $context,
            'created_by_user_id' => $actorUserId,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function persistMonitoring(
        ItTicket $ticket,
        Model $target,
        string $relationship,
        array $context,
    ): ItTicketLink {
        $identity = [
            'relationship' => $relationship,
            'linkable_type' => $target->getMorphClass(),
            'linkable_id' => $target->getKey(),
        ];
        // Fleet may have established a snapshot before waiting for the alert.
        // Read the committed human link instead of attempting a duplicate insert.
        $link = $ticket->links()->where($identity)->lockForUpdate()->first()
            ?? $ticket->links()->make($identity);
        $previous = $link->context ?? [];
        if (($previous['source'] ?? null) === ItControlRoomHandoffService::SOURCE) {
            $context['handoff'] = $previous;
        } elseif (is_array($previous['handoff'] ?? null)) {
            $context['handoff'] = $previous['handoff'];
        }
        $link->context = [...$previous, ...$context];
        $link->save();

        return $link;
    }
}
