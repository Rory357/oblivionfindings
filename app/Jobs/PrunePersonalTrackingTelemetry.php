<?php

namespace App\Jobs;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkRawFrame;
use App\Services\AuditLogger;
use App\Support\SafeOperationalData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

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
        $rawFrameRetentionDays = $this->rawFrameRetentionDays();
        $canPruneRawFrames = $this->canPruneRawFrames();

        $totals = [
            'fleet_events_deleted' => 0,
            'asset_snapshots_deleted' => 0,
            'integration_events_deleted' => 0,
            'asset_summaries_redacted' => 0,
            'queclink_raw_frames_deleted' => 0,
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
            ->each(function (DeviceAssignment $assignment) use (&$totals, $canPruneRawFrames): void {
                $counts = $this->enforceAssignment($assignment, $canPruneRawFrames);

                foreach ($counts as $key => $count) {
                    $totals[$key] += $count;
                }
            });

        if ($canPruneRawFrames) {
            $totals['queclink_raw_frames_deleted'] += $this->pruneRawFramesOlderThan(
                now()->subDays($rawFrameRetentionDays),
            );
        } elseif (Schema::hasTable('queclink_raw_frames')) {
            Log::warning('Queclink raw frame retention skipped fail closed.', SafeOperationalData::logContext([
                'provider' => 'queclink',
                'failure_category' => 'legal_hold_boundary_unavailable',
                'items_errored' => 1,
            ]));
        }

        Log::info('Personal tracking retention enforcement completed.', $totals);
    }

    /** @return array<string, int> */
    private function enforceAssignment(DeviceAssignment $assignment, bool $canPruneRawFrames): array
    {
        $counts = [
            'fleet_events_deleted' => 0,
            'asset_snapshots_deleted' => 0,
            'integration_events_deleted' => 0,
            'asset_summaries_redacted' => 0,
            'queclink_raw_frames_deleted' => 0,
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
            $canPruneRawFrames,
            &$counts,
        ): void {
            if ($canPruneRawFrames) {
                $query = DB::table('queclink_raw_frames')
                    ->where('canonical_device_id', $device->id)
                    ->where('device_assignment_id', $assignment->id)
                    ->where('created_at', '>=', $assignmentStart)
                    ->where('created_at', '<', $deletionEnd);
                $this->excludeActiveRawFrameHolds($query, (int) $assignment->id);
                $counts['queclink_raw_frames_deleted'] = $query->delete();
            }

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

    private function rawFrameRetentionDays(): int
    {
        $value = config('services.queclink.listener.raw_frame_retention_days', 30);
        if (! is_int($value) && (! is_string($value) || preg_match('/^\d+$/', $value) !== 1)) {
            throw new InvalidArgumentException('Invalid Queclink raw frame retention limit.');
        }

        $days = (int) $value;
        if ($days < 1 || $days > 365) {
            throw new InvalidArgumentException('Invalid Queclink raw frame retention limit.');
        }

        return $days;
    }

    private function canPruneRawFrames(): bool
    {
        return Schema::hasTable('queclink_raw_frames')
            && Schema::hasTable('queclink_devices')
            && Schema::hasTable('device_assignments')
            && Schema::hasTable('legal_holds')
            && Schema::hasColumn('queclink_raw_frames', 'canonical_device_id')
            && Schema::hasColumn('queclink_raw_frames', 'device_assignment_id')
            && Schema::hasColumn('queclink_raw_frames', 'binding_uuid');
    }

    private function pruneRawFramesOlderThan(Carbon $cutoff): int
    {
        $deleted = 0;

        do {
            $query = DB::table('queclink_raw_frames')
                ->where('created_at', '<', $cutoff)
                ->limit(1000);
            $this->excludeActiveRawFrameHolds($query);
            $batch = $query->delete();
            $deleted += $batch;
        } while ($batch === 1000);

        return $deleted;
    }

    private function excludeActiveRawFrameHolds(Builder $frames, ?int $assignmentId = null): void
    {
        $rawFrameTypes = $this->morphTypes(QueclinkRawFrame::class);
        $providerDeviceTypes = $this->morphTypes(QueclinkDevice::class);
        $deviceTypes = $this->morphTypes(Device::class);
        $assignmentTypes = $this->morphTypes(DeviceAssignment::class);

        $frames->whereNotExists(function (Builder $holds) use (
            $rawFrameTypes,
            $providerDeviceTypes,
            $deviceTypes,
            $assignmentTypes,
            $assignmentId,
        ): void {
            $holds->selectRaw('1')
                ->from('legal_holds')
                ->where('status', 'active')
                ->where(function (Builder $held) use (
                    $rawFrameTypes,
                    $providerDeviceTypes,
                    $deviceTypes,
                    $assignmentTypes,
                    $assignmentId,
                ): void {
                    $held->where(function (Builder $direct) use ($rawFrameTypes): void {
                        $direct->whereIn('holdable_type', $rawFrameTypes)
                            ->whereColumn('holdable_id', 'queclink_raw_frames.id');
                    })->orWhere(function (Builder $providerDevice) use ($providerDeviceTypes): void {
                        $providerDevice->whereIn('holdable_type', $providerDeviceTypes)
                            ->whereColumn('holdable_id', 'queclink_raw_frames.queclink_device_id');
                    })->orWhere(function (Builder $canonicalDevice) use ($deviceTypes): void {
                        $canonicalDevice->whereIn('holdable_type', $deviceTypes)
                            ->whereColumn('holdable_id', 'queclink_raw_frames.canonical_device_id');
                    })->orWhere(function (Builder $assignment) use ($assignmentTypes, $assignmentId): void {
                        $assignment->whereIn('holdable_type', $assignmentTypes);

                        if ($assignmentId !== null) {
                            $assignment->where('holdable_id', $assignmentId);

                            return;
                        }

                        $assignment->whereColumn('holdable_id', 'queclink_raw_frames.device_assignment_id');
                    });
                });
        });
    }

    /** @return list<string> */
    private function morphTypes(string $modelClass): array
    {
        return array_values(array_unique([
            $modelClass,
            Relation::getMorphAlias($modelClass),
        ]));
    }
}
