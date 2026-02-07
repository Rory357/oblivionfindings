<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategic_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategic_plan_id')->constrained()->onDelete('cascade');
            
            // Goal details
            $table->string('timeframe'); // 3_year, 5_year, annual
            $table->string('pillar'); // safety, quality, people, finance, compliance, it_resilience
            $table->string('title');
            $table->text('description');
            
            // OKR-style key results (JSON)
            $table->json('key_results')->nullable(); // [{description, target, current}]
            
            // Progress
            $table->decimal('progress_pct', 5, 2)->default(0);
            $table->string('status')->default('not_started'); // not_started, in_progress, achieved, at_risk, blocked
            
            // Ownership
            $table->foreignId('lead_executive_id')->constrained('users');
            
            // Risks
            $table->json('risks')->nullable();
            
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['strategic_plan_id', 'pillar']);
            $table->index(['strategic_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_goals');
    }
};
