<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Integration\IntegrationSecretManager;
use App\Support\SecurityPolicy;
use Throwable;

final class CctvAuthority
{
    public function actor(int $id, bool $manage = false, bool $viewOnly = false): User
    {
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($id, ['*']);
        abort_unless($actor->approved_at && $actor->email_verified_at && ! $actor->external_clinical_account, 404, 'unavailable');
        foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
            abort_if($actor->role === $role || $actor->hasRole($role), 404, 'unavailable');
        }
        abort_if(SecurityPolicy::forceTwoFactor() && ! $actor->two_factor_confirmed_at, 404, 'unavailable');
        abort_unless($actor->canDo('securityDevices.viewAny') && ($manage ? $actor->canDo('securityDevices.integrations.manage') && $actor->canDo('securityDevices.devices.update') : ($viewOnly || $actor->canDo('securityDevices.cctv.media.view'))), 404, 'unavailable');

        return $actor;
    }

    public function device(User $actor, int $id, bool $recorder = false): array
    {
        return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $id, $recorder): array {
            $device = $reads->query(Device::query())->whereKey($id)->first();
            abort_unless($device && $device->domain === 'security' && $device->category === 'cctv' && ($recorder ? $device->subcategory === 'nvr' : $device->subcategory !== 'nvr'), 404, 'unavailable');
            abort_if($device->status === DeviceStatus::Decommissioned, 404, 'unavailable');
            app(SecurityDevicesAccessService::class)->forCurrentEvidence($reads, $id)->assertCanViewDevice($actor, $device);
            try {
                $siteId = app(CanonicalDeviceSiteResolver::class)->forCurrentEvidence($reads)->resolve($id);
            } catch (Throwable) {
                abort(404, 'unavailable');
            }

            return [$device, $siteId];
        });
    }

    public function provider(int $siteId, string $console): array
    {
        $connection = IntegrationProviderConnection::query()->forProvider('unifi')->lock('for share nowait')->first();
        $site = IntegrationSiteConfig::query()->forProvider('unifi')->active()->where('site_id', $siteId)->lock('for share nowait')->first();
        abort_unless($connection && $connection->status === 'connected' && ! $connection->requires_credential_replacement && $site && is_string($site->mapped_external_site_id) && trim($site->mapped_external_site_id) !== '', 503, 'temporarily_unavailable');
        $refs = $connection->secretReferences()->where('purpose', IntegrationSecretManager::PURPOSE_PRIMARY)->limit(2)->orderBy('id')->lock('for share nowait')->get()->map(fn ($ref) => $ref->getRawOriginal())->all();
        abort_unless(count($refs) <= 1, 503, 'temporarily_unavailable');
        $fingerprint = hash('sha256', CctvContract::json([$connection->getRawOriginal(), $site->getRawOriginal(), $refs]));

        return [$connection, $fingerprint];
    }

    public function source(User $actor, CctvSource $source, bool $allowWithdrawn = false): array
    {
        abort_unless($source->provider === 'unifi' && $source->recorder_device_id !== null, 503, 'temporarily_unavailable');
        [$camera, $site] = $this->device($actor, (int) $source->camera_id);
        [$recorder, $recorderSite] = $this->device($actor, (int) $source->recorder_device_id, true);
        abort_unless(($source->enabled || $allowWithdrawn) && $site === (int) $source->site_id && $recorderSite === $site, 404, 'unavailable');
        $collector = MonitoringCollector::query()->whereKey($source->collector_id)->lock('for share nowait')->first();
        $scope = DiscoveryScope::query()->whereKey($source->discovery_scope_id)->lock('for share nowait')->first();
        abort_unless($collector && $collector->enrolled_at && ! $collector->revoked_at && $collector->status !== 'revoked' && (int) $collector->site_id === $site && is_string($collector->public_key) && is_string($collector->client_certificate_fingerprint) && $scope && $scope->status === 'active' && (int) $scope->site_id === $site && (int) $scope->collector_id === (int) $collector->id, 503, 'temporarily_unavailable');
        [$collectorDevice, $collectorSite] = $this->collectorDevice($actor, (int) $collector->collector_device_id);
        abort_unless($collectorSite === $site, 404, 'unavailable');
        $config = $source->configuration;
        abort_unless(is_array($config) && hash_equals($source->fingerprint, CctvContract::hash($config)), 409, 'context_changed');
        abort_unless($camera->provider === 'unifi' && $recorder->provider === 'unifi'
            && $camera->mac_address === $config['camera_mac'] && $recorder->mac_address === $config['recorder_mac']
            && data_get($camera->external_ref, 'host_id') === $config['console_id'] && data_get($recorder->external_ref, 'host_id') === $config['console_id']
            && $collector->public_key === $config['collector_key'] && $collector->client_certificate_fingerprint === $config['collector_certificate'], 409, 'context_changed');
        abort_unless(CctvContract::network($scope->cidrs ?? [], $scope->exclusions ?? [], $scope->port_bounds ?? [], $config['stream_address'], $config['stream_port']), 503, 'temporarily_unavailable');
        [$connection, $fingerprint] = $this->provider($site, $config['console_id']);
        abort_unless(hash_equals($source->provider_fingerprint, $fingerprint) && in_array($config['application_version'], config('cctv.supported_protect_versions', []), true), 409, 'context_changed');

        return [$camera, $recorder, $collector, $connection];
    }

    /** Reuse CCTV operational authority; ordinary Device profile reads retain their own owner. */
    public function referenceDevice(User $actor, int $id, bool $recorder = false): array
    {
        [$device, $site] = $this->device($actor, $id, $recorder);
        abort_if($device->status === DeviceStatus::Decommissioned, 404, 'unavailable');

        return [$device, $site];
    }

    /** Recording metadata authority does not depend on a live collector or dial scope. */
    public function referenceSource(User $actor, CctvSource $source, bool $forCreation = true): array
    {
        [$camera, $site] = $this->referenceDevice($actor, (int) $source->camera_id);
        [$recorder, $recorderSite] = $this->referenceDevice($actor, (int) $source->recorder_device_id, true);
        abort_unless($site === (int) $source->site_id && $recorderSite === $site, 404, 'unavailable');
        $config = $source->configuration;
        abort_unless(is_array($config) && hash_equals($source->fingerprint, CctvContract::hash($config)), 409, 'context_changed');
        abort_unless($camera->provider === 'unifi' && $recorder->provider === 'unifi'
            && $camera->mac_address === $config['camera_mac'] && $recorder->mac_address === $config['recorder_mac']
            && data_get($camera->external_ref, 'host_id') === $config['console_id'] && data_get($recorder->external_ref, 'host_id') === $config['console_id'], 409, 'context_changed');
        if ($forCreation) {
            abort_unless($source->enabled, 409, 'context_changed');
            [, $fingerprint] = $this->provider($site, $config['console_id']);
            abort_unless(hash_equals($source->provider_fingerprint, $fingerprint) && in_array($config['application_version'], config('cctv.supported_protect_versions', []), true), 409, 'context_changed');
        }

        return [$camera, $recorder, $site];
    }

    public function collectorDevice(User $actor, int $id): array
    {
        return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $id): array {
            $device = $reads->query(Device::query())->whereKey($id)->first();
            abort_unless($device, 404, 'unavailable');
            app(SecurityDevicesAccessService::class)->forCurrentEvidence($reads, $id)->assertCanViewDevice($actor, $device);
            try {
                $site = app(CanonicalDeviceSiteResolver::class)->forCurrentEvidence($reads)->resolve($id);
            } catch (Throwable) {
                abort(404, 'unavailable');
            }

            return [$device, $site];
        });
    }

    public static function fingerprint(User $actor): string
    {
        return ObliStaffAccess::fingerprint($actor);
    }
}
