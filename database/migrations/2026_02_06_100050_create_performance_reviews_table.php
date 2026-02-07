<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reviewee_id')->constrained('users'); // CEO typically
            
            // Review cycle
            $table->string('review_cycle'); // 2026-Q1, 2026-Annual
            $table->string('review_type'); // quarterly, annual, ad_hoc
            $table->date('period_start');
            $table->date('period_end');
            
            // Status: drafting, self_review, peer_review, board_review, completed
            $table->string('status')->default('drafting');
            
            // Overall assessment
            $table->string('overall_rating')->nullable(); // exceeds, meets, needs_improvement, unsatisfactory
            $table->text('overall_assessment')->nullable();
            
            // Board decision
            $table->string('board_decision')->nullable(); // remuneration_increase, maintain, development_plan, performance_improvement
            $table->text('decision_notes')->nullable();
            $table->foreignId('approval_resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
            $table->datetime('approved_by_board_at')->nullable();
            
            // Self assessment
            $table->text('self_assessment')->nullable();
            $table->datetime('self_assessment_submitted_at')->nullable();
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reviewee_id', 'review_cycle']);
            $table->index(['status', 'period_end']);
            $table->unique(['reviewee_id', 'review_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
    }
};
