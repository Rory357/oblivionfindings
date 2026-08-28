<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves a named foreign-key support index across uniqueness rollback and reapply', function (array $case): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    while ($connection->transactionLevel() > 0) {
        $connection->commit();
    }

    $path = database_path($case['migration']);

    try {
        // Simulate a database upgraded by the earlier migration source, where
        // InnoDB may have retained only the unique index for FK support.
        if (Schema::hasIndex($case['table'], $case['supporting_index'])) {
            Schema::table($case['table'], function (Blueprint $table) use ($case): void {
                $table->dropIndex($case['supporting_index']);
            });
        }

        expect(Schema::hasIndex($case['table'], $case['unique_index']))->toBeTrue()
            ->and(Schema::hasIndex($case['table'], $case['supporting_index']))->toBeFalse();

        /** @var Migration $migration */
        $migration = require $path;
        $migration->down();

        $foreignKey = collect(Schema::getForeignKeys($case['table']))
            ->first(fn (array $key): bool => in_array($case['column'], $key['columns'] ?? [], true));
        expect(Schema::hasIndex($case['table'], $case['unique_index']))->toBeFalse()
            ->and(Schema::hasIndex($case['table'], $case['supporting_index']))->toBeTrue()
            ->and($foreignKey)->not->toBeNull();

        /** @var Migration $migration */
        $migration = require $path;
        $migration->up();

        expect(Schema::hasIndex($case['table'], $case['unique_index']))->toBeTrue()
            ->and(Schema::hasIndex($case['table'], $case['supporting_index']))->toBeTrue();
    } finally {
        if (! Schema::hasIndex($case['table'], $case['unique_index'])
            || ! Schema::hasIndex($case['table'], $case['supporting_index'])) {
            /** @var Migration $restore */
            $restore = require $path;
            $restore->up();
        }

        $connection->beginTransaction();
    }
})->with([
    'attendance time-entry session' => [[
        'migration' => 'migrations/2026_06_27_120000_add_unique_attendance_session_id_to_hr_time_entries.php',
        'table' => 'hr_time_entries',
        'column' => 'attendance_session_id',
        'unique_index' => 'hr_time_entries_attendance_session_id_unique',
        'supporting_index' => 'hr_time_entries_attendance_session_id_index',
    ]],
    'medication round template and date' => [[
        'migration' => 'migrations/2026_08_28_000200_add_medication_round_generation_identity.php',
        'table' => 'medication_rounds',
        'column' => 'round_template_id',
        'unique_index' => 'med_rounds_template_date_unique',
        'supporting_index' => 'med_rounds_template_id_index',
    ]],
    'outgoing shift handover' => [[
        'migration' => 'migrations/2026_08_28_000250_enforce_unique_outgoing_shift_handovers.php',
        'table' => 'shift_handovers',
        'column' => 'outgoing_shift_id',
        'unique_index' => 'shift_handovers_outgoing_shift_unique',
        'supporting_index' => 'shift_handovers_outgoing_shift_id_index',
    ]],
]);
