<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_discovery_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('monitoring_collectors')->restrictOnDelete();
            $table->string('name');
            $table->json('cidrs');
            $table->json('seed_hosts')->nullable();
            $table->json('protocols');
            $table->json('exclusions')->nullable();
            $table->json('port_bounds')->nullable();
            $table->unsignedInteger('max_targets_per_run')->default(1024);
            $table->unsignedInteger('packets_per_second')->default(20);
            $table->string('schedule_cron')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['site_id', 'name'], 'monitoring_discovery_scopes_site_name_uq');
            $table->index(['status', 'site_id'], 'monitoring_discovery_scopes_status_site_idx');
            $table->index(['collector_id', 'status'], 'monitoring_discovery_scopes_collector_status_idx');
        });

        Schema::create('monitoring_discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discovery_scope_id')->constrained('monitoring_discovery_scopes')->restrictOnDelete();
            $table->uuid('run_uuid')->unique();
            $table->string('status')->default('queued');
            $table->string('trigger');
            $table->json('scope_snapshot');
            $table->unsignedInteger('planned_targets')->default(0);
            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('proposed_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('unresolved_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_summary', 500)->nullable();
            $table->timestamps();

            $table->index(['discovery_scope_id', 'status'], 'monitoring_discovery_runs_scope_status_idx');
            $table->index(['status', 'started_at'], 'monitoring_discovery_runs_status_started_idx');
        });

        Schema::create('monitoring_discovery_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discovery_run_id')->constrained('monitoring_discovery_runs')->restrictOnDelete();
            $table->uuid('candidate_uuid')->unique();
            $table->foreignId('canonical_device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->string('decision');
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->json('reasons');
            $table->json('evidence_snapshot');
            $table->char('evidence_hash', 64);
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_action')->nullable();
            $table->unsignedBigInteger('superseded_by_candidate_id')->nullable();
            $table->timestamps();

            $table->unique(['discovery_run_id', 'evidence_hash'], 'monitoring_discovery_candidates_run_evidence_uq');
            $table->index(['decision', 'reviewed_at'], 'monitoring_discovery_candidates_decision_review_idx');
            $table->foreign('superseded_by_candidate_id', 'monitoring_discovery_candidates_superseded_fk')
                ->references('id')->on('monitoring_discovery_candidates')->restrictOnDelete();
        });

        Schema::create('monitoring_device_identity_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('canonical_device_id')->constrained('devices')->restrictOnDelete();
            $table->string('evidence_type', 64);
            $table->char('value_hash', 64);
            $table->string('source', 128);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedTinyInteger('confidence');
            $table->boolean('is_active')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['canonical_device_id', 'evidence_type', 'value_hash', 'source'],
                'monitoring_device_identity_evidence_identity_uq',
            );
            $table->index(
                ['evidence_type', 'value_hash', 'is_active'],
                'monitoring_device_identity_evidence_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_device_identity_evidence');
        Schema::dropIfExists('monitoring_discovery_candidates');
        Schema::dropIfExists('monitoring_discovery_runs');
        Schema::dropIfExists('monitoring_discovery_scopes');
    }
};
