<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referral_settings_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('state', 24)->default('draft');
            $table->foreignId('base_version_id')->nullable()->constrained('care_referral_settings_versions')->restrictOnDelete();
            $table->longText('configuration');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamps();
        });
        Schema::create('care_referral_configuration', function (Blueprint $table): void {
            // One organisation-wide publication pointer; this is not an organisation selector.
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('published_version_id')->nullable()->constrained('care_referral_settings_versions')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
        Schema::create('care_referrals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('settings_version_id')->constrained('care_referral_settings_versions')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->string('stage', 64)->default('new');
            $table->string('source', 32);
            $table->string('entry_kind', 32);
            $table->string('requested_service', 80)->default('supported_living');
            $table->string('team', 120)->default('Central intake');
            $table->string('priority', 24)->default('standard');
            $table->boolean('restricted')->default(false);
            $table->boolean('general_enquiry')->default(false);
            // Different audiences are serialized separately by the server. Never return raw models.
            $table->longText('identity');
            $table->longText('intake');
            $table->longText('authority');
            $table->longText('assessment');
            $table->longText('next_action');
            $table->timestamp('next_due_at')->nullable()->index();
            $table->string('hold_reason', 80)->nullable();
            $table->string('closure_reason', 80)->nullable();
            $table->timestamp('stage_changed_at');
            $table->timestamp('first_contact_attempt_at')->nullable();
            $table->timestamp('first_human_contact_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['site_id', 'stage', 'created_at'], 'care_referrals_site_stage_created');
            $table->index(['owner_id', 'stage']);
        });
        Schema::create('care_referral_submissions', function (Blueprint $table): void {
            $table->id();
            $table->char('idempotency_hash', 64)->unique();
            $table->char('payload_hash', 64);
            $table->char('receipt_hash', 64)->unique();
            $table->foreignId('referral_id')->unique()->constrained('care_referrals')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('care_referral_funding_cases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('navigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('settings_version_id')->constrained('care_referral_settings_versions')->restrictOnDelete();
            $table->foreignId('previous_case_id')->nullable()->constrained('care_referral_funding_cases')->restrictOnDelete();
            $table->string('pathway', 48);
            $table->string('status', 40)->default('exploring');
            $table->boolean('personal_income')->default(false);
            $table->longText('details');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['referral_id', 'status']);
        });
        Schema::create('care_referral_funding_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('funding_case_id')->constrained('care_referral_funding_cases')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('outcome', 32);
            $table->longText('decision');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->unique(['funding_case_id', 'version'], 'care_funding_decision_version');
        });
        Schema::create('care_referral_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('proposal');
            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authored_at');
            $table->unique(['referral_id', 'version']);
        });
        Schema::create('care_referral_work_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('funding_case_id')->nullable()->constrained('care_referral_funding_cases')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40)->default('follow_up');
            $table->string('audience', 32)->default('intake');
            $table->string('status', 32)->default('open');
            $table->longText('details');
            $table->timestamp('due_at')->index();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['owner_id', 'status', 'due_at'], 'care_work_owner_status_due');
        });
        Schema::create('care_referral_evidence', function (Blueprint $table): void {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('previous_version_id')->nullable()->constrained('care_referral_evidence')->restrictOnDelete();
            $table->string('audience', 32)->default('assessment');
            $table->string('state', 32)->default('quarantined');
            $table->string('disk', 40)->default('local');
            $table->string('storage_path', 512);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->longText('metadata');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('care_referrals', function (Blueprint $table): void {
            $table->foreignId('primary_work_item_id')->nullable()->constrained('care_referral_work_items')->restrictOnDelete();
        });
        Schema::create('care_referral_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('referral_id')->nullable()->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 80);
            $table->string('audience', 32)->default('intake');
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->longText('payload');
            $table->timestamp('occurred_at');
            $table->index(['referral_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id', 'kind'], 'care_events_subject_kind');
        });
        Schema::create('care_referral_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('workflow_id')->nullable()->constrained('client_onboarding_workflows')->restrictOnDelete();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('created_person');
            $table->longText('receipt');
            $table->timestamp('converted_at');
        });
        Schema::create('care_referral_service_starts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('proposal_id')->constrained('care_referral_proposals')->restrictOnDelete();
            $table->foreignId('service_agreement_id')->constrained('service_agreements')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('scheduled');
            $table->date('agreed_date');
            $table->timestamp('commenced_at')->nullable();
            $table->longText('handover');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
        Schema::create('care_referral_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->string('deduplication_key', 160)->unique();
            $table->string('kind', 48);
            $table->string('state', 24)->default('pending');
            $table->longText('message');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error_code')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('care_referrals', fn (Blueprint $table) => $table->dropConstrainedForeignId('primary_work_item_id'));
        foreach (['care_referral_outbox', 'care_referral_service_starts', 'care_referral_conversions', 'care_referral_events', 'care_referral_evidence', 'care_referral_work_items', 'care_referral_proposals', 'care_referral_funding_decisions', 'care_referral_funding_cases', 'care_referral_submissions', 'care_referrals', 'care_referral_configuration', 'care_referral_settings_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
