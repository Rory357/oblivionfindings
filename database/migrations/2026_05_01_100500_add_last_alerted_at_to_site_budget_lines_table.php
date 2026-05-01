<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_budget_lines') || Schema::hasColumn('site_budget_lines', 'last_alerted_at')) {
            return;
        }

        Schema::table('site_budget_lines', function (Blueprint $table) {
            $table->timestamp('last_alerted_at')->nullable()->after('approved_at')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_budget_lines') || ! Schema::hasColumn('site_budget_lines', 'last_alerted_at')) {
            return;
        }

        Schema::table('site_budget_lines', function (Blueprint $table) {
            $table->dropColumn('last_alerted_at');
        });
    }
};
