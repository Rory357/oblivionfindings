<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\ItProvisioningRequest;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $attachments
     */
    public function create(User $actor, array $data, array $attachments = []): ItTicket
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($actor, $data, $attachments, &$storedPaths): ItTicket {
                $actor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                if ($actor->approved_at === null
                    || (! $actor->canDo('it.request') && ! $actor->canDo('it.manage'))) {
                    throw new AuthorizationException('You are not allowed to create IT tickets.');
                }

                $isAgent = $actor->canDo('it.manage');
                $siteId = $isAgent
                    ? $this->nullableId($data['site_id'] ?? null)
                    : $this->workAccess->defaultSiteId($actor);
                $isOrganisationWide = $isAgent && (bool) ($data['is_organisation_wide'] ?? false);

                $this->guardScope($actor, $siteId, $isOrganisationWide, $isAgent, $data);

                $requesterId = $isAgent && $this->nullableId($data['requester_user_id'] ?? null) !== null
                    ? (int) $data['requester_user_id']
                    : (int) $actor->id;
                $assigneeId = $isAgent ? $this->nullableId($data['assigned_to_user_id'] ?? null) : null;
                $watcherIds = $isAgent
                    ? collect($data['watchers'] ?? [])->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique()->values()->all()
                    : [];

                $users = $this->lockUsers([$requesterId, $assigneeId, ...$watcherIds]);
                $requester = $users->get($requesterId);
                if (! $requester || ! $this->staffMemberMatchesScope($requester, $siteId, $isOrganisationWide)) {
                    throw new AuthorizationException('The requester is not available in this ticket scope.');
                }
                if ($assigneeId !== null) {
                    $assignee = $users->get($assigneeId);
                    if (! $assignee || ! $this->agentMatchesScope($assignee, $siteId, $isOrganisationWide)) {
                        throw new AuthorizationException('The assignee is not available in this ticket scope.');
                    }
                }
                foreach ($watcherIds as $watcherId) {
                    $watcher = $users->get($watcherId);
                    if (! $watcher || ! $this->staffMemberMatchesScope($watcher, $siteId, $isOrganisationWide)) {
                        throw new AuthorizationException('A watcher is not available in this ticket scope.');
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
                    'priority' => $data['priority'],
                    'work_type' => $isAgent ? ($data['work_type'] ?? 'incident') : 'incident',
                    'workflow_state' => 'submitted',
                    'source' => $isAgent ? 'agent' : 'portal',
                    'status' => $assigneeId !== null ? 'in_progress' : 'open',
                ]);

                $ticket->stampSlaDueDates();
                $ticket->save();
                $this->attachmentStorage->store($ticket, $attachments, $actor, $storedPaths);
                if ($watcherIds !== []) {
                    $ticket->watchers()->syncWithoutDetaching($watcherIds);
                }
                if ($device !== null) {
                    $this->deviceContext->linkAtIntake($ticket, $device, $actor);
                }

                ItTicketEvent::record($ticket, 'created', $actor->id, array_filter([
                    'source' => $ticket->source,
                    'assigned_to_user_id' => $assigneeId,
                    'device_id' => $deviceId,
                    'provisioning_request_id' => $provisioningRequestId,
                    'on_behalf_of' => $requesterId !== (int) $actor->id ? $requesterId : null,
                ]));
                $ticket = $this->routing->route($ticket, $actor->id);

                AuditLogger::logOrFail('it.ticket.created', $ticket, [
                    'actor_id' => $actor->id,
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

                return $ticket->refresh()->load(['requester', 'assignee', 'watchers']);
            });
        } catch (Throwable $exception) {
            $this->attachmentStorage->deleteStored($storedPaths);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    private function guardScope(
        User $actor,
        ?int $siteId,
        bool $isOrganisationWide,
        bool $isAgent,
        array $data,
    ): void {
        if (! $this->workAccess->canAssignScope($actor, $siteId, $isOrganisationWide)) {
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

    private function staffMemberMatchesScope(User $staff, ?int $siteId, bool $isOrganisationWide): bool
    {
        if ($staff->approved_at === null
            || $staff->hasRole('client')
            || $staff->hasRole('next_of_kin')
            || in_array($staff->role, ['client', 'next_of_kin'], true)) {
            return false;
        }

        return $isOrganisationWide
            ? $siteId === null
            : $siteId !== null && in_array($siteId, $this->workAccess->approvedSiteIds($staff), true);
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
