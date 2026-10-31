<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_owner_changes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('source_schema_version')->default(0);
            $table->unsignedBigInteger('audit_log_id')->nullable();
            $table->foreign('audit_log_id', 'ioc_routing_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
        });
        Schema::create('incident_routing_intents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->foreign('incident_id', 'iri_incident_fk')->references('id')->on('client_incidents')->restrictOnDelete();
            $table->unsignedInteger('owner_revision');
            $table->string('kind', 16);
            $table->uuid('request_uuid')->unique('iri_request_uq');
            $table->json('source_tuple');
            $table->char('source_digest', 64);
            $table->unsignedBigInteger('trigger_actor_id')->nullable();
            $table->foreign('trigger_actor_id', 'iri_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->string('trigger_actor_name')->nullable();
            $table->string('state', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code', 40)->nullable();
            $table->dateTime('evaluated_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['incident_id', 'owner_revision', 'kind'], 'iri_source_cycle_uq');
            $table->index(['state', 'id'], 'iri_pending_idx');
        });
        Schema::create('incident_routing_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('intent_id');
            $table->foreign('intent_id', 'ird_intent_fk')->references('id')->on('incident_routing_intents')->restrictOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('state', 24);
            $table->string('reason_code', 40);
            $table->json('source_tuple');
            $table->char('source_digest', 64);
            $table->unsignedBigInteger('setting_change_id')->nullable();
            $table->foreign('setting_change_id', 'ird_setting_fk')->references('id')->on('health_safety_setting_changes')->restrictOnDelete();
            $table->unsignedBigInteger('configuration_revision')->nullable();
            $table->char('configuration_digest', 64)->nullable();
            $table->json('configuration')->nullable();
            $table->unsignedBigInteger('owner_change_id')->nullable();
            $table->foreign('owner_change_id', 'ird_change_fk')->references('id')->on('incident_owner_changes')->restrictOnDelete();
            $table->unsignedBigInteger('audit_log_id');
            $table->foreign('audit_log_id', 'ird_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->dateTime('escalate_at', 6)->nullable();
            $table->unique(['intent_id', 'attempt'], 'ird_attempt_uq');
        });
        Schema::create('incident_triage_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('decision_id');
            $table->foreign('decision_id', 'itd_decision_fk')->references('id')->on('incident_routing_decisions')->restrictOnDelete();
            $table->unsignedBigInteger('recipient_user_id');
            $table->foreign('recipient_user_id', 'itd_recipient_fk')->references('id')->on('users')->restrictOnDelete();
            $table->string('recipient_role', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code', 40)->nullable();
            $table->dateTime('due_at', 6);
            $table->dateTime('delivered_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['decision_id', 'recipient_role'], 'itd_role_uq');
            $table->index(['status', 'due_at'], 'itd_due_idx');
        });
    }

    public function down(): void
    {
        foreach (['incident_routing_intents', 'incident_routing_decisions', 'incident_triage_deliveries'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Incident routing, triage and delivery evidence must be retained.');
            }
        }
        if (DB::table('app_settings')->whereIn('key', ['healthSafety.incidents.owner_routing', 'healthSafety.incidents.owner_routing_revision'])->exists()
            || DB::table('health_safety_setting_changes')->where('setting_key', 'healthSafety.incidents.owner_routing')->exists()) {
            throw new RuntimeException('Configured incident routing must be retained.');
        }
        if (DB::table('incident_owner_changes')->where(fn ($query) => $query->whereNotNull('audit_log_id')->orWhere('source_schema_version', '!=', 0))->exists()) {
            throw new RuntimeException('Accepted ownership provenance must be retained.');
        }
        Schema::dropIfExists('incident_triage_deliveries');
        Schema::dropIfExists('incident_routing_decisions');
        if (DB::table('incident_owner_changes')->whereNotNull('audit_log_id')->exists()) {
            throw new RuntimeException('Accepted ownership audit evidence must be retained.');
        }
        Schema::dropIfExists('incident_routing_intents');
        Schema::table('incident_owner_changes', function (Blueprint $table): void {
            $table->dropForeign('ioc_routing_audit_fk');
            $table->dropColumn(['audit_log_id', 'source_schema_version']);
        });
    }
};
