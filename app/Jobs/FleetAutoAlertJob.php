<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\FleetVehicleStateSnapshot;
use App\Services\Fleet\FleetSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FleetAutoAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FleetSignalService $signalService): void
    {
        try {
            $this->checkOfflineVehicles($signalService);
            $this->checkWofExpiring($signalService);
            $this->checkRegistrationExpiring($signalService);
            $this->checkMaintenanceOverdue($signalService);
            $this->checkLowBattery($signalService);
        } catch (\Throwable $e) {
            \Log::error('FleetAutoAlertJob failed: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

    private function checkOfflineVehicles(FleetSignalService $signalService): void
    {
        $threshold = now()->subHours(2);

        $offlineStates = FleetVehicleStateSnapshot::query()
            ->where('status', 'online')
            ->where('last_seen_at', '<', $threshold)
            ->with('asset:id,name')
            ->get();

        foreach ($offlineStates as $state) {
            if (!$state->asset) continue;

            $signalService->emit(
                assetId: $state->asset_id,
                signalType: 'vehicle_offline',
                severityHint: 'medium',
                occurredAt: now(),
                context: [
                    'last_seen_at' => $state->last_seen_at?->toISOString(),
                    'hours_offline' => $state->last_seen_at?->diffInHours(now()),
                ],
            );

            $state->update(['status' => 'offline']);
        }
    }

    private function checkWofExpiring(FleetSignalService $signalService): void
    {
        // Alert at 14 days, 7 days, and 1 day before expiry
        $thresholds = [14, 7, 1];

        foreach ($thresholds as $days) {
            $targetDate = now()->addDays($days)->toDateString();

            $assets = Asset::query()
                ->whereNotNull('wof_expires_at')
                ->whereDate('wof_expires_at', $targetDate)
                ->get(['id', 'name', 'wof_expires_at']);

            foreach ($assets as $asset) {
                $severity = $days <= 1 ? 'critical' : ($days <= 7 ? 'high' : 'medium');

                $signalService->emit(
                    assetId: $asset->id,
                    signalType: 'wof_expiring',
                    severityHint: $severity,
                    occurredAt: now(),
                    context: [
                        'expires_at' => $asset->wof_expires_at->toDateString(),
                        'days_remaining' => $days,
                    ],
                );
            }
        }

        // Expired WOF
        $expired = Asset::query()
            ->whereNotNull('wof_expires_at')
            ->where('wof_expires_at', '<', now())
            ->where('status', 'active')
            ->get(['id', 'name', 'wof_expires_at']);

        foreach ($expired as $asset) {
            $signalService->emit(
                assetId: $asset->id,
                signalType: 'wof_expired',
                severityHint: 'critical',
                occurredAt: now(),
                context: [
                    'expired_at' => $asset->wof_expires_at->toDateString(),
                    'days_overdue' => $asset->wof_expires_at->diffInDays(now()),
                ],
            );
        }
    }

    private function checkRegistrationExpiring(FleetSignalService $signalService): void
    {
        $thresholds = [30, 14, 7, 1];

        foreach ($thresholds as $days) {
            $targetDate = now()->addDays($days)->toDateString();

            $assets = Asset::query()
                ->whereNotNull('registration_expires_at')
                ->whereDate('registration_expires_at', $targetDate)
                ->get(['id', 'name', 'registration_expires_at']);

            foreach ($assets as $asset) {
                $severity = $days <= 1 ? 'critical' : ($days <= 7 ? 'high' : 'medium');

                $signalService->emit(
                    assetId: $asset->id,
                    signalType: 'registration_expiring',
                    severityHint: $severity,
                    occurredAt: now(),
                    context: [
                        'expires_at' => $asset->registration_expires_at->toDateString(),
                        'days_remaining' => $days,
                    ],
                );
            }
        }
    }

    private function checkMaintenanceOverdue(FleetSignalService $signalService): void
    {
        $overdue = Asset::query()
            ->where('requires_maintenance', true)
            ->whereNotNull('maintenance_due_at')
            ->where('maintenance_due_at', '<', now())
            ->where('status', 'active')
            ->get(['id', 'name', 'maintenance_due_at']);

        foreach ($overdue as $asset) {
            $signalService->emit(
                assetId: $asset->id,
                signalType: 'maintenance_overdue',
                severityHint: 'high',
                occurredAt: now(),
                context: [
                    'due_at' => $asset->maintenance_due_at->toDateString(),
                    'days_overdue' => $asset->maintenance_due_at->diffInDays(now()),
                ],
            );
        }
    }

    private function checkLowBattery(FleetSignalService $signalService): void
    {
        $lowBattery = FleetVehicleStateSnapshot::query()
            ->where('status', 'online')
            ->whereNotNull('battery_pct')
            ->where('battery_pct', '<', 15)
            ->with('asset:id,name')
            ->get();

        foreach ($lowBattery as $state) {
            if (!$state->asset) continue;

            $signalService->emit(
                assetId: $state->asset_id,
                signalType: 'low_battery',
                severityHint: $state->battery_pct < 5 ? 'critical' : 'medium',
                occurredAt: now(),
                context: [
                    'battery_pct' => $state->battery_pct,
                ],
            );
        }
    }
}
