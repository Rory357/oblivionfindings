<?php

namespace App\Jobs;

use App\Models\AssetTelemetryHistory;
use App\Models\AssetTelemetrySnapshot;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SummarizeAssetTelemetry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?string $summaryDate = null
    ) {}

    public function handle(): void
    {
        $date = $this->summaryDate ? Carbon::parse($this->summaryDate)->toDateString() : now()->subDay()->toDateString();

        $snapshots = AssetTelemetrySnapshot::query()
            ->whereDate('occurred_at', $date)
            ->where('consent_blocked', false)
            ->orderBy('asset_id')
            ->orderBy('occurred_at')
            ->get();

        $grouped = $snapshots->groupBy('asset_id');

        foreach ($grouped as $assetId => $rows) {
            $distanceKm = 0.0;
            $batteryMin = null;
            $batteryMax = null;
            $lastLat = null;
            $lastLon = null;
            $timeMoving = 0;

            $prev = null;
            foreach ($rows as $row) {
                if ($prev && $row->latitude !== null && $row->longitude !== null && $prev->latitude !== null && $prev->longitude !== null) {
                    $distanceKm += $this->distanceMeters($prev->latitude, $prev->longitude, $row->latitude, $row->longitude) / 1000;
                }

                if ($row->battery_pct !== null) {
                    $batteryMin = $batteryMin === null ? $row->battery_pct : min($batteryMin, $row->battery_pct);
                    $batteryMax = $batteryMax === null ? $row->battery_pct : max($batteryMax, $row->battery_pct);
                }

                if ($row->movement_status === 'moving') {
                    $timeMoving++;
                }

                $lastLat = $row->latitude ?? $lastLat;
                $lastLon = $row->longitude ?? $lastLon;
                $prev = $row;
            }

            AssetTelemetryHistory::updateOrCreate(
                ['asset_id' => $assetId, 'summary_date' => $date],
                [
                    'distance_km' => round($distanceKm, 3),
                    'time_moving_minutes' => $timeMoving,
                    'last_latitude' => $lastLat,
                    'last_longitude' => $lastLon,
                    'battery_min' => $batteryMin,
                    'battery_max' => $batteryMax,
                    'alerts_count' => 0,
                ]
            );
        }
    }

    protected function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}
