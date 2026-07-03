<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give behaviour support plans a real, stored ticket number (BSP-YYYY-NNNN via
 * the central ReferenceNumberGenerator) instead of the display-time
 * 'BSP-'.str_pad(id) synthesised in RestraintController::planRef().
 *
 * Same resume-safe pattern as 2026_07_03_100001_add_reference_numbers_to_task_entities:
 * every step is individually guarded so a partially-failed run (DDL
 * auto-commits; a backfill can die mid-chunk) resumes cleanly.
 */
return new class extends Migration
{
    private const TABLE = 'behaviour_support_plans';

    private const PREFIX = 'BSP';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'reference_number')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('reference_number', 32)->nullable()->after('id');
            });
        }

        try {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique('reference_number', self::TABLE.'_reference_number_unique');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Ignore ONLY "duplicate key name" (1061 — index already exists
            // from a previous partial run); anything else must fail loudly.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1061) {
                throw $e;
            }
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'reference_number')) {
            return;
        }

        try {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::TABLE.'_reference_number_unique');
            });
        } catch (\Illuminate\Database\QueryException) {
            // Index missing (half-applied up()) — still drop the column.
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn('reference_number');
        });
    }

    /**
     * Number un-referenced plans in id (creation) order, scoped to the year
     * each row was created, then point the sequences past the highest number
     * used. Resume-safe: counters start above any references a previous
     * (interrupted) run already assigned.
     */
    private function backfill(): void
    {
        $counters = [];

        DB::table(self::TABLE)
            ->whereNotNull('reference_number')
            ->where('reference_number', 'like', self::PREFIX.'-%')
            ->pluck('reference_number')
            ->each(function ($ref) use (&$counters) {
                if (preg_match('/^'.preg_quote(self::PREFIX, '/').'-(\d{4})-(\d+)$/', (string) $ref, $m)) {
                    $year = (int) $m[1];
                    $counters[$year] = max($counters[$year] ?? 0, (int) $m[2]);
                }
            });

        DB::table(self::TABLE)
            ->select('id', 'created_at')
            ->whereNull('reference_number')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$counters) {
                foreach ($rows as $row) {
                    $year = $row->created_at
                        ? (int) substr((string) $row->created_at, 0, 4)
                        : now()->year;

                    $counters[$year] = ($counters[$year] ?? 0) + 1;

                    DB::table(self::TABLE)->where('id', $row->id)->update([
                        'reference_number' => sprintf('%s-%d-%04d', self::PREFIX, $year, $counters[$year]),
                    ]);
                }
            });

        foreach ($counters as $year => $count) {
            $this->putSequence(self::PREFIX.'-'.$year, $count + 1);
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
