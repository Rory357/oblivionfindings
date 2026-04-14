<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('integration_events')) {
            return;
        }

        if (!Schema::hasColumn('integration_events', 'canonical_device_id')) {
            Schema::table('integration_events', function (Blueprint $table) {
                $table->foreignId('canonical_device_id')
                    ->nullable()
                    ->after('hardware_id')
                    ->constrained('devices')
                    ->nullOnDelete();

                $table->index(
                    ['canonical_device_id', 'created_at'],
                    'integration_events_canonical_created_idx'
                );
            });
        }

        if (!Schema::hasTable('devices')) {
            return;
        }

        DB::table('integration_events')
            ->select(['id', 'hardware_id'])
            ->whereNull('canonical_device_id')
            ->whereNotNull('hardware_id')
            ->orderBy('id')
            ->chunkById(500, function ($events): void {
                $hardwareIds = collect($events)
                    ->pluck('hardware_id')
                    ->filter()
                    ->unique()
                    ->values();

                if ($hardwareIds->isEmpty()) {
                    return;
                }

                $deviceIdsByLegacyHardware = DB::table('devices')
                    ->select(['id', 'legacy_location_hardware_id'])
                    ->whereIn('legacy_location_hardware_id', $hardwareIds)
                    ->get()
                    ->groupBy('legacy_location_hardware_id');

                foreach ($events as $event) {
                    $matches = $deviceIdsByLegacyHardware->get($event->hardware_id);

                    // Be conservative: only backfill when the legacy hardware row
                    // maps to exactly one canonical device.
                    if (!$matches || $matches->count() !== 1) {
                        continue;
                    }

                    DB::table('integration_events')
                        ->where('id', $event->id)
                        ->update([
                            'canonical_device_id' => $matches->first()->id,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('integration_events') || !Schema::hasColumn('integration_events', 'canonical_device_id')) {
            return;
        }

        Schema::table('integration_events', function (Blueprint $table) {
            $table->dropIndex('integration_events_canonical_created_idx');
            $table->dropConstrainedForeignId('canonical_device_id');
        });
    }
};
