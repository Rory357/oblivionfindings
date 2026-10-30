<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public careers apply wizard (mockup v2, approved 23 Oct 2026) asks about
 * experience, the right to work in New Zealand and driver licence. These are
 * the Recruitment homes for those answers. Every column is nullable: existing
 * applications, staff-created applications and API callers carry none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_applications', 'experience_summary')) {
                $table->text('experience_summary')->nullable()->after('cover_letter');
            }
            if (! Schema::hasColumn('hr_applications', 'years_of_experience')) {
                $table->string('years_of_experience', 20)->nullable()->after('experience_summary');
            }
            if (! Schema::hasColumn('hr_applications', 'right_to_work_status')) {
                $table->string('right_to_work_status', 30)->nullable()->after('years_of_experience');
            }
            if (! Schema::hasColumn('hr_applications', 'driver_licence_class')) {
                $table->string('driver_licence_class', 20)->nullable()->after('right_to_work_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_applications', function (Blueprint $table) {
            foreach (['driver_licence_class', 'right_to_work_status', 'years_of_experience', 'experience_summary'] as $column) {
                if (Schema::hasColumn('hr_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
