<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table): void {
            $table->boolean('independent_handling_requested')->default(false);
            $table->unsignedSmallInteger('independence_schema_version')->default(0);
            $table->unsignedInteger('independence_revision')->default(0);
            $table->index(['independent_handling_requested', 'site_id', 'status'], 'ci_independent_idx');
        });
        Schema::create('incident_independent_handling_declarations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('incident_id');
            $table->foreign('incident_id', 'iihd_incident_fk')->references('id')->on('client_incidents')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('source_tuple'); $table->json('handling'); $table->json('policy_snapshot');
            $table->unsignedBigInteger('actor_id');
            $table->foreign('actor_id', 'iihd_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->json('actor_snapshot'); $table->dateTime('recorded_at', 6); $table->char('content_digest', 64);
            $table->unsignedBigInteger('acceptance_id')->nullable();
            $table->foreign('acceptance_id', 'iihd_acceptance_fk')->references('id')->on('health_safety_setting_changes')->restrictOnDelete();
            $table->unique(['incident_id', 'revision'], 'iihd_revision_uq');
        });
        Schema::create('incident_independent_handling_exclusions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('declaration_id'); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('exclusion_id');
            $table->foreign('declaration_id', 'iihe_declaration_fk')->references('id')->on('incident_independent_handling_declarations')->restrictOnDelete();
            $table->foreign('user_id', 'iihe_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('exclusion_id', 'iihe_exclusion_fk')->references('id')->on('incident_owner_exclusions')->restrictOnDelete();
            $table->boolean('prior_existing'); $table->dateTime('recorded_at', 6);
            $table->unique(['declaration_id', 'user_id'], 'iihe_user_uq');
        });
    }
    public function down(): void
    {
        foreach (['incident_independent_handling_declarations', 'incident_independent_handling_exclusions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) { throw new RuntimeException('Independent-handling declarations and provenance must be retained.'); }
        }
        if (DB::table('client_incidents')->where(fn ($q) => $q->where('independent_handling_requested', true)->orWhere('independence_schema_version', '!=', 0)->orWhere('independence_revision', '!=', 0))->exists()
            || DB::table('health_safety_setting_changes')->where(fn ($q) => $q->where('setting_key', 'healthSafety.incidents.independence_policy')->orWhere('setting_key', 'like', 'healthSafety.incidents.independence_declaration.%'))->exists()
            || DB::table('app_settings')->where('key', 'healthSafety.incidents.independence_policy')->exists()) {
            throw new RuntimeException('Independent-handling policy and accepted source evidence must be retained.');
        }
        $revision = DB::table('app_settings')->where('key', 'healthSafety.incidents.independence_policy_revision')->first(['value']);
        if ($revision !== null && json_decode((string) $revision->value, true) !== 0) { throw new RuntimeException('Independent-handling policy revision evidence must be retained.'); }
        Schema::dropIfExists('incident_independent_handling_exclusions'); Schema::dropIfExists('incident_independent_handling_declarations');
        Schema::table('client_incidents', function (Blueprint $table): void {
            $table->dropIndex('ci_independent_idx'); $table->dropColumn(['independent_handling_requested', 'independence_schema_version', 'independence_revision']);
        });
        DB::table('app_settings')->where('key', 'healthSafety.incidents.independence_policy_revision')->delete();
    }
};
