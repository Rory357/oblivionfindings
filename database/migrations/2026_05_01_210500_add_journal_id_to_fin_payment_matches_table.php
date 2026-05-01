<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_payment_matches', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_id')->nullable()->after('confirmed_at');
            $table->index('journal_id', 'fin_pm_journal_id_idx');
            $table->foreign('journal_id', 'fin_pm_journal_id_fk')
                ->references('id')
                ->on('fin_journals')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fin_payment_matches', function (Blueprint $table) {
            $table->dropForeign('fin_pm_journal_id_fk');
            $table->dropIndex('fin_pm_journal_id_idx');
            $table->dropColumn('journal_id');
        });
    }
};
