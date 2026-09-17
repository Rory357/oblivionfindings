<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board finance plain-language pass (2026-09-14).
 *
 * - `actuals_recorded_at` lets the budget page say "Actual spend not recorded
 *   yet" instead of showing $0 as if nothing had been spent.
 * - `external_approval_reference` records the minutes reference when a budget
 *   the board approved outside this system is entered as already approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            if (! Schema::hasColumn('budgets', 'actuals_recorded_at')) {
                $table->timestamp('actuals_recorded_at')->nullable()->after('approved_by_board_at');
            }

            if (! Schema::hasColumn('budgets', 'external_approval_reference')) {
                $table->string('external_approval_reference')->nullable()->after('actuals_recorded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            if (Schema::hasColumn('budgets', 'external_approval_reference')) {
                $table->dropColumn('external_approval_reference');
            }

            if (Schema::hasColumn('budgets', 'actuals_recorded_at')) {
                $table->dropColumn('actuals_recorded_at');
            }
        });
    }
};
