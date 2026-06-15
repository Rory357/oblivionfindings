<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional cross-link: a development plan (HrDevelopmentGoal) can roll up into
 * an OKR objective (HrGoal). Nullable FK on the "many" side; no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_development_goals', function (Blueprint $table) {
            $table->foreignId('hr_goal_id')
                ->nullable()
                ->after('manager_user_id')
                ->constrained('hr_goals')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_development_goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hr_goal_id');
        });
    }
};
