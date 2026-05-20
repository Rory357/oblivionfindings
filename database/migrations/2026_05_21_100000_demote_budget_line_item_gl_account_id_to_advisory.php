<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit follow-up: BudgetLineItem.gl_account_id was added as an unsigned bigint
 * with no enforced foreign-key constraint — already advisory in practice. This
 * migration formalises that posture by:
 *
 *  1. Adding a denormalised `gl_account_code` string column so the UI can
 *     display the human-readable account code without joining fin_accounts
 *     (Finance remains the chart-of-accounts source of truth).
 *  2. Backfilling the new column from the existing FK where possible.
 *
 * The numeric `gl_account_id` is left in place for now (other modules may
 * already key off it) but is marked-by-convention as advisory: Governance no
 * longer enforces a relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budget_line_items')) {
            return;
        }

        if (! Schema::hasColumn('budget_line_items', 'gl_account_code')) {
            Schema::table('budget_line_items', function (Blueprint $table) {
                $table->string('gl_account_code', 32)->nullable()->after('gl_account_id');
            });
        }

        // Backfill the cached code from fin_accounts where the legacy FK still resolves.
        if (Schema::hasTable('fin_accounts') && Schema::hasColumn('fin_accounts', 'code')) {
            DB::table('budget_line_items')
                ->whereNotNull('gl_account_id')
                ->whereNull('gl_account_code')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        $code = DB::table('fin_accounts')->where('id', $row->gl_account_id)->value('code');
                        if ($code !== null) {
                            DB::table('budget_line_items')
                                ->where('id', $row->id)
                                ->update(['gl_account_code' => $code]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('budget_line_items') && Schema::hasColumn('budget_line_items', 'gl_account_code')) {
            Schema::table('budget_line_items', function (Blueprint $table) {
                $table->dropColumn('gl_account_code');
            });
        }
    }
};
