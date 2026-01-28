<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Consent types catalog
        Schema::create('consent_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Service Delivery, Data Sharing, Portal Access, Photography, etc.
            $table->string('category'); // essential, optional, third_party
            $table->text('description');
            $table->text('purpose'); // Why this consent is needed
            $table->text('legal_basis'); // GDPR Article 6 basis
            $table->boolean('mandatory')->default(false); // Required for service delivery
            $table->boolean('requires_capacity_assessment')->default(false);
            $table->integer('validity_period_months')->nullable(); // Renewal period
            $table->boolean('withdrawable')->default(true);
            $table->text('withdrawal_implications')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'active']);
            $table->index('mandatory');
        });

        // Consent type versions (for audit trail)
        Schema::create('consent_type_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consent_type_id')->constrained('consent_types')->cascadeOnDelete();
            $table->integer('version');
            $table->text('description');
            $table->text('purpose');
            $table->text('legal_basis');
            $table->json('changes_summary')->nullable(); // What changed in this version
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['consent_type_id', 'version']);
            $table->index('effective_from');
        });

        // Client consents
        Schema::create('client_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('consent_type_id')->constrained('consent_types')->cascadeOnDelete();
            $table->foreignId('consent_type_version_id')->nullable()->constrained('consent_type_versions')->nullOnDelete();

            $table->enum('status', [
                'pending',
                'given',
                'refused',
                'withdrawn',
                'expired',
                'revoked',
                'superseded'
            ])->default('pending');

            // Consent given
            $table->timestamp('given_at')->nullable();
            $table->foreignId('given_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('given_by_relationship')->nullable(); // self, parent, guardian, attorney, deputy
            $table->text('given_method')->nullable(); // verbal, written, electronic
            $table->text('given_notes')->nullable();

            // Mental capacity assessment
            $table->boolean('capacity_assessed')->default(false);
            $table->enum('capacity_outcome', [
                'has_capacity',
                'lacks_capacity',
                'fluctuating',
                'not_assessed'
            ])->nullable();
            $table->foreignId('capacity_assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('capacity_assessed_at')->nullable();
            $table->text('capacity_notes')->nullable();

            // Best interests decision (if lacks capacity)
            $table->boolean('best_interests_decision')->default(false);
            $table->foreignId('best_interests_decision_maker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('best_interests_decision_at')->nullable();
            $table->text('best_interests_rationale')->nullable();
            $table->json('best_interests_consultees')->nullable(); // Who was consulted

            // Refusal
            $table->timestamp('refused_at')->nullable();
            $table->text('refusal_reason')->nullable();

            // Withdrawal
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('withdrawal_reason')->nullable();
            $table->text('withdrawal_acknowledged')->nullable();

            // Expiry and renewal
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('renewal_reminder_sent_at')->nullable();
            $table->foreignId('superseded_by_consent_id')->nullable()->constrained('client_consents')->nullOnDelete();

            // Document evidence
            $table->string('signed_document_path')->nullable();
            $table->string('evidence_type')->nullable(); // signature, recording, witness_statement

            // Conditions and restrictions
            $table->json('conditions')->nullable(); // Any conditions or restrictions on the consent
            $table->text('special_conditions')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'consent_type_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('given_at');
        });

        // Consent withdrawal requests
        Schema::create('consent_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_consent_id')->constrained('client_consents')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->enum('request_type', ['full_withdrawal', 'partial_withdrawal', 'temporary_suspension']);
            $table->text('reason');
            $table->json('specific_consents')->nullable(); // For partial withdrawal

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_relationship')->nullable();
            $table->timestamp('requested_at');

            $table->enum('status', ['pending', 'approved', 'completed', 'rejected'])->default('pending');

            // Processing
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_notes')->nullable();

            // Impact assessment
            $table->text('service_impact')->nullable();
            $table->boolean('client_informed_of_impact')->default(false);
            $table->timestamp('client_informed_at')->nullable();

            // Completion
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index('requested_at');
        });

        // Consent audit log (specific to consent changes)
        Schema::create('consent_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_consent_id')->constrained('client_consents')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->string('action'); // given, withdrawn, refused, updated, expired, renewed
            $table->enum('previous_status', ['pending', 'given', 'refused', 'withdrawn', 'expired', 'revoked', 'superseded'])->nullable();
            $table->enum('new_status', ['pending', 'given', 'refused', 'withdrawn', 'expired', 'revoked', 'superseded']);

            $table->json('changes')->nullable(); // Detailed change log
            $table->text('reason')->nullable();

            $table->foreignId('actioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('actioned_at');

            $table->timestamps();

            $table->index(['client_consent_id', 'actioned_at']);
            $table->index(['client_id', 'action']);
        });

        // Consent reminders (for expiring consents)
        Schema::create('consent_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_consent_id')->constrained('client_consents')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->enum('reminder_type', ['renewal_due', 'review_required', 'expiring_soon']);
            $table->timestamp('due_date');

            $table->enum('status', ['pending', 'sent', 'acknowledged', 'actioned', 'dismissed'])->default('pending');

            $table->timestamp('sent_at')->nullable();
            $table->string('sent_method')->nullable(); // email, sms, letter, in_person
            $table->json('recipients')->nullable(); // Who was reminded

            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('actioned_at')->nullable();
            $table->foreignId('actioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('action_taken')->nullable();

            $table->timestamps();

            $table->index(['client_consent_id', 'status']);
            $table->index(['due_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consent_reminders');
        Schema::dropIfExists('consent_audit_logs');
        Schema::dropIfExists('consent_withdrawal_requests');
        Schema::dropIfExists('client_consents');
        Schema::dropIfExists('consent_type_versions');
        Schema::dropIfExists('consent_types');
    }
};
