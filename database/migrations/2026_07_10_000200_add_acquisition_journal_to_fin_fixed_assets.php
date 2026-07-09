<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the acquisition journal on the fixed asset itself. Previously the
 * acquisition posting was untracked (only discoverable via FinJournal source
 * polymorphics), so it had no idempotency guard, and an asset created without
 * a GL account could NEVER post its acquisition later — there was no
 * "capitalise" action and nothing to tell whether one had already posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_fixed_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('acquisition_journal_id')->nullable()->after('gl_expense_account_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fin_fixed_assets', function (Blueprint $table) {
            $table->dropColumn('acquisition_journal_id');
        });
    }
};
