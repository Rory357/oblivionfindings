<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleStateSnapshot;
use App\Notifications\Fleet\FleetVehicleOverdueNotification;
use App\Services\Fleet\FleetSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FleetAutoAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 2;

    public function handle(FleetSignalService $signalService): void
    {
        try {
            // Offline detection handled by DetectFleetOfflineDevices job
            $this->checkOverdueBookings($signalService);
            $this->checkWofExpiring($signalService);
            $this->checkRegistrationExpiring($signalService);
            $this->checkMaintenanceOverdue($signalService);
            $this->checkLowBattery($signalService);
        } catch (\Throwable $e) {
            \Log::error('FleetAutoAlertJob failed: ' . $e->getMessage(), ['exception' => $e]);
            throw $e;
        }
    }

    private function checkOverdueBookings(FleetSignalService $signalService): void
    {
        $overdueBookings = FleetVehicleBooking::query()
            ->where('status', 'checked_out')
            ->where('ends_at', '<', now())
            ->with(['asset:id,name', 'user'])
            ->get();

        foreach ($overdueBookings as $booking) {
            if (!$booking->asset || !$booking->user) continue;

            $hoursOverdue = $booking->ends_at->diffInHours(now());
            // PR7: Vehicle overdue is operationally urgent but not life-safety
            $severity = $hoursOverdue >= 4 ? 'high' : 'medium';

            $signalService->emit([
                'asset_id' => $booking->asset_id,
                'signal_type' => 'vehicle_overdue',
                'severity_hint' => $severity,
                'occurred_at' => now(),
                'idempotency_key' => hash('sha256', "overdue|{$booking->id}|" . now()->toDateString()),
                'payload' => [
                    'booking_id' => $booking->id,
                    'ends_at' => $booking->ends_at->toISOString(),
                    'hours_overdue' => $hoursOverdue,
                ],
            ]);

            $booking->user->notify(new FleetVehicleOverdueNotification($booking));
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
                // PR7: Compliance expiry is high urgency, not life-safety critical
                $severity = $days <= 7 ? 'high' : 'medium';

                $signalService->emit([
                    'asset_id' => $asset->id,
                    'signal_type' => 'wof_expiring',
                    'severity_hint' => $severity,
                    'occurred_at' => now(),
                    'payload' => [
                        'expires_at' => $asset->wof_expires_at->toDateString(),
                        'days_remaining' => $days,
                    ],
                ]);
            }
        }

        // Expired WOF
        $expired = Asset::query()
            ->whereNotNull('wof_expires_at')
            ->where('wof_expires_at', '<', now())
            ->where('status', 'active')
            ->get(['id', 'name', 'wof_expires_at']);

        foreach ($expired as $asset) {
            $signalService->emit([
                'asset_id' => $asset->id,
                'signal_type' => 'wof_expired',
                'severity_hint' => 'high', // PR7: expired WoF is compliance issue, not life-safety
                'occurred_at' => now(),
                'payload' => [
                    'expired_at' => $asset->wof_expires_at->toDateString(),
                    'days_overdue' => $asset->wof_expires_at->diffInDays(now()),
                ],
            ]);
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
                // PR7: Compliance expiry is high urgency, not life-safety critical
                $severity = $days <= 7 ? 'high' : 'medium';

                $signalService->emit([
                    'asset_id' => $asset->id,
                    'signal_type' => 'registration_expiring',
                    'severity_hint' => $severity,
                    'occurred_at' => now(),
                    'payload' => [
                        'expires_at' => $asset->registration_expires_at->toDateString(),
                        'days_remaining' => $days,
                    ],
                ]);
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
            $signalService->emit([
                'asset_id' => $asset->id,
                'signal_type' => 'maintenance_overdue',
                'severity_hint' => 'high',
                'occurred_at' => now(),
                'payload' => [
                    'due_at' => $asset->maintenance_due_at->toDateString(),
                    'days_overdue' => $asset->maintenance_due_at->diffInDays(now()),
                ],
            ]);
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

            $signalService->emit([
                'asset_id' => $state->asset_id,
                'signal_type' => 'low_battery',
                // PR7: Low battery is operational, not life-safety
                'severity_hint' => $state->battery_pct < 5 ? 'medium' : 'low',
                'occurred_at' => now(),
                'payload' => [
                    'battery_pct' => $state->battery_pct,
                ],
            ]);
        }
    }
}
