<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'weekly_food_budget_cents')) {
                $table->unsignedInteger('weekly_food_budget_cents')->nullable()->after('rent_frequency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'weekly_food_budget_cents')) {
                $table->dropColumn('weekly_food_budget_cents');
            }
        });
    }
};
