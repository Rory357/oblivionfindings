<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_command_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_uuid')->unique();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('it_change_id')->nullable()->constrained('it_changes')->nullOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('monitoring_collectors')->nullOnDelete();
            $table->string('capability', 120);
            $table->unsignedSmallInteger('capability_version')->default(1);
            $table->string('management_level', 20);
            $table->string('risk', 20);
            $table->string('status', 40)->index();
            $table->longText('encrypted_parameters')->nullable();
            $table->json('safe_parameter_summary')->nullable();
            $table->text('reason');
            $table->json('expected_state')->nullable();
            $table->string('reconciliation_rule', 120);
            $table->string('idempotency_key', 128);
            $table->string('signing_key_id', 120)->nullable();
            $table->text('signature')->nullable();
            $table->string('execution_route', 40)->nullable();
            $table->string('provider', 80)->nullable();
            $table->text('safe_result_summary')->nullable();
            $table->text('safe_failure_reason')->nullable();
            $table->boolean('is_break_glass')->default(false);
            $table->text('break_glass_reason')->nullable();
            $table->foreignId('break_glass_reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('break_glass_review_summary')->nullable();
            $table->timestamp('step_up_confirmed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('execution_completed_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('break_glass_reviewed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['device_id', 'created_at']);
            $table->index(['site_id', 'status']);
            $table->index(['requested_by_user_id', 'status']);
            $table->index(['capability', 'status']);
            $table->unique(
                ['device_id', 'requested_by_user_id', 'capability', 'idempotency_key'],
                'device_command_requests_idempotency_unique',
            );
        });

        Schema::create('device_command_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_command_request_id')->constrained('device_command_requests')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 20);
            $table->text('comment');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique('device_command_request_id', 'device_command_approvals_request_unique');
            $table->index('decided_at');
        });

        Schema::create('device_command_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_command_request_id')->constrained('device_command_requests')->restrictOnDelete();
            $table->uuid('attempt_uuid')->unique();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 30);
            $table->string('runtime', 40);
            $table->string('provider_request_reference', 160)->nullable();
            $table->json('safe_result_summary')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('safe_failure_reason')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['device_command_request_id', 'attempt_number'], 'device_command_attempts_request_number_unique');
        });

        Schema::create('device_command_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_command_request_id')->constrained('device_command_requests')->restrictOnDelete();
            $table->foreignId('device_command_attempt_id')->nullable()->constrained('device_command_attempts')->restrictOnDelete();
            $table->string('outcome', 30);
            $table->json('expected_state');
            $table->json('observed_state')->nullable();
            $table->string('observation_reference', 255)->nullable();
            $table->text('safe_evidence_summary')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['device_command_request_id', 'observed_at'], 'device_command_reconciliations_request_observed_index');
        });

        Schema::create('device_command_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_command_request_id')->constrained('device_command_requests')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->json('safe_context')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('event_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['device_command_request_id', 'event_hash'], 'device_command_audit_request_hash_unique');
            $table->index(['device_command_request_id', 'occurred_at'], 'device_command_audit_request_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_command_audit_events');
        Schema::dropIfExists('device_command_reconciliations');
        Schema::dropIfExists('device_command_attempts');
        Schema::dropIfExists('device_command_approvals');
        Schema::dropIfExists('device_command_requests');
    }
};
