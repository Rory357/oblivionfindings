<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();

            $table->string('event_type');              // ClinicalEventType enum value
            $table->string('severity');                // AlertSeverity constant value
            $table->timestamp('occurred_at');
            $table->timestamp('reported_at');

            $table->text('description');
            $table->text('immediate_action_taken')->nullable();
            $table->text('outcome')->nullable();
            $table->json('witnesses')->nullable();     // Array of user IDs

            // H&S / Incident linkage
            $table->foreignId('linked_hs_event_id')->nullable();
            $table->foreignId('linked_incident_id')->nullable();

            // Follow-up
            $table->boolean('requires_followup')->default(false);
            $table->text('followup_notes')->nullable();
            $table->timestamp('followup_completed_at')->nullable();
            $table->foreignId('followup_completed_by')->nullable()->constrained('users')->nullOnDelete();

            // Review lifecycle
            $table->string('status')->default('open'); // open, reviewed, closed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Query indexes (explicit names for MySQL 64-char limit)
            $table->index(['client_id', 'event_type', 'occurred_at'], 'clin_evt_client_type_occurred');
            $table->index(['severity', 'status'], 'clin_evt_severity_status');
            $table->index(['shift_id', 'occurred_at'], 'clin_evt_shift_occurred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_events');
    }
};
