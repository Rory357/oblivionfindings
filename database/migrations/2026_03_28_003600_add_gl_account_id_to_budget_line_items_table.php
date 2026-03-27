<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_line_items', function (Blueprint $table) {
            $table->unsignedBigInteger('gl_account_id')->nullable()->after('account_code');
        });
    }

    public function down(): void
    {
        Schema::table('budget_line_items', function (Blueprint $table) {
            $table->dropColumn('gl_account_id');
        });
    }
};
