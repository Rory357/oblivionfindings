<?php

namespace App\Services\Assets;

use App\Models\Asset;
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
     * @param  array{site_id?: int|null, home_site_id?: int|null, client_id?: int|null}  $placement
     */
    public function assertPlacementChangeAllowed(Asset $asset, array $placement): void
    {
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

    private function normalizedId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
