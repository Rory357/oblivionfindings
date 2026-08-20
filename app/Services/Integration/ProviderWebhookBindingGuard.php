<?php

namespace App\Services\Integration;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Data\VerifiedWebhookBinding;
use App\Services\Integration\Exceptions\WebhookBindingUnavailable;

final readonly class ProviderWebhookBindingGuard
{
    public function __construct(private CanonicalDeviceSiteResolver $siteResolver) {}

    public function assertActive(
        string $provider,
        int $providerConnectionId,
        int $siteId,
        VerifiedWebhookBinding $binding,
    ): void {
        $connection = IntegrationProviderConnection::query()
            ->whereKey($providerConnectionId)
            ->forProvider($provider)
            ->connected()
            ->sharedLock()
            ->first(['id']);
        if ($connection === null) {
            throw new WebhookBindingUnavailable;
        }

        $siteConfig = IntegrationSiteConfig::query()
            ->whereKey($binding->siteConfigId)
            ->forProvider($provider)
            ->active()
            ->where('site_id', $siteId)
            ->where('mapped_external_site_id', $binding->externalSiteId)
            ->whereHas('site', fn ($site) => $site
                ->where('is_active', true)
                ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                ->whereNull('archived_at'))
            ->sharedLock()
            ->first(['id']);
        if ($siteConfig === null) {
            throw new WebhookBindingUnavailable;
        }

        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->sharedLock()
            ->first(['id']);
        if ($site === null) {
            throw new WebhookBindingUnavailable;
        }

        if ($binding->canonicalDeviceId === null) {
            return;
        }

        $device = Device::query()
            ->whereKey($binding->canonicalDeviceId)
            ->byProvider($provider)
            ->where('external_ref->provider_entity_id', $binding->providerEntityId)
            ->sharedLock()
            ->first(['id', 'external_ref']);
        if ($device === null) {
            throw new WebhookBindingUnavailable;
        }

        // Hold the canonical provenance rows stable through staging/projection.
        // The resolver below remains the authority for deriving the Site.
        DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->whereNull('released_at')
            ->where('assigned_at', '<=', now())
            ->orderBy('id')
            ->sharedLock()
            ->get(['id']);
        DeviceAssetLink::query()
            ->where('device_id', $device->id)
            ->whereNull('unlinked_at')
            ->orderBy('id')
            ->sharedLock()
            ->get(['id']);

        $deviceExternalSiteId = data_get($device->external_ref, 'application_id')
            ?? data_get($device->external_ref, 'external_site_id');
        if ($deviceExternalSiteId !== null
            && (! is_scalar($deviceExternalSiteId)
                || trim((string) $deviceExternalSiteId) !== $binding->externalSiteId)) {
            throw new WebhookBindingUnavailable;
        }

        try {
            $canonicalSiteId = $this->siteResolver->resolve((int) $device->id);
        } catch (\Throwable) {
            throw new WebhookBindingUnavailable;
        }

        if ($canonicalSiteId !== $siteId) {
            throw new WebhookBindingUnavailable;
        }
    }
}
