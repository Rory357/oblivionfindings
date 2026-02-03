<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respite_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('referrer_type')->nullable();
            $table->string('referrer_name');
            $table->string('referrer_contact')->nullable();
            $table->text('referral_reason');
            $table->enum('urgency', ['planned', 'urgent', 'crisis'])->default('planned');
            $table->enum('status', ['received', 'triaged', 'accepted', 'declined'])->default('received');
            $table->timestamp('received_at');
            $table->text('triage_notes')->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->nullable();
            $table->unsignedBigInteger('linked_booking_request_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index('received_at');
        });

        Schema::create('respite_booking_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->nullable()->constrained('respite_referrals')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('service_context_id')->nullable()->constrained('service_contexts')->nullOnDelete();
            $table->timestamp('requested_start');
            $table->timestamp('requested_end');
            $table->json('requirements')->nullable();
            $table->text('preference_notes')->nullable();
            $table->string('funding_reference')->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'waitlisted'])->default('draft');
            $table->text('decision_notes')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index('requested_start');
        });

        Schema::create('respite_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_request_id')->nullable()->constrained('respite_booking_requests')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->enum('status', ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->foreignId('assigned_coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->json('approvals')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index('start_at');
        });

        Schema::create('respite_evidence_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stay_id')->nullable();
            $table->enum('status', ['drafting', 'sealed'])->default('drafting');
            $table->text('summary')->nullable();
            $table->json('items')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->foreignId('sealed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('stay_id');
        });

        Schema::create('respite_stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('respite_bookings')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->enum('status', ['admitted', 'active', 'extended', 'discharged'])->default('admitted');
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->text('discharge_summary')->nullable();
            $table->foreignId('evidence_pack_id')->nullable()->constrained('respite_evidence_packs')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });

        Schema::create('respite_resource_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained('respite_bookings')->nullOnDelete();
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->enum('status', ['reserved', 'confirmed', 'released'])->default('reserved');
            $table->text('conflict_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['resource_type', 'resource_id'], 'respite_resource_lookup_idx');
            $table->index('start_at');
        });

        Schema::create('respite_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained('respite_bookings')->nullOnDelete();
            $table->foreignId('stay_id')->nullable()->constrained('respite_stays')->nullOnDelete();
            $table->string('event_type');
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->foreignId('location_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('visibility', ['private', 'limited', 'public'])->default('limited');
            $table->enum('projection_status', ['pending', 'projected', 'suppressed', 'failed'])->default('pending');
            $table->json('meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('start_at');
            $table->index('location_id');
            $table->index('staff_id');
        });

        Schema::create('respite_handover_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('respite_stays')->cascadeOnDelete();
            $table->enum('handover_type', ['arrival', 'shift_change', 'daily', 'discharge'])->default('shift_change');
            $table->text('notes');
            $table->boolean('sensitive_flag')->default(false);
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('respite_communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('respite_stays')->cascadeOnDelete();
            $table->enum('channel', ['phone', 'email', 'in_person', 'portal', 'other'])->default('other');
            $table->json('participants')->nullable();
            $table->text('summary');
            $table->timestamp('occurred_at');
            $table->json('evidence')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('respite_linked_refs', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject', 'respite_linked_subject_idx');
            $table->enum('ref_type', ['incident', 'medication', 'transport', 'funding', 'document', 'other'])->default('other');
            $table->string('ref_id');
            $table->string('relation')->nullable();
            $table->json('meta')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respite_linked_refs');
        Schema::dropIfExists('respite_communication_logs');
        Schema::dropIfExists('respite_handover_notes');
        Schema::dropIfExists('respite_calendar_events');
        Schema::dropIfExists('respite_resource_allocations');
        Schema::dropIfExists('respite_stays');
        Schema::dropIfExists('respite_evidence_packs');
        Schema::dropIfExists('respite_bookings');
        Schema::dropIfExists('respite_booking_requests');
        Schema::dropIfExists('respite_referrals');
    }
};
