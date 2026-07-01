<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured child rows for HR performance-review goals, replacing the anemic
 * `hr_performance_reviews.goals` JSON blob. Additive: the JSON column is kept
 * during the transition (dual-write / read-with-fallback), so this is fully
 * reversible with zero data loss — see docs/PERFORMANCE_HUB_DATA_MODEL_UNIFICATION.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_review_goals')) {
            return;
        }

        Schema::create('hr_review_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_review_id')->constrained('hr_performance_reviews')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('description');
            // Optional link to an OKR objective this review goal maps to.
            $table->foreignId('hr_goal_id')->nullable()->constrained('hr_goals')->nullOnDelete();
            $table->string('status')->default('open'); // open | met | partially_met | missed
            $table->tinyInteger('rating')->nullable(); // 1-5, optional
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['performance_review_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_review_goals');
    }
};
