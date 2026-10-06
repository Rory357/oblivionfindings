<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('external_clinical_account')->default(false)->index());
        Schema::create('medication_external_clinicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('provider_name');
            $table->string('registration_authority');
            $table->string('registration_number', 100);
            $table->text('identity_evidence');
            $table->foreignId('verified_by')->constrained('users');
            $table->timestamp('identity_verified_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->text('revoke_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('medication_external_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinician_id')->constrained('medication_external_clinicians', indexName: 'meg_clinician_fk');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('site_id')->constrained('sites');
            $table->text('purpose');
            $table->boolean('can_propose')->default(false);
            $table->boolean('include_controlled')->default(false);
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->text('revoke_reason')->nullable();
            $table->timestamps();
            $table->index(['clinician_id', 'client_id', 'revoked_at'], 'meg_named_person');
        });
        Schema::create('medication_external_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinician_id')->constrained('medication_external_clinicians', indexName: 'mep_clinician_fk');
            $table->foreignId('grant_id')->constrained('medication_external_grants');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('medication_id')->nullable()->constrained('client_medications');
            $table->unsignedInteger('expected_version')->nullable();
            $table->string('kind', 20);
            $table->boolean('controlled')->default(false);
            $table->longText('prescription')->nullable();
            $table->text('reason');
            $table->string('status', 30)->default('submitted');
            $table->string('request_key', 100);
            $table->char('payload_sha256', 64);
            $table->string('source_file_path')->nullable();
            $table->string('source_file_name')->nullable();
            $table->char('source_file_sha256', 64)->nullable();
            $table->timestamp('submitted_at');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('revision_id')->nullable()->constrained('medication_order_revisions');
            $table->unsignedInteger('ceased_version')->nullable();
            $table->text('decision_evidence')->nullable();
            $table->timestamps();
            $table->unique(['clinician_id', 'request_key'], 'mep_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_external_proposals');
        Schema::dropIfExists('medication_external_grants');
        Schema::dropIfExists('medication_external_clinicians');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('external_clinical_account'));
    }
};
