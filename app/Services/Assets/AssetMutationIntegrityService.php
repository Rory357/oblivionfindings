<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetKitItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AssetMutationIntegrityService
{
    /**
     * Ordinary edit routes may preserve a retired state, but lifecycle
     * transitions into or out of retirement use the governed lifecycle.
     */
    public function assertOrdinaryStatusUpdate(Asset $asset, ?string $requestedStatus): void
    {
        if ($requestedStatus === null || $requestedStatus === $asset->status) {
            return;
        }

        if ($requestedStatus === 'retired' || $asset->status === 'retired') {
            throw ValidationException::withMessages([
                'status' => 'Use the governed Asset retirement workflow to change a retired state.',
            ]);
        }
    }

    /**
     * Do not let a placement edit silently invalidate canonical assignment or
     * Device provenance. The caller must already hold the Asset row lock.
     *
     * The governed kit dispatch/receipt holds its parent and component Asset
     * locks and passes that parent's identity after checking the current kit.
     * Ordinary callers omit it and cannot independently reposition a kit member.
     *
     * @param  array<string, mixed>  $placement
     */
    public function assertPlacementChangeAllowed(Asset $asset, array $placement, ?int $movingKitParentId = null): void
    {
        $this->assertKitPlacementChangeAllowed($asset, $placement, $movingKitParentId);
        $changed = collect(['site_id', 'home_site_id', 'client_id'])
            ->contains(function (string $field) use ($asset, $placement): bool {
                if (! array_key_exists($field, $placement)) {
                    return false;
                }

                return $this->normalizedId($placement[$field]) !== $this->normalizedId($asset->getAttribute($field));
            });

        if (! $changed) {
            return;
        }

        if ($asset->assignments()->whereNull('released_at')->lockForUpdate()->first(['id'])) {
            throw ValidationException::withMessages([
                'site_id' => 'Release the current Asset assignment before changing its Site or client placement.',
            ]);
        }

        if ($asset->activeDeviceLinks()->lockForUpdate()->first(['id'])) {
            throw ValidationException::withMessages([
                'site_id' => 'Unlink active Devices before changing this Asset\'s Site or client placement.',
            ]);
        }
    }

    private function assertKitPlacementChangeAllowed(Asset $asset, array $placement, ?int $movingKitParentId): void
    {
        $field = collect(['site_id', 'home_site_id', 'client_id', 'site_room_id', 'room_id', 'location'])
            ->first(function (string $field) use ($asset, $placement): bool {
                if (! array_key_exists($field, $placement)) {
                    return false;
                }

                return $field === 'location'
                    ? (string) $placement[$field] !== (string) $asset->getAttribute($field)
                    : $this->normalizedId($placement[$field]) !== $this->normalizedId($asset->getAttribute($field));
            });
        if ($field === null || ! Schema::hasTable('asset_kit_items')) {
            return;
        }

        // Locking reads see the current relationship after waiting for the
        // Asset lock, including a kit linked after this transaction began.
        $links = AssetKitItem::whereNull('removed_at')->whereNotNull('component_asset_id')
            ->where(fn ($query) => $query->where('asset_id', $asset->id)->orWhere('component_asset_id', $asset->id))
            ->orderBy('id')->lockForUpdate()->get(['id', 'asset_id', 'component_asset_id']);
        if ($links->contains(fn ($link) => (int) $link->asset_id !== $movingKitParentId)) {
            throw ValidationException::withMessages([
                $field => 'Move the linked kit through dispatch and receipt, or remove the component from the kit before changing its placement.',
            ]);
        }
    }

    private function normalizedId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
