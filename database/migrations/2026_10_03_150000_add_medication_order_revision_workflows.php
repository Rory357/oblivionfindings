<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_order_versions', function (Blueprint $table) {
            $table->json('prescription_payload')->nullable();
            $table->json('source_evidence')->nullable();
            $table->string('entry_request_key', 100)->nullable()->unique();
            $table->char('entry_payload_sha256', 64)->nullable();
        });
        Schema::create('medication_order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_medication_id')->constrained('client_medications');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('medication_order_version_id')->unique()->constrained('medication_order_versions');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('base_version');
            $table->foreignId('entered_by')->nullable()->constrained('users');
            $table->foreignId('read_back_witness_id')->nullable()->constrained('users');
            $table->foreignId('checked_by')->nullable()->constrained('users');
            $table->timestamp('checked_at')->nullable();
            $table->text('lone_reason')->nullable();
            $table->timestamp('second_due_at')->nullable();
            $table->foreignId('second_checked_by')->nullable()->constrained('users');
            $table->timestamp('second_checked_at')->nullable();
            $table->timestamp('written_due_at')->nullable();
            $table->json('written_confirmation')->nullable();
            $table->json('allergy_confirmation')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['client_medication_id', 'status']);
        });
        Schema::create('medication_order_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_medication_id')->constrained('client_medications');
            $table->foreignId('medication_order_revision_id')->nullable()->constrained('medication_order_revisions');
            $table->string('action', 60);
            $table->json('evidence');
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('occurred_at');
        });
        Schema::create('medication_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('respite_stay_id')->nullable()->constrained('respite_stays');
            $table->string('reason', 40);
            $table->text('sources');
            $table->string('status', 30)->default('draft');
            $table->boolean('support_reassessment_required')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('signed_off_by')->nullable()->constrained('users');
            $table->timestamp('signed_off_at')->nullable();
            $table->timestamps();
        });
        Schema::create('medication_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_reconciliation_id')->constrained('medication_reconciliations');
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications');
            $table->foreignId('medication_order_revision_id')->nullable()->constrained('medication_order_revisions');
            $table->string('medicine_name');
            $table->boolean('controlled')->default(false);
            $table->string('decision', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('source_order')->nullable();
            $table->json('last_dose_evidence')->nullable();
            $table->timestamp('next_dose_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('prescriber_query_resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('medication_order_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_order_revision_id')->nullable()->constrained('medication_order_revisions');
            $table->foreignId('medication_reconciliation_id')->nullable()->constrained('medication_reconciliations');
            $table->foreignId('medication_covert_authorisation_id')->nullable()->constrained('medication_covert_authorisations');
            $table->string('purpose', 30);
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('created_at');
        });
        // Clinical mappings are deliberately empty. A maintained, attributable
        // source must be installed; this migration does not invent drug policy.
        Schema::create('medication_allergy_class_rules', function (Blueprint $table) {
            $table->id();
            $table->string('allergen');
            $table->string('medicine');
            $table->string('source');
            $table->string('source_version');
            $table->timestamp('reviewed_at');
            $table->timestamp('expires_at');
            $table->unique(['allergen', 'medicine', 'source_version'], 'med_allergy_class_rule_unique');
        });
        Schema::table('medication_covert_authorisations', function (Blueprint $table) {
            $table->json('structured_evidence')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->text('revoke_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('medication_covert_authorisations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['structured_evidence', 'revoked_at', 'revoke_reason']);
        });
        foreach (['medication_allergy_class_rules', 'medication_order_files', 'medication_reconciliation_items', 'medication_reconciliations', 'medication_order_actions', 'medication_order_revisions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('medication_order_versions', function (Blueprint $table) {
            $table->dropUnique(['entry_request_key']);
            $table->dropColumn(['prescription_payload', 'source_evidence', 'entry_request_key', 'entry_payload_sha256']);
        });
    }
};
