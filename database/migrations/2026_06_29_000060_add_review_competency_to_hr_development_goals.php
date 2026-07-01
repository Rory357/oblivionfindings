<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_development_goals', function (Blueprint $table) {
            // When the next review reminder is due — bumped forward by the
            // cadence each time the reminder fires (idempotent nag).
            $table->date('next_review_at')->nullable()->after('due_date');
            // Optional link to a formal competency (item 15) instead of free text.
            if (Schema::hasTable('hr_competencies')) {
                $table->foreignId('competency_id')->nullable()->after('competency_area')
                    ->constrained('hr_competencies')->nullOnDelete();
            } else {
                $table->unsignedBigInteger('competency_id')->nullable()->after('competency_area');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_development_goals', function (Blueprint $table) {
            if (Schema::hasColumn('hr_development_goals', 'competency_id')) {
                try {
                    $table->dropConstrainedForeignId('competency_id');
                } catch (\Throwable $e) {
                    $table->dropColumn('competency_id');
                }
            }
            $table->dropColumn('next_review_at');
        });
    }
};
