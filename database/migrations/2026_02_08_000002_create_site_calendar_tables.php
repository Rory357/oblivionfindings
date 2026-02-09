<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->index();

            // Event details
            $table->string('event_type', 50)->index();
            $table->string('title');
            $table->text('description')->nullable();

            // Timing
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->string('timezone', 50)->default('UTC');

            // Recurrence (RFC 5545 RRULE compatible)
            $table->string('recurrence_rule')->nullable();
            $table->foreignId('recurrence_parent_id')->nullable()->constrained('site_calendar_events')->nullOnDelete();
            $table->json('recurrence_exceptions')->nullable();

            // Linked entities
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('fleet_vehicle_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('checklist_run_id')->nullable();
            $table->foreignId('hazard_id')->nullable();

            // People
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('attendee_user_ids')->nullable();

            // Approval workflow
            $table->string('status', 20)->default('draft');
            $table->string('approval_status', 20)->default('not_required');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();

            // Reminders
            $table->json('reminder_minutes')->nullable();
            $table->timestamp('last_reminder_sent_at')->nullable();

            // Attachments & outcomes
            $table->json('attachments')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['site_id', 'start_at', 'end_at']);
            $table->index(['event_type', 'status']);
            $table->index(['owner_user_id', 'start_at']);
            $table->index(['recurrence_parent_id']);
        });

        Schema::create('site_calendar_event_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_event_id')->constrained('site_calendar_events')->cascadeOnDelete();
            $table->date('exception_date');
            $table->boolean('is_cancelled')->default(false);
            $table->json('overridden_fields')->nullable();
            $table->timestamps();

            $table->unique(['parent_event_id', 'exception_date'], 'cal_exc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_calendar_event_exceptions');
        Schema::dropIfExists('site_calendar_events');
    }
};
