<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendees / RSVP for HR calendar events. An event's audience is expressed
     * as one or more rows: a group descriptor (org/site/team/department, with an
     * optional ref) and/or named people (audience_type=person, user_id set).
     * Only person rows carry an RSVP.
     */
    public function up(): void
    {
        Schema::create('hr_calendar_event_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('hr_calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->enum('audience_type', ['org', 'site', 'team', 'department', 'person']);
            $table->string('audience_ref')->nullable();
            $table->enum('rsvp_status', ['none', 'yes', 'no', 'maybe'])->default('none');
            $table->dateTime('responded_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'audience_type']);
            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_calendar_event_attendees');
    }
};
