<?php

namespace App\Jobs;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce the retention period recorded on each personal-tracker assignment.
 *
 * Historical safety evidence remains available until its assignment-specific
 * cutoff. After the cutoff, raw location rows are deleted and asset summaries
 * retain operational totals without their last known coordinates.
 */
class PrunePersonalTrackingTelemetry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function handle(): void
    {
        $totals = [
            'fleet_events_deleted' => 0,
            'asset_snapshots_deleted' => 0,
            'integration_events_deleted' => 0,
            'asset_summaries_redacted' => 0,
        ];

        DeviceAssignment::query()
            ->with([
                'device:id,domain,legacy_asset_tracker_id,legacy_location_hardware_id',
                'device.assetLinks:id,device_id,asset_id,linked_at,unlinked_at',
            ])
            ->whereIn('assignable_type', [
                DeviceAssignment::TARGET_CLIENT,
                DeviceAssignment::TARGET_STAFF,
            ])
            ->whereNotNull('retention_days')
            ->whereHas('device', fn ($query) => $query->where('domain', 'tracking'))
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (DeviceAssignment $assignment) use (&$totals): void {
                $counts = $this->enforceAssignment($assignment);

                foreach ($counts as $key => $count) {
                    $totals[$key] += $count;
                }
            });

        Log::info('Personal tracking retention enforcement completed.', $totals);
    }

    /** @return array<string, int> */
    private function enforceAssignment(DeviceAssignment $assignment): array
    {
        $counts = [
            'fleet_events_deleted' => 0,
            'asset_snapshots_deleted' => 0,
            'integration_events_deleted' => 0,
            'asset_summaries_redacted' => 0,
        ];
        $device = $assignment->device;
        if (! $device || ! $assignment->assigned_at) {
            return $counts;
        }

        $cutoff = now()->subDays(max(1, (int) $assignment->retention_days));
        $assignmentStart = Carbon::parse($assignment->assigned_at);
        if ($cutoff->lessThanOrEqualTo($assignmentStart)) {
            return $counts;
        }

        $assignmentEnd = collect([
            $assignment->collection_stopped_at,
            $assignment->released_at,
            now(),
        ])->filter()->map(fn ($value) => Carbon::parse($value))->sort()->first();
        $deletionEnd = $assignmentEnd->lessThan($cutoff) ? $assignmentEnd : $cutoff;

        DB::transaction(function () use (
            $assignment,
            $device,
            $assignmentStart,
            $deletionEnd,
            &$counts,
        ): void {
            if (Schema::hasTable('fleet_telemetry_events')) {
                $counts['fleet_events_deleted'] = DB::table('fleet_telemetry_events')
                    ->where(fn (Builder $query) => $this->whereDeviceLineage(
                        $query,
                        (int) $device->id,
                        $device->legacy_asset_tracker_id,
                    ))
                    ->where('occurred_at', '>=', $assignmentStart)
                    ->where('occurred_at', '<', $deletionEnd)
                    ->delete();
            }

            if (Schema::hasTable('asset_telemetry_snapshots')) {
                $counts['asset_snapshots_deleted'] = DB::table('asset_telemetry_snapshots')
                    ->where(fn (Builder $query) => $this->whereDeviceLineage(
                        $query,
                        (int) $device->id,
                        $device->legacy_asset_tracker_id,
                    ))
                    ->where('occurred_at', '>=', $assignmentStart)
                    ->where('occurred_at', '<', $deletionEnd)
                    ->delete();
            }

            if (Schema::hasTable('integration_events')
                && Schema::hasColumn('integration_events', 'canonical_device_id')) {
                $counts['integration_events_deleted'] = DB::table('integration_events')
                    ->where(function (Builder $query) use ($device): void {
                        $query->where('canonical_device_id', $device->id);

                        if ($device->legacy_location_hardware_id) {
                            $query->orWhere(function (Builder $legacy) use ($device): void {
                                $legacy->whereNull('canonical_device_id')
                                    ->where('hardware_id', $device->legacy_location_hardware_id);
                            });
                        }
                    })
                    ->where('occurred_at', '>=', $assignmentStart)
                    ->where('occurred_at', '<', $deletionEnd)
                    ->delete();
            }

            if (Schema::hasTable('asset_telemetry_histories')) {
                $assetIds = $device->assetLinks
                    ->filter(fn ($link): bool => Carbon::parse($link->linked_at)->lessThanOrEqualTo($deletionEnd)
                        && ($link->unlinked_at === null
                            || Carbon::parse($link->unlinked_at)->greaterThanOrEqualTo($assignmentStart)))
                    ->pluck('asset_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values();

                if ($assetIds->isNotEmpty()) {
                    $counts['asset_summaries_redacted'] = DB::table('asset_telemetry_histories')
                        ->whereIn('asset_id', $assetIds->all())
                        ->where('summary_date', '>=', $assignmentStart->toDateString())
                        ->where('summary_date', '<', $deletionEnd->toDateString())
                        ->where(fn (Builder $query) => $query
                            ->whereNotNull('last_latitude')
                            ->orWhereNotNull('last_longitude'))
                        ->update([
                            'last_latitude' => null,
                            'last_longitude' => null,
                            'updated_at' => now(),
                        ]);
                }
            }

            if (array_sum($counts) > 0) {
                AuditLogger::logOrFail('tracking.retention.enforced', $assignment, [
                    'assignment_id' => $assignment->id,
                    'device_id' => $device->id,
                    'subject_type' => $assignment->assignable_type,
                    'subject_id' => $assignment->assignable_id,
                    'retention_days' => $assignment->retention_days,
                    'cutoff' => $deletionEnd->toISOString(),
                    ...$counts,
                ]);
            }
        });

        return $counts;
    }

    private function whereDeviceLineage(
        Builder $query,
        int $deviceId,
        ?int $legacyTrackerId,
    ): void {
        $query->where('device_id', $deviceId);

        if ($legacyTrackerId) {
            $query->orWhere(function (Builder $legacy) use ($legacyTrackerId): void {
                $legacy->whereNull('device_id')
                    ->where('asset_tracker_id', $legacyTrackerId);
            });
        }
    }
}
