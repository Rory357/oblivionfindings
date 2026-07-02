<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every incident/task-like entity a human-facing ticket number.
 *
 * Adds a nullable-unique `reference_number` to the eight tables that lacked
 * one, backfills existing rows in creation order (numbered within the year
 * they were created), and seeds `reference_sequences` so the central
 * ReferenceNumberGenerator continues each scope above both the backfilled
 * numbers and the numbers legacy per-model generators already handed out
 * (HS-, INV-, CA-, RA-, SG-, DSR-, BR-, HAZ-, ACT-, HR-).
 */
return new class extends Migration
{
    /** table => prefix for the newly-numbered entities */
    private const NEW_REF_TABLES = [
        'client_incidents' => 'INC',
        'medication_errors' => 'MED',
        'controlled_drug_loss_reports' => 'CDL',
        'control_room_alerts' => 'CR',
        'workplace_injuries' => 'INJ',
        'first_aid_records' => 'FA',
        'restraint_events' => 'RST',
        'fleet_incidents' => 'FLT',
    ];

    /** table => [column, prefix] for legacy year-scoped generators being centralised */
    private const LEGACY_YEAR_SCOPED = [
        'hs_events' => ['reference_number', 'HS'],
        'hs_investigations' => ['reference_number', 'INV'],
        'hs_corrective_actions' => ['reference_number', 'CA'],
        'hs_risk_assessments' => ['reference_number', 'RA'],
        'safeguarding_concerns' => ['reference_number', 'SG'],
        'data_subject_requests' => ['reference_number', 'DSR'],
        'data_breach_logs' => ['breach_reference', 'BR'],
        'site_hazards' => ['reference_number', 'HAZ'],
        'action_items' => ['action_reference', 'ACT'],
    ];

    public function up(): void
    {
        foreach (self::NEW_REF_TABLES as $tableName => $prefix) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'reference_number')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->string('reference_number', 32)->nullable()->after('id');
                $table->unique('reference_number', $tableName.'_reference_number_unique');
            });

            $this->backfill($tableName, $prefix);
        }

        foreach (self::LEGACY_YEAR_SCOPED as $tableName => [$column, $prefix]) {
            $this->seedFromExisting($tableName, $column, $prefix);
        }

        // hr_cases uses a single global HR-NNNNN sequence (no year segment).
        $this->seedGlobalFromExisting('hr_cases', 'case_number', 'HR');
    }

    public function down(): void
    {
        foreach (array_keys(self::NEW_REF_TABLES) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'reference_number')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->dropUnique($tableName.'_reference_number_unique');
                    $table->dropColumn('reference_number');
                });
            }
        }
    }

    /**
     * Number existing rows in id (creation) order, scoped to the year each
     * row was created, then point the sequence past the highest number used.
     */
    private function backfill(string $tableName, string $prefix): void
    {
        $counters = [];

        DB::table($tableName)
            ->select('id', 'created_at')
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

    /**
     * Seed a year-scoped sequence from references a legacy generator already
     * issued, so the central allocator continues where it left off.
     */
    private function seedFromExisting(string $tableName, string $column, string $prefix): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        $max = [];

        DB::table($tableName)
            ->whereNotNull($column)
            ->where($column, 'like', "{$prefix}-%")
            ->pluck($column)
            ->each(function ($ref) use ($prefix, &$max) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-(\d{4})-(\d+)$/', (string) $ref, $m)) {
                    $scope = "{$prefix}-{$m[1]}";
                    $max[$scope] = max($max[$scope] ?? 0, (int) $m[2]);
                }
            });

        foreach ($max as $scope => $value) {
            $this->putSequence($scope, $value + 1);
        }
    }

    private function seedGlobalFromExisting(string $tableName, string $column, string $prefix): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        $maxValue = 0;

        DB::table($tableName)
            ->whereNotNull($column)
            ->where($column, 'like', "{$prefix}-%")
            ->pluck($column)
            ->each(function ($ref) use ($prefix, &$maxValue) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', (string) $ref, $m)) {
                    $maxValue = max($maxValue, (int) $m[1]);
                }
            });

        if ($maxValue > 0) {
            $this->putSequence($prefix, $maxValue + 1);
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
