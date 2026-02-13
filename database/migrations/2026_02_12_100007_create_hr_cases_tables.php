<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // HR cases (disciplinary, grievance, performance improvement, etc.)
        Schema::create('hr_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('case_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('subject employee');
            $table->string('case_type');
            $table->string('severity')->default('medium');
            $table->string('status')->default('open');
            $table->string('title');
            $table->text('description');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('opened_at');
            $table->datetime('closed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->string('outcome_type')->nullable();
            $table->boolean('is_confidential')->default(true);
            $table->json('access_list')->nullable();
            $table->json('linked_incident_ids')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['case_number']);
        });

        // Case event timeline
        Schema::create('hr_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('hr_cases')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->datetime('occurred_at');
            $table->string('document_path')->nullable();
            $table->string('visibility')->default('hr_only');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['case_id', 'occurred_at']);
        });

        // Disciplinary actions with full NZ employment law process tracking
        Schema::create('hr_disciplinary_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('case_id')->constrained('hr_cases')->cascadeOnDelete();
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('stage')->default('investigation');
            $table->string('action_type');
            $table->text('allegation_summary');
            $table->text('investigation_notes')->nullable();
            $table->foreignId('investigator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('notice_issued_at')->nullable();
            $table->string('notice_document_path')->nullable();
            $table->datetime('meeting_scheduled_at')->nullable();
            $table->string('meeting_location')->nullable();
            $table->boolean('support_person_advised')->default(false);
            $table->datetime('meeting_held_at')->nullable();
            $table->text('meeting_notes')->nullable();
            $table->json('meeting_attendees')->nullable();
            $table->text('employee_response')->nullable();
            $table->datetime('response_deadline')->nullable();
            $table->string('outcome')->nullable();
            $table->datetime('outcome_decided_at')->nullable();
            $table->foreignId('outcome_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('outcome_rationale')->nullable();
            $table->datetime('outcome_communicated_at')->nullable();
            $table->string('outcome_document_path')->nullable();
            $table->json('good_faith_checklist')->nullable();
            $table->boolean('appeal_received')->default(false);
            $table->datetime('appeal_received_at')->nullable();
            $table->text('appeal_notes')->nullable();
            $table->string('appeal_outcome')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['case_id']);
            $table->index(['employee_user_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_disciplinary_actions');
        Schema::dropIfExists('hr_case_events');
        Schema::dropIfExists('hr_cases');
    }
};
