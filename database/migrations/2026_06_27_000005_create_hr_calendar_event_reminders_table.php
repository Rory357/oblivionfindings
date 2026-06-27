<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reminders for HR calendar events: fire `offset_minutes` before the event
     * start (or before each occurrence, for a recurring series) on the given
     * channel. `last_sent_at` records the trigger time we last dispatched, so the
     * every-minute scheduler never double-sends.
     */
    public function up(): void
    {
        Schema::create('hr_calendar_event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('hr_calendar_events')->cascadeOnDelete();
            $table->unsignedInteger('offset_minutes')->default(0);
            $table->enum('channel', ['notification', 'email'])->default('notification');
            $table->dateTime('last_sent_at')->nullable();
            $table->timestamps();

            // Explicit short name — the auto-generated one exceeds MySQL's 64-char limit.
            $table->unique(['event_id', 'offset_minutes', 'channel'], 'hr_cal_reminder_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_calendar_event_reminders');
    }
};
