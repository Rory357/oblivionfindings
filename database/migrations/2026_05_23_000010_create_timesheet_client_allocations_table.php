<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client time allocation rows for a timesheet.
 *
 * A timesheet still has a single `client_id` (the "primary" client — for 1:1
 * shifts this is the only one, for multi-resident shifts it's the shift's
 * anchor client). The new `timesheet_client_allocations` table breaks the
 * timesheet's total hours into per-client buckets so:
 *   - residential houses can roll the same total up to N residents (equal split)
 *   - group / activity shifts can split unevenly across clients
 *   - sequential 1:1 shifts can capture a start/end window per client
 *
 * Allocation methods:
 *   - single             — one row, hours = total; default for 1:1 shifts
 *   - residential_house  — N rows, equal split, info-only for the worker
 *   - equal_split        — N rows, equal split (editable after apply)
 *   - manual             — N rows, worker enters hours per client
 *   - time_segmented     — N rows with starts_at + ends_at per segment
 *
 * Backward compat: existing timesheets with no allocation rows synthesise a
 * single-method allocation on read (timesheet.client_id, hours = total). No
 * destructive backfill needed; legacy reads keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_client_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('timesheet_id')
                ->constrained('timesheets')
                ->cascadeOnDelete();

            $table->foreignId('client_id')
                ->constrained('clients')
                ->cascadeOnDelete();

            // Always quantised in 0.01 hour units to match `timesheets.hours`.
            // Sum across rows must equal `timesheets.hours` within rounding
            // tolerance (validated at the controller layer).
            $table->decimal('hours', 6, 2);

            $table->string('allocation_method', 32)
                ->default('manual')
                ->comment('single | residential_house | equal_split | manual | time_segmented');

            // Only populated for `time_segmented` rows. Stored as UTC datetimes
            // so reporting that already speaks UTC works without conversion.
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->integer('sort_order')->default(0);

            // Optional per-client free text (e.g. "Administered PRN at 10:15").
            // Distinct from the timesheet-level `notes` which describes the
            // whole shift.
            $table->text('notes')->nullable();

            $table->timestamps();

            // One allocation row per client per timesheet. Workers wanting to
            // record multiple windows on the same client/timesheet must use
            // the per-row notes field rather than multiple rows.
            $table->unique(['timesheet_id', 'client_id'], 'tsca_unique_timesheet_client');

            // Common index patterns: per-client billing scrape, per-timesheet
            // expansion in the UI.
            $table->index(['client_id', 'timesheet_id']);
            $table->index(['timesheet_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_client_allocations');
    }
};
