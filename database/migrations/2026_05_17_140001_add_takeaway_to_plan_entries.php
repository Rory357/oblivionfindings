<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('site_meal_plan_entries', function (Blueprint $table) {
            $table->enum('source_type', ['recipe', 'ad_hoc', 'takeaway'])
                ->default('recipe')
                ->after('meal_slot');
            $table->string('takeaway_vendor')->nullable()->after('ad_hoc_name');
            $table->unsignedInteger('takeaway_cost_cents')->nullable()->after('takeaway_vendor');
            $table->string('takeaway_reference')->nullable()->after('takeaway_cost_cents');

            $table->index(['site_id', 'source_type'], 'smpe_site_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('site_meal_plan_entries', function (Blueprint $table) {
            $table->dropIndex('smpe_site_source_idx');
            $table->dropColumn(['source_type', 'takeaway_vendor', 'takeaway_cost_cents', 'takeaway_reference']);
        });
    }
};
