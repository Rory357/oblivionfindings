<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->foreignId('journal_id')
                ->nullable()
                ->after('status')
                ->constrained('fin_journals')
                ->nullOnDelete();
            $table->datetime('gl_posted_at')->nullable()->after('journal_id');

            $table->index(['organization_id', 'journal_id'], 'fin_inv_org_journal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            $table->dropIndex('fin_inv_org_journal_idx');
            $table->dropForeign(['journal_id']);
            $table->dropColumn(['journal_id', 'gl_posted_at']);
        });
    }
};
