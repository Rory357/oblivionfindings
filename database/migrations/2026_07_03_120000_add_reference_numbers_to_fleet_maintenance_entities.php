<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give fleet work orders and vehicle bookings a human-facing ticket number,
 * matching the FLT- numbers fleet incidents already carry.
 *
 * Mirrors 2026_07_03_100001_add_reference_numbers_to_task_entities: adds a
 * nullable-unique `reference_number`, backfills existing rows in creation
 * order (numbered within the year they were created), and seeds
 * `reference_sequences` so the central ReferenceNumberGenerator continues
 * each scope above the backfilled numbers.
 */
return new class extends Migration
{
    /** table => prefix for the newly-numbered entities */
    private const NEW_REF_TABLES = [
        'fleet_work_orders' => 'WO',
        'fleet_vehicle_bookings' => 'BK',
    ];

    public function up(): void
    {
        foreach (self::NEW_REF_TABLES as $tableName => $prefix) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            // Each step is individually guarded so a partially-failed run
            // (DDL auto-commits; a backfill can die mid-chunk) resumes
            // cleanly instead of wedging the table with a half-backfill.
            if (! Schema::hasColumn($tableName, 'reference_number')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('reference_number', 32)->nullable()->after('id');
                });
            }

            try {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->unique('reference_number', $tableName.'_reference_number_unique');
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Ignore ONLY "duplicate key name" (1061 — index already
                // exists from a previous partial run); anything else must
                // fail loudly rather than silently dropping the guard rail.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1061) {
                    throw $e;
                }
            }

            $this->backfill($tableName, $prefix);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::NEW_REF_TABLES) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'reference_number')) {
                continue;
            }

            // Blueprint only QUEUES commands — the exception surfaces when
            // Schema::table() executes them — so the index drop needs its
            // own Schema::table call for the catch to actually work.
            try {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->dropUnique($tableName.'_reference_number_unique');
                });
            } catch (\Illuminate\Database\QueryException) {
                // Index missing (half-applied up()) — still drop the column.
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('reference_number');
            });
        }
    }

    /**
     * Number un-referenced rows in id (creation) order, scoped to the year
     * each row was created, then point the sequence past the highest number
     * used. Resume-safe: counters start above any references a previous
     * (interrupted) run already assigned.
     */
    private function backfill(string $tableName, string $prefix): void
    {
        $counters = [];

        DB::table($tableName)
            ->whereNotNull('reference_number')
            ->where('reference_number', 'like', "{$prefix}-%")
            ->pluck('reference_number')
            ->each(function ($ref) use ($prefix, &$counters) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-(\d{4})-(\d+)$/', (string) $ref, $m)) {
                    $year = (int) $m[1];
                    $counters[$year] = max($counters[$year] ?? 0, (int) $m[2]);
                }
            });

        DB::table($tableName)
            ->select('id', 'created_at')
            ->whereNull('reference_number')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($tableName, $prefix, &$counters) {
                foreach ($rows as $row) {
                    $year = $row->created_at
                        ? (int) substr((string) $row->created_at, 0, 4)
                        : now()->year;

                    $counters[$year] = ($counters[$year] ?? 0) + 1;

                    DB::table($tableName)->where('id', $row->id)->update([
                        'reference_number' => sprintf('%s-%d-%04d', $prefix, $year, $counters[$year]),
                    ]);
                }
            });

        foreach ($counters as $year => $count) {
            $this->putSequence("{$prefix}-{$year}", $count + 1);
        }
    }

    private function putSequence(string $scope, int $nextValue): void
    {
        $existing = DB::table('reference_sequences')->where('scope', $scope)->first();

        if (! $existing) {
            DB::table('reference_sequences')->insert([
                'scope' => $scope,
                'next_value' => $nextValue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ($existing->next_value < $nextValue) {
            DB::table('reference_sequences')->where('scope', $scope)->update([
                'next_value' => $nextValue,
                'updated_at' => now(),
            ]);
        }
    }
};
