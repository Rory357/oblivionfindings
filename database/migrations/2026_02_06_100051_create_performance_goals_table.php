<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_review_id')->constrained()->onDelete('cascade');
            
            // Goal categorisation
            $table->string('pillar'); // safety, quality, people, finance, compliance, it_resilience
            $table->text('goal_description');
            $table->text('success_criteria');
            
            // Weighting
            $table->decimal('weight', 5, 2)->default(0); // Percentage
            $table->decimal('target_score', 3, 1)->default(3.0); // 1-5
            $table->decimal('actual_score', 3, 1)->nullable();
            
            // Assessments
            $table->text('self_assessment')->nullable();
            $table->text('board_assessment')->nullable();
            
            // Evidence
            $table->json('evidence_links')->nullable();
            $table->text('evidence_summary')->nullable();
            
            // Status: not_started, in_progress, achieved, partially_achieved, missed
            $table->string('status')->default('not_started');
            
            $table->timestamps();

            $table->index(['performance_review_id', 'pillar']);
            $table->index(['performance_review_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_goals');
    }
};
