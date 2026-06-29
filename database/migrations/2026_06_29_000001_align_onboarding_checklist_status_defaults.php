<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align the onboarding/offboarding checklist status lifecycle with the code.
 *
 * The original table shipped a `not_started` column default, but every code
 * path (service, controller, UI) only ever uses pending|in_progress|completed
 * (and now cancelled|archived). This backfills any stray `not_started` rows to
 * `pending` and changes the column default so new rows match the code. `status`
 * is a plain string column, so the new cancelled/archived states need no enum
 * change.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_onboarding_checklists', 'hr_offboarding_checklists'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->where('status', 'not_started')->update(['status' => 'pending']);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('status')->default('pending')->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['hr_onboarding_checklists', 'hr_offboarding_checklists'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('status')->default('not_started')->change();
            });
        }
    }
};
