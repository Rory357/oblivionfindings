<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add soft-delete support to development plans (HrDevelopmentGoal) so a plan can
 * be removed without losing history; the global scope auto-hides deleted rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_development_goals', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('hr_development_goals', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
