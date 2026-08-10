<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queclink_devices', function (Blueprint $table): void {
            $table->uuid('binding_uuid')->nullable()->unique()->after('device_id');
        });

        Schema::table('queclink_raw_frames', function (Blueprint $table): void {
            $table->dropForeign(['queclink_device_id']);
            $table->foreignId('canonical_device_id')
                ->nullable()
                ->after('queclink_device_id')
                ->constrained('devices')
                ->restrictOnDelete();
            $table->foreignId('device_assignment_id')
                ->nullable()
                ->after('canonical_device_id')
                ->constrained('device_assignments')
                ->restrictOnDelete();
            $table->uuid('binding_uuid')->nullable()->after('device_assignment_id')->index();
            $table->foreign('queclink_device_id')
                ->references('id')
                ->on('queclink_devices')
                ->restrictOnDelete();
        });

        DB::table('queclink_devices')
            ->where('status', 'paired')
            ->whereNotNull('device_id')
            ->orderBy('id')
            ->chunkById(500, function ($devices): void {
                foreach ($devices as $device) {
                    DB::table('queclink_devices')
                        ->where('id', $device->id)
                        ->whereNull('binding_uuid')
                        ->update(['binding_uuid' => (string) Str::uuid()]);
                }
            });

        // Existing frames have no binding history. Retaining their current
        // canonical projection is deliberately conservative: a later release
        // or re-pair cannot erase an active Device hold from those rows.
        DB::statement(<<<'SQL'
            UPDATE queclink_raw_frames AS frames
            INNER JOIN queclink_devices AS provider_devices
                ON provider_devices.id = frames.queclink_device_id
            SET frames.canonical_device_id = provider_devices.device_id,
                frames.binding_uuid = provider_devices.binding_uuid
            WHERE provider_devices.device_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            UPDATE queclink_raw_frames AS frames
            SET frames.device_assignment_id = (
                SELECT assignments.id
                FROM device_assignments AS assignments
                WHERE assignments.device_id = frames.canonical_device_id
                  AND assignments.assigned_at <= frames.created_at
                  AND (
                      assignments.released_at IS NULL
                      OR assignments.released_at >= frames.created_at
                  )
                ORDER BY assignments.assigned_at DESC, assignments.id DESC
                LIMIT 1
            )
            WHERE frames.canonical_device_id IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        Schema::table('queclink_raw_frames', function (Blueprint $table): void {
            $table->dropForeign(['queclink_device_id']);
            $table->dropForeign(['canonical_device_id']);
            $table->dropForeign(['device_assignment_id']);
            $table->dropIndex(['binding_uuid']);
            $table->dropColumn([
                'canonical_device_id',
                'device_assignment_id',
                'binding_uuid',
            ]);
            $table->foreign('queclink_device_id')
                ->references('id')
                ->on('queclink_devices')
                ->nullOnDelete();
        });

        Schema::table('queclink_devices', function (Blueprint $table): void {
            $table->dropUnique(['binding_uuid']);
            $table->dropColumn('binding_uuid');
        });
    }
};
