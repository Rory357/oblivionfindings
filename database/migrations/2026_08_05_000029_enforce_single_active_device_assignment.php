<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->releaseDuplicateActiveAssignments();

        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX device_assignments_one_active_device_uq '
                .'ON device_assignments (device_id) WHERE released_at IS NULL',
            );

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new LogicException('Single-active Device assignments require a supported database driver.');
        }

        // MySQL 8 functional indexes allow multiple NULL values. Released
        // history is therefore unrestricted while active Device ids remain
        // unique, without rebuilding the table around a stored column.
        DB::statement(
            'CREATE UNIQUE INDEX device_assignments_one_active_device_uq '
            .'ON device_assignments ((CASE WHEN released_at IS NULL THEN device_id ELSE NULL END))',
        );
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX IF EXISTS device_assignments_one_active_device_uq');

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            throw new LogicException('Single-active Device assignments require a supported database driver.');
        }

        DB::statement('DROP INDEX device_assignments_one_active_device_uq ON device_assignments');
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
