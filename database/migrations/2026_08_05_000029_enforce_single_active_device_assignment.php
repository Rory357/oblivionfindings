<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->releaseDuplicateActiveAssignments();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX device_assignments_one_active_device_uq '
                .'ON device_assignments (device_id) WHERE released_at IS NULL',
            );

            return;
        }

        Schema::table('device_assignments', function (Blueprint $table): void {
            // MySQL allows multiple NULL values in a unique index. Released
            // history therefore remains unrestricted while an active row
            // exposes its device id and is unique per device.
            $table->unsignedBigInteger('active_device_id')
                ->nullable()
                ->storedAs('CASE WHEN released_at IS NULL THEN device_id ELSE NULL END')
                ->after('device_id');
            $table->unique('active_device_id', 'device_assignments_one_active_device_uq');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS device_assignments_one_active_device_uq');

            return;
        }

        Schema::table('device_assignments', function (Blueprint $table): void {
            $table->dropUnique('device_assignments_one_active_device_uq');
            $table->dropColumn('active_device_id');
        });
    }

    private function releaseDuplicateActiveAssignments(): void
    {
        DB::table('device_assignments')
            ->select('device_id')
            ->whereNull('released_at')
            ->groupBy('device_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('device_id')
            ->chunk(200, function ($duplicateDevices): void {
                foreach ($duplicateDevices as $duplicateDevice) {
                    $activeAssignments = DB::table('device_assignments')
                        ->where('device_id', $duplicateDevice->device_id)
                        ->whereNull('released_at')
                        ->orderByDesc('assigned_at')
                        ->orderByDesc('id')
                        ->get();
                    $winner = $activeAssignments->shift();

                    if (! $winner) {
                        continue;
                    }

                    foreach ($activeAssignments as $staleAssignment) {
                        $changes = [
                            'released_at' => $winner->assigned_at,
                            'updated_at' => now(),
                        ];

                        if ($staleAssignment->collection_started_at !== null
                            && $staleAssignment->collection_stopped_at === null) {
                            $changes += [
                                'collection_stopped_at' => $winner->assigned_at,
                                'collection_stop_reason' => 'canonical_assignment_integrity_repair',
                                'withdrawal_outcome' => 'collection_stopped_and_live_projection_revoked',
                            ];
                        }

                        DB::table('device_assignments')
                            ->where('id', $staleAssignment->id)
                            ->whereNull('released_at')
                            ->update($changes);
                    }
                }
            });
    }
};
