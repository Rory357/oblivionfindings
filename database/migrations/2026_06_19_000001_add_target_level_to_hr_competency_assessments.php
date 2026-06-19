<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_competency_assessments') || Schema::hasColumn('hr_competency_assessments', 'target_level')) {
            return;
        }

        Schema::table('hr_competency_assessments', function (Blueprint $table) {
            $table->unsignedTinyInteger('target_level')->nullable()->after('assessed_level');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_competency_assessments') || ! Schema::hasColumn('hr_competency_assessments', 'target_level')) {
            return;
        }

        Schema::table('hr_competency_assessments', function (Blueprint $table) {
            $table->dropColumn('target_level');
        });
    }
};
