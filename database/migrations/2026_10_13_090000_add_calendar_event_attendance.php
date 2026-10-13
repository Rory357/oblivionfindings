<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_calendar_registrations', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->string('attendance', 16)->nullable();
            $table->timestamp('attendance_recorded_at')->nullable();
            $table->foreignId('attendance_recorded_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->boolean('reminders_override')->default(false));
    }

    public function down(): void
    {
        Schema::table('hr_calendar_registrations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('attendance_recorded_by');
            $table->dropColumn(['version', 'attendance', 'attendance_recorded_at']);
        });
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->dropColumn('reminders_override'));
    }
};
