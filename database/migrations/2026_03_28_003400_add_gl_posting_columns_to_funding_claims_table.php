<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_id')->nullable()->after('rejection_reason');
            $table->datetime('gl_posted_at')->nullable()->after('journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('funding_claims', function (Blueprint $table) {
            $table->dropColumn(['journal_id', 'gl_posted_at']);
        });
    }
};
