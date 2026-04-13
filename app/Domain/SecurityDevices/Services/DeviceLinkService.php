<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;

class DeviceLinkService
{
    /**
     * Link a device to an asset.
     *
     * @throws \InvalidArgumentException if an active link already exists for this device-asset pair.
     */
    public function link(
        Device $device,
        Asset $asset,
        int $linkedByUserId,
        LinkType $linkType = LinkType::Primary,
        ?string $notes = null,
    ): DeviceAssetLink {
        $existing = DeviceAssetLink::query()
            ->active()
            ->where('device_id', $device->id)
            ->where('asset_id', $asset->id)
            ->exists();

        if ($existing) {
            throw new \InvalidArgumentException(
                "Device {$device->device_uid} is already actively linked to asset #{$asset->id}."
            );
        }

        return DeviceAssetLink::create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => $linkType,
            'linked_at' => now(),
            'linked_by_user_id' => $linkedByUserId,
            'notes' => $notes,
        ]);
    }

    /**
     * Unlink a device from an asset (sets unlinked_at, preserves history).
     */
    public function unlink(DeviceAssetLink $link): DeviceAssetLink
    {
        if (!$link->isActive()) {
            throw new \InvalidArgumentException('This link is already inactive.');
        }

        $link->update(['unlinked_at' => now()]);

        return $link->fresh();
    }

    /**
     * Unlink all active links for a device.
     */
    public function unlinkAllForDevice(Device $device): int
    {
        return $device->activeAssetLinks()
            ->update(['unlinked_at' => now()]);
    }
}
