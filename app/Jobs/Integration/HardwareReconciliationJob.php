<?php

namespace App\Jobs\Integration;

use App\Models\Asset;
use App\Models\LocationHardware;
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
     * Cross-check hardware records against assets and report discrepancies.
     */
    public function handle(): array
    {
        // Hardware categories that should have linked assets
        $hardwareCategories = ['camera', 'door', 'sensor', 'nvr', 'tracker'];

        // Asset categories that should have linked hardware
        $assetCategories = ['Camera', 'Access Control', 'Sensor', 'NVR', 'Tracker'];

        // Find unlinked hardware (hardware without a linked asset)
        $unlinkedHardware = LocationHardware::whereNull('linked_asset_id')
            ->whereIn('category', $hardwareCategories)
            ->where('tenant_id', $this->tenantId)
            ->get();

        // Find unlinked assets (assets without linked hardware)
        $unlinkedAssets = Asset::whereIn('category', $assetCategories)
            ->where('tenant_id', $this->tenantId)
            ->whereDoesntHave('linkedHardware')
            ->get();

        Log::info("Reconciliation for tenant {$this->tenantId}: {$unlinkedHardware->count()} unlinked hardware, {$unlinkedAssets->count()} unlinked assets");

        return [
            'unlinked_hardware_count' => $unlinkedHardware->count(),
            'unlinked_asset_count' => $unlinkedAssets->count(),
            'unlinked_hardware_ids' => $unlinkedHardware->pluck('id')->toArray(),
            'unlinked_asset_ids' => $unlinkedAssets->pluck('id')->toArray(),
        ];
    }
}
