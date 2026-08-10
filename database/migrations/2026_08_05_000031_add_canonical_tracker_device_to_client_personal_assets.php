<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_personal_assets', function (Blueprint $table): void {
            $table->foreignId('tracker_device_id')
                ->nullable()
                ->after('tracker_hardware_id')
                ->constrained('devices')
                ->nullOnDelete();
        });

        // Retain the old LocationHardware pointer as immutable compatibility
        // evidence, and backfill only unambiguous canonical Device matches.
        DB::table('client_personal_assets')
            ->whereNull('tracker_device_id')
            ->whereNotNull('tracker_hardware_id')
            ->orderBy('id')
            ->chunkById(200, function ($assets): void {
                foreach ($assets as $asset) {
                    $deviceIds = DB::table('devices')
                        ->where('legacy_location_hardware_id', $asset->tracker_hardware_id)
                        ->orderBy('id')
                        ->limit(2)
                        ->pluck('id');

                    if ($deviceIds->count() !== 1) {
                        continue;
                    }

                    DB::table('client_personal_assets')
                        ->where('id', $asset->id)
                        ->whereNull('tracker_device_id')
                        ->update([
                            'tracker_device_id' => $deviceIds->first(),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('client_personal_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tracker_device_id');
        });
    }
};
