<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategic_goals', function (Blueprint $table) {
            if (! Schema::hasColumn('strategic_goals', 'origin_goal_id')) {
                $table->foreignId('origin_goal_id')->nullable()->after('strategic_plan_id')->constrained('strategic_goals')->nullOnDelete();
            }
            if (! Schema::hasColumn('strategic_goals', 'roadmap_initiative_id')) {
                $table->foreignId('roadmap_initiative_id')->nullable()->after('risks')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('strategic_goals', function (Blueprint $table) {
            if (Schema::hasColumn('strategic_goals', 'origin_goal_id')) {
                $table->dropConstrainedForeignId('origin_goal_id');
            }
            if (Schema::hasColumn('strategic_goals', 'roadmap_initiative_id')) {
                $table->dropColumn('roadmap_initiative_id');
            }
        });
    }
};
