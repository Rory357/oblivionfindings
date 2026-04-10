<?php

namespace App\Jobs;

use App\Models\ControlRoom\Device;
use App\Services\Facility\FacilitySignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Detect offline Control Room devices (non-fleet).
 *
 * Fleet vehicle trackers are handled by DetectFleetOfflineDevices.
 * Integration devices (Gallagher, Hikvision, etc.) are handled by IntegrationSignalNormaliser.
 *
 * This job monitors CR-registered devices: bed sensors, cameras, alarm panels,
 * environmental sensors, door controllers, and network devices.
 *
 * Runs every 5 minutes. Emits signals for devices that have been stale (no heartbeat)
 * for more than 30 minutes.
 */
class DetectCrDeviceOfflineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const STALE_THRESHOLD_MINUTES = 30;

    public function handle(FacilitySignalService $signalService): void
    {
        // Find devices currently marked online but not seen recently.
        // Exclude vehicle_tracker type (handled by DetectFleetOfflineDevices).
        $staleDevices = Device::query()
            ->where('status', 'online')
            ->where('type', '!=', Device::TYPE_VEHICLE_TRACKER)
            ->where(function ($q) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes(self::STALE_THRESHOLD_MINUTES));
            })
            ->get();

        $detectedCount = 0;

        foreach ($staleDevices as $device) {
            $minutesOffline = $device->last_seen_at
                ? (int) $device->last_seen_at->diffInMinutes(now())
                : self::STALE_THRESHOLD_MINUTES;

            // Mark device as offline
            $device->markOffline();

            // Emit signal → Control Room
            $signalService->emitDeviceOffline($device, $minutesOffline);
            $detectedCount++;
        }

        if ($detectedCount > 0) {
            Log::info('DetectCrDeviceOfflineJob: completed', [
                'devices_checked' => $staleDevices->count(),
                'marked_offline' => $detectedCount,
            ]);
        }
    }
}
