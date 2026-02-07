<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_register_entries', function (Blueprint $table) {
            $table->id();
            $table->string('risk_reference')->unique(); // R-2026-001
            
            // Risk categorisation
            $table->string('category'); // client_safety, reputational, financial, it_cyber, workforce, legal_compliance, operational, clinical
            $table->string('title');
            $table->text('description');
            
            // Risk scoring (5x5 matrix)
            $table->tinyInteger('likelihood_score'); // 1-5
            $table->tinyInteger('impact_score'); // 1-5
            $table->tinyInteger('inherent_score'); // L x I
            $table->string('control_effectiveness')->default('none'); // none, weak, moderate, strong
            $table->tinyInteger('residual_score'); // after controls
            
            // Risk appetite
            $table->tinyInteger('appetite_threshold'); // Score above which board approval required
            $table->boolean('within_appetite')->default(false);
            
            // Ownership
            $table->foreignId('risk_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('risk_committee')->nullable(); // audit_risk, people, finance
            $table->string('review_frequency')->default('quarterly'); // monthly, quarterly, annual
            $table->date('next_review_date');
            
            // Status: active, mitigating, accepted, transferred, avoided, voided
            $table->string('status')->default('active');
            $table->string('mitigation_strategy')->nullable(); // treat, transfer, terminate, tolerate
            
            // Closure
            $table->text('closure_rationale')->nullable();
            $table->datetime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Key dates
            $table->date('identified_at');
            $table->foreignId('identified_by')->constrained('users');
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'status']);
            $table->index(['status', 'residual_score']);
            $table->index(['risk_owner_id', 'status']);
            $table->index('next_review_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_register_entries');
    }
};
