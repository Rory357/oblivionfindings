<?php

namespace App\Services\Assets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetOwnership;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssetOwnershipService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @param array<string, mixed> $data */
    public function change(User $actor, Asset $asset, array $data): AssetOwnership
    {
        return DB::transaction(function () use ($actor, $asset, $data): AssetOwnership {
            $asset = $this->access->assignableAsset($actor, (int) $asset->getKey(), true) ?? abort(404);
            $ownerId = (int) $data['owner_id'];

            if ($data['owner_type'] === 'site') {
                abort_unless(in_array($ownerId, $this->access->accessibleSiteIds($actor), true), 404);
                abort_unless($this->assetCanBelongToSite($asset, $ownerId), 404);
            } else {
                $client = $this->access->assignableClient($actor, $ownerId, true) ?? abort(404);
                abort_unless($this->assetCanBelongToSite($asset, (int) $client->site_id), 404);
            }

            $effectiveFrom = CarbonImmutable::parse($data['effective_from'] ?? now());
            if ($effectiveFrom->isFuture()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'A current ownership change cannot start in the future.',
                ]);
            }

            $current = AssetOwnership::query()
                ->where('asset_id', $asset->id)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();
            if ($current?->effective_from && $effectiveFrom->isBefore($current->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_from' => 'The ownership change cannot predate the current ownership period.',
                ]);
            }
            if ($current
                && $current->owner_type === $data['owner_type']
                && (int) $current->owner_id === $ownerId) {
                throw ValidationException::withMessages([
                    'owner_id' => 'This is already the current Asset owner.',
                ]);
            }
            $current?->update(['effective_to' => $effectiveFrom]);

            $ownership = AssetOwnership::query()->create([
                'asset_id' => $asset->id,
                'owner_type' => $data['owner_type'],
                'owner_id' => $ownerId,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'notes' => $data['notes'] ?? null,
            ]);

            AuditLogger::logOrFail('assets.ownership.changed', $asset, [
                'ownership_id' => $ownership->id,
            ]);

            return $ownership;
        }, 3);
    }

    private function assetCanBelongToSite(Asset $asset, int $siteId): bool
    {
        $asset->loadMissing('client:id,site_id');
        $clientSiteId = is_numeric($asset->client?->site_id) ? (int) $asset->client->site_id : null;

        if (is_numeric($asset->site_id)) {
            return (int) $asset->site_id === $siteId
                && ($clientSiteId === null || $clientSiteId === $siteId);
        }
        if (is_numeric($asset->home_site_id)) {
            return (int) $asset->home_site_id === $siteId
                && ($clientSiteId === null || $clientSiteId === $siteId);
        }

        return $clientSiteId === $siteId;
    }
}
