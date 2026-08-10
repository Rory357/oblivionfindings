<?php

namespace App\Services\Integration;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\LocationHardware;
use App\Models\SiteRoom;
use Illuminate\Support\Collection;

/**
 * Resolves provider payload identities only inside their canonical mapped Site.
 *
 * Legacy partition values are deliberately ignored. Conflicting identities or
 * assignments fail closed so a provider sync can never relocate another Site's
 * Device merely because old storage metadata happens to match.
 */
final class CanonicalIntegrationDeviceResolver
{
    public function resolveInventory(
        IntegrationSiteConfig $siteConfig,
        string $provider,
        string $providerEntityId,
        array $payload,
    ): ?Device {
        $candidateIds = $this->providerIdentityCandidateIds(
            provider: $provider,
            providerEntityId: $providerEntityId,
            serial: $payload['serial'] ?? null,
            mac: $payload['mac'] ?? null,
        );

        return $this->singleCandidateAtSite($candidateIds, (int) $siteConfig->site_id, true);
    }

    public function resolveHealth(
        IntegrationSiteConfig $siteConfig,
        string $provider,
        array $entry,
    ): ?Device {
        $candidateIds = collect();
        $deviceId = $entry['device_id'] ?? null;
        if (is_numeric($deviceId)) {
            $candidateIds->push(...Device::query()
                ->byProvider($provider)
                ->whereKey((int) $deviceId)
                ->pluck('id'));
        }

        $providerEntityId = isset($entry['provider_entity_id'])
            ? trim((string) $entry['provider_entity_id'])
            : '';
        if ($providerEntityId !== '') {
            $candidateIds->push(...Device::query()
                ->byProvider($provider)
                ->where('external_ref->provider_entity_id', $providerEntityId)
                ->pluck('id'));
        }

        $hardwareId = $entry['hardware_id'] ?? null;
        if (is_numeric($hardwareId)) {
            $candidateIds->push(...Device::query()
                ->byProvider($provider)
                ->where('legacy_location_hardware_id', (int) $hardwareId)
                ->pluck('id'));
        }

        return $this->singleCandidateAtSite($candidateIds, (int) $siteConfig->site_id, false);
    }

    /** @return Collection<int, int> */
    private function providerIdentityCandidateIds(
        string $provider,
        string $providerEntityId,
        mixed $serial,
        mixed $mac,
    ): Collection {
        $candidateIds = Device::query()
            ->byProvider($provider)
            ->where('external_ref->provider_entity_id', $providerEntityId)
            ->pluck('id');

        $legacyIds = LocationHardware::query()
            ->where('provider', $provider)
            ->where('external_ref->provider_entity_id', $providerEntityId)
            ->pluck('id');
        if ($legacyIds->isNotEmpty()) {
            $candidateIds->push(...Device::query()
                ->byProvider($provider)
                ->whereIn('legacy_location_hardware_id', $legacyIds)
                ->pluck('id'));
        }

        $serialValue = is_scalar($serial) ? trim((string) $serial) : '';
        if ($serialValue !== '') {
            $candidateIds->push(...Device::query()
                ->byProvider($provider)
                ->whereRaw('LOWER(serial_number) = ?', [strtolower($serialValue)])
                ->pluck('id'));
        }

        $macValue = is_scalar($mac) ? trim((string) $mac) : '';
        if ($macValue !== '') {
            $candidateIds->push(...Device::query()
                ->byProvider($provider)
                ->whereRaw('LOWER(mac_address) = ?', [strtolower($macValue)])
                ->pluck('id'));
        }

        return $candidateIds
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }

    /** @param Collection<int, mixed> $candidateIds */
    private function singleCandidateAtSite(
        Collection $candidateIds,
        int $siteId,
        bool $throwWhenOutsideSite,
    ): ?Device {
        $ids = $candidateIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->count() > 1) {
            throw new \RuntimeException('Provider device identity is ambiguous and requires reconciliation.');
        }

        if ($ids->isEmpty()) {
            return null;
        }

        $device = Device::query()->find($ids->first());
        if ($device !== null && $this->belongsOnlyToSite($device, $siteId)) {
            return $device;
        }

        if ($throwWhenOutsideSite) {
            throw new \RuntimeException('Provider device identity belongs to another Site and requires reconciliation.');
        }

        return null;
    }

    private function belongsOnlyToSite(Device $device, int $siteId): bool
    {
        $assignments = $device->assignments()->active()->get(['assignable_type', 'assignable_id']);
        if ($assignments->isEmpty()) {
            return false;
        }

        $roomSiteIds = SiteRoom::query()
            ->whereKey($assignments
                ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
                ->pluck('assignable_id'))
            ->pluck('site_id', 'id');

        foreach ($assignments as $assignment) {
            $assignmentSiteId = match ($assignment->assignable_type) {
                DeviceAssignment::TARGET_SITE => (int) $assignment->assignable_id,
                DeviceAssignment::TARGET_ROOM => (int) ($roomSiteIds->get($assignment->assignable_id) ?? 0),
                default => 0,
            };

            if ($assignmentSiteId !== $siteId) {
                return false;
            }
        }

        return true;
    }
}
