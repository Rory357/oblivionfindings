<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence attachment path for performance reviews and goals (private disk).
 * Additive, nullable — reversible and leaves existing rows untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_performance_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_performance_reviews', 'evidence_path')) {
                $table->string('evidence_path')->nullable()->after('goals');
            }
        });

        Schema::table('hr_goals', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_goals', 'evidence_path')) {
                $table->string('evidence_path')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_performance_reviews', function (Blueprint $table) {
            $table->dropColumn('evidence_path');
        });
        Schema::table('hr_goals', function (Blueprint $table) {
            $table->dropColumn('evidence_path');
        });
    }
};
