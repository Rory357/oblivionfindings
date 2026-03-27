<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bank_transactions', function (Blueprint $table) {
            $table->foreignId('bank_feed_id')->nullable()->after('matched_journal_line_id')->constrained('fin_bank_feeds')->nullOnDelete();
            $table->string('external_id')->nullable()->after('bank_feed_id');
            $table->boolean('is_from_feed')->default(false)->after('external_id');

            $table->unique(['bank_account_id', 'external_id'], 'fin_bank_txn_acct_ext_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fin_bank_transactions', function (Blueprint $table) {
            $table->dropUnique('fin_bank_txn_acct_ext_unique');
            $table->dropForeign(['bank_feed_id']);
            $table->dropColumn(['bank_feed_id', 'external_id', 'is_from_feed']);
        });
    }
};
