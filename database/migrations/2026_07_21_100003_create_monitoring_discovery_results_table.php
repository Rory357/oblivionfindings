<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_discovery_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discovery_run_id')->constrained('monitoring_discovery_runs')->restrictOnDelete();
            $table->foreignId('discovery_candidate_id')->nullable()->constrained('monitoring_discovery_candidates')->restrictOnDelete();
            $table->char('target_reference_hash', 64);
            $table->string('target_source', 16);
            $table->string('outcome', 16)->default('pending');
            $table->string('failure_code', 64)->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->unique(['discovery_run_id', 'target_reference_hash'], 'monitoring_discovery_results_run_target_uq');
            $table->index(['discovery_run_id', 'outcome'], 'monitoring_discovery_results_run_outcome_idx');
            $table->index(['discovery_candidate_id'], 'monitoring_discovery_results_candidate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_discovery_results');
    }
};
