<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use Illuminate\Support\Facades\DB;

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
        if (! $link->isActive()) {
            throw new \InvalidArgumentException('This link is already inactive.');
        }

        $link->update(['unlinked_at' => now()]);

        return $link->fresh();
    }

    /**
     * Unlink all active links for a device.
     */
    public function unlinkAllForDevice(Device $device, ?string $reason = null): int
    {
        return DB::transaction(function () use ($device, $reason): int {
            $links = DeviceAssetLink::query()
                ->where('device_id', $device->id)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $unlinkedAt = now();

            foreach ($links as $link) {
                $attributes = ['unlinked_at' => $unlinkedAt];
                if ($reason !== null && trim($reason) !== '') {
                    $attributes['notes'] = $this->notesWithLifecycleReason($link->notes, trim($reason));
                }
                $link->update($attributes);
            }

            return $links->count();
        });
    }

    private function notesWithLifecycleReason(?string $notes, string $reason): string
    {
        $stamp = "Lifecycle reason: {$reason}.";
        $notes = trim((string) $notes);

        return str_contains($notes, $stamp)
            ? $notes
            : trim($notes.PHP_EOL.$stamp);
    }
}
