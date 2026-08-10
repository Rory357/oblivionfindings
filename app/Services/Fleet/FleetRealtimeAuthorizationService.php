<?php

namespace App\Services\Fleet;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\FleetSignal;
use App\Models\User;
use UnexpectedValueException;

final class FleetRealtimeAuthorizationService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly PersonalTrackingPrivacyService $privacy,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
    ) {}

    public function canViewAssetPosition(User $user, int $assetId): bool
    {
        if ($assetId < 1 || ! (
            $user->canDo('fleet.viewAny')
            || $user->canDo('assets.viewAny')
            || $user->canDo('assets.viewAssigned')
        )) {
            return false;
        }

        return $this->access->assignableVehicle($user, $assetId) !== null;
    }

    public function canViewClientAlert(User $user, int $clientId): bool
    {
        if ($clientId < 1
            || ! ($user->canDo('fleet.viewAny') || $user->canDo('assets.viewAny'))
            || ! $user->canDo('assets.telemetry.view')) {
            return false;
        }

        $client = $this->access->assignableClient($user, $clientId);

        return $client !== null
            && $this->privacy->authorisedClientAssignment($client) !== null;
    }

    /**
     * Resolve the exact Client authorised by the signal's current consent,
     * Device, Asset link, and canonical Site provenance. Any mismatch fails closed.
     */
    public function consentedClientForSignal(FleetSignal $signal): ?Client
    {
        $deviceId = (int) $signal->device_id;
        $assetId = (int) $signal->asset_id;
        if ($deviceId < 1 || $assetId < 1) {
            return null;
        }

        $signal->loadMissing('asset.client');
        $client = $signal->asset?->client;
        if (! $client instanceof Client || ! is_numeric($client->site_id)) {
            return null;
        }

        $assignment = $this->privacy->authorisedClientAssignment($client);
        if ($assignment === null || (int) $assignment->device_id !== $deviceId) {
            return null;
        }

        $hasExactActiveLink = DeviceAssetLink::query()
            ->forDevice($deviceId)
            ->forAsset($assetId)
            ->active()
            ->exists();
        if (! $hasExactActiveLink) {
            return null;
        }

        try {
            $siteId = $this->siteResolver->resolveForContext($deviceId);
        } catch (UnexpectedValueException) {
            return null;
        }

        return $siteId === (int) $client->site_id ? $client : null;
    }
}
