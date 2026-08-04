<?php

namespace App\Services\ControlRoom;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\UserSiteAccessService;
use DomainException;

/**
 * Resolves and validates the ownership tuple behind a Control Room alert.
 *
 * The alert's direct site/client fields are authoritative. Nested asset,
 * signal, and device records may enrich that alert only when they agree with
 * the same client/Site tuple; contradictory legacy links are treated as
 * untrusted instead of becoming an alternate authorization path.
 */
class ControlRoomAlertProvenanceService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function authoritativeClientId(ControlRoomAlert $alert): ?int
    {
        $clientId = $alert->client_id
            ?? data_get($alert->context, 'client_id')
            ?? data_get($alert->context, 'normalized_data.client_id');

        return is_numeric($clientId) && (int) $clientId > 0 ? (int) $clientId : null;
    }

    public function authoritativeSiteId(ControlRoomAlert $alert): ?int
    {
        if (is_numeric($alert->site_id) && (int) $alert->site_id > 0) {
            return (int) $alert->site_id;
        }

        // Only the direct client FK may supply the relational fallback site.
        // Context identity is untrusted enrichment and must be checked against
        // an independently resolved context site, never allowed to choose it.
        if (is_numeric($alert->client_id) && (int) $alert->client_id > 0) {
            $loadedClient = $alert->relationLoaded('client') ? $alert->client : null;
            $clientSiteId = $loadedClient && (int) $loadedClient->id === (int) $alert->client_id
                ? $loadedClient->site_id
                : Client::query()->whereKey((int) $alert->client_id)->value('site_id');
            if (is_numeric($clientSiteId) && (int) $clientSiteId > 0) {
                return (int) $clientSiteId;
            }
        }

        foreach ([
            'site_id',
            'shift_context.site.id',
            'shift.site_id',
            'shift.site.id',
            'site.id',
        ] as $path) {
            $siteId = data_get($alert->context, $path);
            if (is_numeric($siteId) && (int) $siteId > 0) {
                return (int) $siteId;
            }
        }

        return null;
    }

    /**
     * Resolve the canonical Security & Devices identity claimed by an alert.
     * Native signals carry it directly; retained Control Room projections are
     * accepted only as historical corroboration and conflicting claims fail
     * closed.
     */
    public function authoritativeCanonicalDeviceId(ControlRoomAlert $alert): ?int
    {
        $contextDeviceId = data_get($alert->context, 'normalized_data.canonical_device_id');
        $contextDeviceId = is_numeric($contextDeviceId) && (int) $contextDeviceId > 0
            ? (int) $contextDeviceId
            : null;

        $projectionDeviceId = null;
        if ($alert->device_id !== null) {
            $loadedProjection = $alert->relationLoaded('device') ? $alert->device : null;
            $projectionDeviceId = $loadedProjection && (int) $loadedProjection->id === (int) $alert->device_id
                ? $loadedProjection->canonical_device_id
                : Device::query()->whereKey($alert->device_id)->value('canonical_device_id');
            $projectionDeviceId = is_numeric($projectionDeviceId) && (int) $projectionDeviceId > 0
                ? (int) $projectionDeviceId
                : null;

            if ($projectionDeviceId === null) {
                return null;
            }
        }

        if ($contextDeviceId !== null
            && $projectionDeviceId !== null
            && $contextDeviceId !== $projectionDeviceId
        ) {
            return null;
        }

        return $contextDeviceId ?? $projectionDeviceId;
    }

    public function clientMatchesAlert(ControlRoomAlert $alert): bool
    {
        $clientId = $this->authoritativeClientId($alert);
        if ($clientId === null) {
            return $alert->client_id === null;
        }

        $loadedClient = $alert->relationLoaded('client') ? $alert->client : null;
        $client = $loadedClient && (int) $loadedClient->id === $clientId
            ? $loadedClient
            : Client::query()->find($clientId);

        return $client !== null
            && $this->clientMatchesTuple($client, $this->authoritativeSiteId($alert));
    }

    public function safeClient(ControlRoomAlert $alert): ?Client
    {
        $alert->loadMissing('client:id,first_name,last_name,site_id');

        return $alert->client && $this->clientMatchesAlert($alert)
            ? $alert->client
            : null;
    }

    public function safeAsset(ControlRoomAlert $alert): ?Asset
    {
        $alert->loadMissing([
            'asset:id,name,asset_tag,site_id,home_site_id,client_id',
            'asset.client:id,site_id',
        ]);

        return $alert->asset && $this->assetMatchesAlert($alert, $alert->asset)
            ? $alert->asset
            : null;
    }

    public function safeAssignedTo(ControlRoomAlert $alert, User $viewer): ?User
    {
        if ($alert->assigned_to_user_id === null) {
            return null;
        }

        $query = User::query()
            ->staff()
            ->whereKey($alert->assigned_to_user_id);
        $this->siteAccess->applyControlRoomAssigneeScope($query, $viewer, ['reports.viewAny']);
        if (! $query->exists()) {
            return null;
        }

        $alert->loadMissing('assignedTo:id,name,email');

        return $alert->assignedTo;
    }

    /**
     * Remove nested identity and location fields that are not backed by the
     * alert's authoritative client/site/source tuple.
     *
     * @return array<string, mixed>
     */
    public function sanitiseContextForRead(ControlRoomAlert $alert): array
    {
        $context = is_array($alert->context) ? $alert->context : [];
        $authoritativeClientId = $this->authoritativeClientId($alert);
        $hasClientContext = collect([
            data_get($context, 'client_id'),
            data_get($context, 'client_name'),
            data_get($context, 'client'),
            data_get($context, 'resident_id'),
            data_get($context, 'resident_name'),
            data_get($context, 'resident'),
            data_get($context, 'normalized_data.client_id'),
            data_get($context, 'normalized_data.client_name'),
            data_get($context, 'normalized_data.client'),
            data_get($context, 'normalized_data.resident_id'),
            data_get($context, 'normalized_data.resident_name'),
            data_get($context, 'normalized_data.resident'),
        ])->contains(fn ($value) => filled($value));
        $contextClientIds = collect([
            data_get($context, 'client_id'),
            data_get($context, 'client.id'),
            data_get($context, 'resident_id'),
            data_get($context, 'resident.id'),
            data_get($context, 'normalized_data.client_id'),
            data_get($context, 'normalized_data.client.id'),
            data_get($context, 'normalized_data.resident_id'),
            data_get($context, 'normalized_data.resident.id'),
        ])
            ->filter(fn ($value) => is_numeric($value) && (int) $value > 0)
            ->map(fn ($value) => (int) $value);
        $contextClientConflict = $authoritativeClientId !== null
            && $contextClientIds->contains(fn (int $clientId) => $clientId !== $authoritativeClientId);
        $unsafeClientReference = ($authoritativeClientId !== null || $hasClientContext)
            && ($authoritativeClientId === null
                || ! $this->clientMatchesAlert($alert)
                || $contextClientConflict);
        $unsafeFleetReference = ($alert->asset_id !== null && ! $this->assetMatchesAlert($alert))
            || ($alert->fleet_signal_id !== null && ! $this->fleetSignalMatchesAlert($alert));
        $unsafeDeviceReference = $alert->device_id !== null && ! $this->deviceMatchesAlert($alert);

        if ($unsafeClientReference) {
            unset(
                $context['client_id'],
                $context['client_name'],
                $context['client'],
                $context['resident_id'],
                $context['resident_name'],
                $context['resident'],
            );
        }

        if ($unsafeFleetReference) {
            unset(
                $context['fleet_context'],
                $context['asset_id'],
                $context['asset_name'],
                $context['fleet_signal_id'],
                $context['latitude'],
                $context['longitude'],
                $context['coordinates'],
            );
        }

        if ($unsafeDeviceReference) {
            unset(
                $context['device_id'],
                $context['device'],
                $context['device_location'],
                $context['latitude'],
                $context['longitude'],
                $context['coordinates'],
            );
        }

        if (is_array($context['normalized_data'] ?? null)) {
            if ($unsafeClientReference) {
                unset(
                    $context['normalized_data']['client_id'],
                    $context['normalized_data']['client_name'],
                    $context['normalized_data']['client'],
                    $context['normalized_data']['resident_id'],
                    $context['normalized_data']['resident_name'],
                    $context['normalized_data']['resident'],
                );
            }
            if ($unsafeFleetReference) {
                unset(
                    $context['normalized_data']['fleet_context'],
                    $context['normalized_data']['asset_id'],
                    $context['normalized_data']['asset_name'],
                    $context['normalized_data']['fleet_signal_id'],
                    $context['normalized_data']['latitude'],
                    $context['normalized_data']['longitude'],
                    $context['normalized_data']['coordinates'],
                );
            }
            if ($unsafeDeviceReference) {
                unset(
                    $context['normalized_data']['device_id'],
                    $context['normalized_data']['device'],
                    $context['normalized_data']['device_location'],
                    $context['normalized_data']['latitude'],
                    $context['normalized_data']['longitude'],
                    $context['normalized_data']['coordinates'],
                );
            }
        }

        return $context;
    }

    public function assetMatchesAlert(ControlRoomAlert $alert, ?Asset $asset = null): bool
    {
        if ($alert->asset_id === null) {
            return true;
        }

        $asset ??= Asset::query()->find($alert->asset_id);

        return $asset !== null
            && $this->assetMatchesTuple(
                $asset,
                $this->authoritativeSiteId($alert),
                $this->authoritativeClientId($alert),
            );
    }

    public function fleetSignalMatchesAlert(
        ControlRoomAlert $alert,
        ?FleetSignal $signal = null,
    ): bool {
        if ($alert->fleet_signal_id === null) {
            return true;
        }

        $signal ??= FleetSignal::query()->find($alert->fleet_signal_id);

        return $signal !== null
            && $this->fleetSignalMatchesTuple(
                $signal,
                $this->authoritativeSiteId($alert),
                $this->authoritativeClientId($alert),
                $alert->asset_id ? (int) $alert->asset_id : null,
            );
    }

    public function deviceMatchesAlert(ControlRoomAlert $alert, ?Device $device = null): bool
    {
        if ($alert->device_id === null) {
            return true;
        }

        $device ??= Device::query()->find($alert->device_id);

        return $device !== null
            && $this->deviceMatchesTuple(
                $device,
                $this->authoritativeSiteId($alert),
                $this->authoritativeClientId($alert),
                $alert->asset_id ? (int) $alert->asset_id : null,
            );
    }

    public function assetMatchesTuple(Asset $asset, ?int $siteId, ?int $clientId): bool
    {
        if ($siteId === null) {
            return false;
        }

        $asset->loadMissing('client:id,site_id');
        $assetSiteId = $asset->site_id
            ?: $asset->client?->site_id
            ?: $asset->home_site_id;

        if (! is_numeric($assetSiteId) || (int) $assetSiteId !== $siteId) {
            return false;
        }

        if ($asset->client_id !== null
            && ($clientId === null || (int) $asset->client_id !== $clientId)
        ) {
            return false;
        }

        return $asset->client === null || $this->clientMatchesTuple($asset->client, $siteId);
    }

    public function fleetSignalMatchesTuple(
        FleetSignal $signal,
        ?int $siteId,
        ?int $clientId,
        ?int $assetId = null,
    ): bool {
        if ($assetId !== null && (int) $signal->asset_id !== $assetId) {
            return false;
        }

        $signal->loadMissing('asset.client:id,site_id');

        return $signal->asset !== null
            && $this->assetMatchesTuple($signal->asset, $siteId, $clientId);
    }

    public function deviceMatchesTuple(
        Device $device,
        ?int $siteId,
        ?int $clientId,
        ?int $assetId = null,
    ): bool {
        if ($siteId === null) {
            return false;
        }

        if ($device->site_id !== null && (int) $device->site_id !== $siteId) {
            return false;
        }

        if ($device->client_id !== null
            && ($clientId === null || (int) $device->client_id !== $clientId)
        ) {
            return false;
        }

        if ($device->asset_id !== null
            && ($assetId === null || (int) $device->asset_id !== $assetId)
        ) {
            return false;
        }

        if ($device->site_id !== null) {
            return true;
        }

        if ($device->client_id !== null) {
            $client = Client::query()->find($device->client_id);

            return $client !== null && $this->clientMatchesTuple($client, $siteId);
        }

        if ($device->asset_id !== null) {
            $asset = Asset::query()->find($device->asset_id);

            return $asset !== null && $this->assetMatchesTuple($asset, $siteId, $clientId);
        }

        return false;
    }

    public function assertIncidentTuple(
        ControlRoomAlert $alert,
        int $clientId,
        ?int $siteId,
    ): void {
        $alertClientId = $this->authoritativeClientId($alert);
        $alertSiteId = $this->authoritativeSiteId($alert);
        $client = Client::query()->find($clientId);

        if ($client === null
            || $alertClientId === null
            || $alertClientId !== $clientId
            || ! $this->clientMatchesTuple($client, $siteId)
            || ($alertSiteId !== null && $alertSiteId !== $siteId)
            || ! $this->assetMatchesAlert($alert)
            || ! $this->fleetSignalMatchesAlert($alert)
            || ! $this->deviceMatchesAlert($alert)
        ) {
            throw new DomainException(
                'Incident journey provenance conflict: the alert client, site, or nested source does not share one ownership tuple.',
            );
        }
    }

    public function assertHealthSafetyEventTuple(
        ControlRoomAlert $alert,
        HsEvent $event,
    ): void {
        $alertSiteId = $this->authoritativeSiteId($alert);
        $alertClientId = $this->authoritativeClientId($alert);

        $clientMismatch = $alertClientId === null
            ? $event->client_id !== null
            : (int) $event->client_id !== $alertClientId;
        $assetMismatch = $event->asset_id !== null
            && ($alert->asset_id === null || (int) $event->asset_id !== (int) $alert->asset_id);

        if ($alertSiteId === null
            || (int) $event->site_id !== $alertSiteId
            || $clientMismatch
            || $assetMismatch
            || ! $this->clientMatchesAlert($alert)
            || ! $this->assetMatchesAlert($alert)
            || ! $this->fleetSignalMatchesAlert($alert)
            || ! $this->deviceMatchesAlert($alert)
        ) {
            throw new DomainException(
                'H&S handover provenance conflict: the alert and H&S event do not share one Client/Site ownership tuple.',
            );
        }
    }

    private function clientMatchesTuple(Client $client, ?int $siteId): bool
    {
        if ($siteId === null || $client->site_id === null || (int) $client->site_id !== $siteId) {
            return false;
        }

        return true;
    }
}
