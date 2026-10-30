<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table): void {
            $table->unsignedInteger('registration_closes_before_minutes')->nullable();
        });
        Schema::create('hr_calendar_personal_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('hr_calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['event_id', 'occurrence_date', 'user_id'], 'calendar_personal_reminder_choice_unique');
            $table->index('enabled', 'calendar_personal_reminders_enabled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_calendar_personal_reminders');
        Schema::table('hr_calendar_events', function (Blueprint $table): void {
            $table->dropColumn('registration_closes_before_minutes');
        });
    }
};
