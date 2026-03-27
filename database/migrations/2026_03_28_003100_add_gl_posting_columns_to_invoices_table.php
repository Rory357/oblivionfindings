<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_id')->nullable()->after('notes');
            $table->datetime('gl_posted_at')->nullable()->after('journal_id');

            $table->index(['journal_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['journal_id']);
            $table->dropColumn(['journal_id', 'gl_posted_at']);
        });
    }
};
