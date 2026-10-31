<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_risk_assessment_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assessment_id');
            $table->unsignedBigInteger('audit_log_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedInteger('sequence');
            $table->unsignedTinyInteger('schema_version');
            $table->string('action', 120);
            $table->json('actor_snapshot');
            $table->uuid('correlation_token');
            $table->unsignedBigInteger('prior_id')->nullable();
            $table->char('prior_digest', 64)->nullable();
            $table->unsignedBigInteger('before_site_id')->nullable();
            $table->unsignedBigInteger('after_site_id')->nullable();
            $table->unsignedBigInteger('before_client_id')->nullable();
            $table->unsignedBigInteger('after_client_id')->nullable();
            $table->boolean('has_exposure');
            $table->json('before_binding')->nullable();
            $table->json('after_binding');
            $table->json('before_business')->nullable();
            $table->json('business');
            $table->char('business_digest', 64);
            $table->char('before_binding_digest', 64);
            $table->char('after_binding_digest', 64);
            $table->char('acceptance_digest', 64);
            $table->dateTime('recorded_at', 6);
            $table->unique('audit_log_id', 'hsra_accept_audit_uq');
            $table->unique('correlation_token', 'hsra_accept_token_uq');
            $table->unique(['assessment_id', 'sequence'], 'hsra_accept_sequence_uq');
            $table->unique('prior_id', 'hsra_accept_prior_uq');
            foreach (['assessment_id' => 'hs_risk_assessments', 'audit_log_id' => 'audit_logs', 'actor_id' => 'users',
                'before_site_id' => 'sites', 'after_site_id' => 'sites', 'before_client_id' => 'clients', 'after_client_id' => 'clients',
                'prior_id' => 'hs_risk_assessment_acceptances'] as $column => $target) {
                $table->foreign($column, 'hsra_accept_'.$column.'_fk')->references('id')->on($target)->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (DB::table('hs_risk_assessment_acceptances')->exists()) {
            throw new RuntimeException('Retained risk acceptance prevents destructive rollback.');
        }
        Schema::drop('hs_risk_assessment_acceptances');
    }
};
