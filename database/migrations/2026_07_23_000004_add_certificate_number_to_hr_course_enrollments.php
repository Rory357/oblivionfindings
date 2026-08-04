<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_course_enrollments', function (Blueprint $table): void {
            $table->string('certificate_number', 120)->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('hr_course_enrollments', function (Blueprint $table): void {
            $table->dropColumn('certificate_number');
        });
    }
};
