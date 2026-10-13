<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table): void {
            $table->boolean('registration_enabled')->default(false);
            $table->unsignedInteger('registration_capacity')->nullable();
            $table->unsignedInteger('registration_version')->default(1);
        });
        Schema::create('hr_calendar_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('hr_calendar_events')->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'occurrence_date', 'user_id'], 'calendar_registration_person');
            $table->index(['event_id', 'occurrence_date', 'status'], 'calendar_registration_places');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_calendar_registrations');
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->dropColumn(['registration_enabled', 'registration_capacity', 'registration_version']));
    }
};
