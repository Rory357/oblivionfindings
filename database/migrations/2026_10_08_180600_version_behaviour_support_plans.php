<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('behaviour_support_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->uuid('creation_key')->nullable()->unique('bsp_creation_key_unique');
            $table->char('creation_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('source_schema_version')->nullable();
            $table->foreignId('source_site_id')->nullable()->constrained('sites', 'id', 'bsp_source_site_fk')->restrictOnDelete();
            $table->foreignId('source_stay_id')->nullable()->constrained('respite_stays', 'id', 'bsp_source_stay_fk')->restrictOnDelete();
            $table->unsignedBigInteger('version_sequence')->default(0);
            $table->unsignedBigInteger('current_candidate_version_id')->nullable();
            $table->unsignedBigInteger('current_published_version_id')->nullable();
        });
        Schema::create('behaviour_support_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('behaviour_support_plan_id')->constrained('behaviour_support_plans', 'id', 'bspv_plan_fk')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('kind', 20);
            $table->foreignId('candidate_version_id')->nullable()->constrained('behaviour_support_plan_versions', 'id', 'bspv_candidate_fk')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients', 'id', 'bspv_client_fk')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites', 'id', 'bspv_site_fk')->restrictOnDelete();
            $table->foreignId('stay_id')->nullable()->constrained('respite_stays', 'id', 'bspv_stay_fk')->restrictOnDelete();
            $table->string('context_origin', 40);
            $table->json('content');
            $table->char('content_digest', 64);
            $table->foreignId('recorded_by')->nullable()->constrained('users', 'id', 'bspv_recorder_fk')->nullOnDelete();
            $table->json('actor_snapshot');
            $table->dateTime('recorded_at', 6);
            $table->foreignId('published_by')->nullable()->constrained('users', 'id', 'bspv_publisher_fk')->nullOnDelete();
            $table->dateTime('published_at', 6)->nullable();
            $table->dateTime('effective_from', 6)->nullable();
            $table->dateTime('effective_to', 6)->nullable();
            $table->date('review_date')->nullable();
            $table->uuid('command_key');
            $table->unsignedBigInteger('originating_review_id')->nullable();
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->unique(['behaviour_support_plan_id', 'sequence'], 'bsp_version_sequence_unique');
        });
        Schema::create('behaviour_support_plan_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('behaviour_support_plan_id')->constrained('behaviour_support_plans', 'id', 'bspa_plan_fk')->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained('behaviour_support_plan_versions', 'id', 'bspa_version_fk')->restrictOnDelete();
            $table->char('content_digest', 64);
            $table->foreignId('client_id')->constrained('clients', 'id', 'bspa_client_fk')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites', 'id', 'bspa_site_fk')->restrictOnDelete();
            $table->foreignId('stay_id')->nullable()->constrained('respite_stays', 'id', 'bspa_stay_fk')->restrictOnDelete();
            $table->string('decision', 20);
            $table->string('origin', 40);
            $table->foreignId('approver_id')->nullable()->constrained('users', 'id', 'bspa_approver_fk')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users', 'id', 'bspa_recorder_fk')->nullOnDelete();
            $table->json('approver_snapshot');
            $table->json('recorder_snapshot');
            $table->dateTime('approved_at', 6);
            $table->dateTime('recorded_at', 6);
            $table->dateTime('effective_from', 6);
            $table->dateTime('effective_to', 6)->nullable();
            $table->text('basis');
            $table->json('evidence_references');
            $table->json('policy_snapshot');
            $table->foreignId('setting_change_id')->constrained('health_safety_setting_changes', 'id', 'bspa_setting_change_fk')->restrictOnDelete();
            $table->foreignId('supersedes_approval_id')->nullable()->constrained('behaviour_support_plan_approvals', 'id', 'bspa_supersedes_fk')->restrictOnDelete();
            $table->uuid('command_key')->unique('bspa_command_key_unique');
            $table->foreignId('audit_id')->nullable()->constrained('audit_logs', 'id', 'bspa_audit_fk')->restrictOnDelete();
        });
        Schema::table('behaviour_support_plan_versions', function (Blueprint $table): void {
            $table->foreign('approval_id', 'bsp_version_approval_fk')->references('id')->on('behaviour_support_plan_approvals')->restrictOnDelete();
        });
        Schema::table('behaviour_support_plans', function (Blueprint $table): void {
            $table->foreign('current_candidate_version_id', 'bsp_current_candidate_fk')->references('id')->on('behaviour_support_plan_versions')->restrictOnDelete();
            $table->foreign('current_published_version_id', 'bsp_current_published_fk')->references('id')->on('behaviour_support_plan_versions')->restrictOnDelete();
        });
        Schema::table('behaviour_support_plan_reviews', function (Blueprint $table): void {
            $table->dateTime('reviewed_at', 6)->change();
            $table->foreignId('plan_version_id')->nullable()->constrained('behaviour_support_plan_versions', 'id', 'bspr_version_fk')->restrictOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('behaviour_support_plan_approvals', 'id', 'bspr_approval_fk')->restrictOnDelete();
            $table->string('requested_resulting_status')->nullable();
            $table->string('approval_disposition', 40)->nullable();
            $table->json('reviewer_snapshot')->nullable();
            $table->unsignedBigInteger('source_version')->nullable();
            $table->uuid('command_key')->nullable()->unique('bspr_command_key_unique');
            $table->foreignId('audit_id')->nullable()->constrained('audit_logs', 'id', 'bspr_audit_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['behaviour_support_plan_versions', 'behaviour_support_plan_approvals'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Plan version and approval evidence must be retained; rollback is refused.');
            }
        }
        if (DB::table('behaviour_support_plans')->whereNotNull('creation_key')->orWhereNotNull('creation_fingerprint')->orWhereNotNull('source_schema_version')->orWhereNotNull('source_site_id')->orWhereNotNull('source_stay_id')->orWhereNotNull('current_candidate_version_id')->orWhereNotNull('current_published_version_id')->orWhere('lock_version', '>', 1)->orWhere('version_sequence', '>', 0)->exists()
            || DB::table('behaviour_support_plan_reviews')->whereNotNull('plan_version_id')->orWhereNotNull('approval_id')->orWhereNotNull('command_key')->orWhereNotNull('requested_resulting_status')->orWhereNotNull('approval_disposition')->orWhereNotNull('reviewer_snapshot')->orWhereNotNull('source_version')->orWhereNotNull('audit_id')->exists()) {
            throw new RuntimeException('New plan command provenance must be retained; rollback is refused.');
        }
        Schema::table('behaviour_support_plan_reviews', function (Blueprint $table): void {
            $table->dropForeign('bspr_version_fk');
            $table->dropForeign('bspr_approval_fk');
            $table->dropForeign('bspr_audit_fk');
            $table->dropUnique('bspr_command_key_unique');
            $table->dropColumn(['plan_version_id', 'approval_id', 'requested_resulting_status', 'approval_disposition', 'reviewer_snapshot', 'source_version', 'command_key', 'audit_id']);
        });
        Schema::table('behaviour_support_plans', function (Blueprint $table): void {
            $table->dropForeign('bsp_current_candidate_fk');
            $table->dropForeign('bsp_current_published_fk');
            $table->dropForeign('bsp_source_site_fk');
            $table->dropForeign('bsp_source_stay_fk');
            $table->dropUnique('bsp_creation_key_unique');
            $table->dropColumn(['lock_version', 'creation_key', 'creation_fingerprint', 'source_schema_version', 'source_site_id', 'source_stay_id', 'version_sequence', 'current_candidate_version_id', 'current_published_version_id']);
        });
        Schema::table('behaviour_support_plan_versions', fn (Blueprint $table) => $table->dropForeign('bsp_version_approval_fk'));
        Schema::dropIfExists('behaviour_support_plan_approvals');
        Schema::dropIfExists('behaviour_support_plan_versions');
    }
};
