<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Models\HikvisionDiscoveryRequest;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;

final class HikvisionDiscoveryAuthority
{
    public const CAPABILITY = 'cctv:hikvision:discovery:read_only';

    public function binding(int $actorId, int $deviceId, array $configuration, bool $manage = true): array
    {
        $actor = app(CctvAuthority::class)->actor($actorId, $manage);
        $device = Device::query()->whereKey($deviceId)->lock('for share nowait')->first();
        abort_unless($device && $device->provider === 'hikvision' && ! $device->status->isRetired(), 404, 'unavailable');
        [$device,$site] = app(CctvAuthority::class)->device($actor, $deviceId, $device->subcategory === 'nvr');
        abort_unless(is_string($device->ip_address) && $device->ip_address === $configuration['allowed_ip'], 409, 'context_changed');
        $collector = MonitoringCollector::query()->whereKey($configuration['collector_id'])->lock('for share nowait')->first();
        abort_unless($collector && $collector->enrolled_at && ! $collector->revoked_at && $collector->status !== 'revoked' && (int) $collector->site_id === $site && is_string($collector->public_key) && is_string($collector->client_certificate_fingerprint), 503, 'temporarily_unavailable');
        [$collectorDevice, $collectorSite] = app(CctvAuthority::class)->collectorDevice($actor, (int) $collector->collector_device_id);
        abort_unless($collectorSite === $site && ! $collectorDevice->status->isRetired(), 404, 'unavailable');
        $key = base64_decode($collector->public_key, true);
        abort_unless(is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && preg_match('/\A[0-9a-f]{64}\z/', $collector->client_certificate_fingerprint), 503, 'temporarily_unavailable');
        $scope = DiscoveryScope::query()->whereKey($configuration['discovery_scope_id'])->lock('for share nowait')->first();
        abort_unless($scope && $scope->status === 'active' && (int) $scope->site_id === $site && (int) $scope->collector_id === (int) $collector->id
            && in_array('tls', $scope->protocols ?? [], true)
            && CctvContract::network($scope->cidrs ?? [], $scope->exclusions ?? [], $scope->port_bounds ?? [], $configuration['allowed_ip'], $configuration['allowed_port']), 503, 'temporarily_unavailable');
        $capability = $device->subcategory === 'nvr' ? HikvisionRecorderDiscoveryContract::CAPABILITY : self::CAPABILITY;
        $reference = CredentialReference::query()->whereKey($configuration['credential_reference_id'])->lock('for share nowait')->first();
        abort_unless($reference && (int) $reference->site_id === $site && $reference->provider === 'hikvision' && $reference->purpose === 'cctv_read_only'
            && $reference->status === CredentialReferenceStatus::Active && $reference->test_status === CredentialTestStatus::Passed
            && ! in_array($reference->rotation_status, [CredentialRotationStatus::Overdue, CredentialRotationStatus::Failed], true)
            && in_array($capability, $reference->capabilities ?? [], true), 503, 'temporarily_unavailable');
        $credential = CctvContract::hash(['id' => (int) $reference->id, 'uuid' => $reference->reference_uuid, 'site_id' => (int) $reference->site_id, 'provider' => $reference->provider, 'purpose' => $reference->purpose, 'version' => (int) $reference->version, 'status' => $reference->status->value, 'rotation' => $reference->rotation_status->value, 'test' => $reference->test_status->value, 'capabilities' => $reference->capabilities, 'pointer' => $reference->getRawOriginal('secret_manager_reference')]);
        $deviceIdentity = [];
        foreach (['id', 'domain', 'category', 'subcategory', 'provider', 'model', 'serial_number', 'mac_address', 'ip_address', 'status'] as $field) {
            $deviceIdentity[$field] = $device->getRawOriginal($field);
        }
        $collectorIdentity = ['id' => (int) $collector->id, 'uuid' => $collector->collector_uuid, 'device_id' => (int) $collector->collector_device_id, 'site_id' => (int) $collector->site_id, 'key' => $collector->public_key, 'certificate' => $collector->client_certificate_fingerprint, 'enrolled_at' => $collector->getRawOriginal('enrolled_at')];
        $scopeSnapshot = $scope->snapshot();
        $fixedConfiguration = $configuration;
        unset($fixedConfiguration['expected_source_revision']);
        $binding = CctvContract::hash(['device' => $deviceIdentity, 'site_id' => $site, 'collector' => $collectorIdentity, 'scope' => $scopeSnapshot, 'configuration' => $fixedConfiguration, 'credential' => $credential]);

        return ['actor' => $actor, 'device' => $device, 'site' => $site, 'collector' => $collector, 'reference' => $reference, 'credential' => $credential, 'binding' => $binding, 'capability' => $capability, 'device_identity' => $deviceIdentity, 'collector_identity' => $collectorIdentity, 'scope_snapshot' => $scopeSnapshot];
    }

    public function current(int $actorId, int $deviceId, array $configuration): array
    {
        $current = $this->binding($actorId, $deviceId, $configuration);
        $latest = $current['device']->subcategory === 'nvr' ? null : CctvSource::query()->where('camera_id', $deviceId)->orderByDesc('revision')->lock('for share nowait')->first();
        $revision = $latest ? (int) $latest->revision : 0;
        abort_unless($revision === $configuration['expected_source_revision'], 409, 'context_changed');
        // Preserve the original discovery context bytes/hash semantics. Source
        // bindings separately omit actor/revision for an approved durable mapping.
        $context = CctvContract::hash(['actor_id' => (int) $current['actor']->id, 'actor' => CctvAuthority::fingerprint($current['actor']), 'device' => $current['device_identity'], 'site_id' => $current['site'], 'collector' => $current['collector_identity'], 'scope' => $current['scope_snapshot'], 'source_revision' => $revision, 'source_id' => $latest?->id, 'source_fingerprint' => $latest?->fingerprint, 'configuration' => $configuration, 'credential' => $current['credential']]);

        return $current + ['context' => $context, 'revision' => $revision];
    }

    public function request(HikvisionDiscoveryRequest $request): array
    {
        $current = $this->current((int) $request->actor_id, (int) $request->device_id, $request->configuration);
        abort_unless($current['site'] === (int) $request->site_id && hash_equals($request->actor_fingerprint, CctvAuthority::fingerprint($current['actor'])) && hash_equals($request->context_fingerprint, $current['context']) && hash_equals($request->credential_fingerprint, $current['credential']), 409, 'context_changed');

        return $current;
    }

    public function reader(int $actorId, HikvisionDiscoveryRequest $request): User
    {
        $actor = app(CctvAuthority::class)->actor($actorId, true);
        abort_unless((int) $request->actor_id === $actorId, 404, 'unavailable');
        $device = Device::query()->whereKey($request->device_id)->lock('for share nowait')->first();
        abort_unless($device, 404, 'unavailable');
        [, $site] = app(CctvAuthority::class)->device($actor, (int) $device->id, $device->subcategory === 'nvr');
        abort_unless($site === (int) $request->site_id, 404, 'unavailable');

        return $actor;
    }
}
