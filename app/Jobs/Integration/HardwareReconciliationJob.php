<?php

namespace App\Jobs\Integration;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HardwareReconciliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
    ) {}

    /**
     * Cross-check canonical devices against assets and report discrepancies.
     * Now reads from the Security & Devices canonical device registry.
     */
    public function handle(): array
    {
        // Devices in domains that typically should have asset links.
        $deviceDomains = ['security', 'it_infrastructure', 'tracking'];

        // Find devices without any active asset link.
        $unlinkedDevices = Device::query()
            ->forTenant($this->tenantId)
            ->whereIn('domain', $deviceDomains)
            ->whereDoesntHave('activeAssetLinks')
            ->get();

        // Find assets in categories that typically should have linked devices.
        $assetCategories = ['Camera', 'Access Control', 'Sensor', 'NVR', 'Tracker', 'IT Equipment'];
        $unlinkedAssets = Asset::whereIn('category', $assetCategories)
            ->whereDoesntHave('deviceLinks', function ($q) {
                // Note: Asset model needs a deviceLinks() relationship.
                // For now, query directly.
            })
            ->get();

        // Fallback: query device_asset_links directly if Asset doesn't have the relationship yet.
        $linkedAssetIds = DeviceAssetLink::query()
            ->active()
            ->pluck('asset_id')
            ->toArray();

        $unlinkedAssets = Asset::whereIn('category', $assetCategories)
            ->whereNotIn('id', $linkedAssetIds)
            ->get();

        Log::info("Reconciliation for tenant {$this->tenantId}: {$unlinkedDevices->count()} unlinked devices, {$unlinkedAssets->count()} unlinked assets");

        return [
            'unlinked_device_count' => $unlinkedDevices->count(),
            'unlinked_asset_count' => $unlinkedAssets->count(),
            'unlinked_device_ids' => $unlinkedDevices->pluck('id')->toArray(),
            'unlinked_asset_ids' => $unlinkedAssets->pluck('id')->toArray(),
        ];
    }
}
