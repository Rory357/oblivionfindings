<?php

namespace App\Jobs\Integration;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\SiteRoom;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HardwareReconciliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $siteId,
    ) {}

    /**
     * Cross-check canonical devices against assets and report discrepancies.
     * Now reads from the Security & Devices canonical device registry.
     */
    public function handle(): array
    {
        // Devices in domains that typically should have asset links.
        $deviceDomains = ['security', 'it_infrastructure', 'tracking'];
        $roomIds = SiteRoom::query()
            ->where('site_id', $this->siteId)
            ->pluck('id')
            ->all();

        // Find canonically Site-assigned devices without an active asset link.
        $unlinkedDevices = Device::query()
            ->whereHas('assignments', function (Builder $assignment) use ($roomIds): void {
                $assignment->active()
                    ->where(function (Builder $siteScope) use ($roomIds): void {
                        $siteScope->where(function (Builder $directSite): void {
                            $directSite
                                ->where('assignable_type', DeviceAssignment::TARGET_SITE)
                                ->where('assignable_id', $this->siteId);
                        })->orWhere(function (Builder $room) use ($roomIds): void {
                            $room
                                ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
                                ->whereIn('assignable_id', $roomIds === [] ? [-1] : $roomIds);
                        });
                    });
            })
            ->whereIn('domain', $deviceDomains)
            ->whereDoesntHave('activeAssetLinks')
            ->get();

        // Find canonically Site-owned assets that should have linked devices.
        $assetCategories = ['Camera', 'Access Control', 'Sensor', 'NVR', 'Tracker', 'IT Equipment'];
        $linkedAssetIds = DeviceAssetLink::query()
            ->active()
            ->pluck('asset_id')
            ->toArray();

        $unlinkedAssets = Asset::whereIn('category', $assetCategories)
            ->where(function (Builder $siteScope): void {
                $siteScope
                    ->where('site_id', $this->siteId)
                    ->orWhere('home_site_id', $this->siteId)
                    ->orWhereHas('client', fn (Builder $client): Builder => $client->where('site_id', $this->siteId));
            })
            ->whereNotIn('id', $linkedAssetIds)
            ->get();

        Log::info('Hardware reconciliation completed for Site.', [
            'site_id' => $this->siteId,
            'unlinked_device_count' => $unlinkedDevices->count(),
            'unlinked_asset_count' => $unlinkedAssets->count(),
        ]);

        return [
            'unlinked_device_count' => $unlinkedDevices->count(),
            'unlinked_asset_count' => $unlinkedAssets->count(),
            'unlinked_device_ids' => $unlinkedDevices->pluck('id')->toArray(),
            'unlinked_asset_ids' => $unlinkedAssets->pluck('id')->toArray(),
        ];
    }
}
