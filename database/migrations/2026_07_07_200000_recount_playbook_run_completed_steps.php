<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recount control_room_playbook_runs.completed_steps from the actual step rows.
 *
 * The counter had drifted two ways: completing a step never incremented it
 * (the controller pre-completed the step, so PlaybookRun::advanceToNextStep()
 * found nothing in progress to count) and skipping a step incremented it even
 * though a skipped step was never done. The write paths are fixed alongside
 * this migration; this backfills every existing run to the true value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE control_room_playbook_runs runs
            SET completed_steps = (
                SELECT COUNT(*)
                FROM control_room_playbook_run_steps steps
                WHERE steps.playbook_run_id = runs.id
                  AND steps.status = 'completed'
            )
        SQL);
    }

    public function down(): void
    {
        // Irreversible data correction — the drifted values are not recoverable.
    }
};
