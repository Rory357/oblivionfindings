<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────
        // 1. Utility actuals per period — enables estimate→actual true-up
        // ──────────────────────────────────────────────────────────
        Schema::create('site_utility_actuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_utility_id')->constrained('site_utilities')->cascadeOnDelete();
            $table->string('period', 7); // 'YYYY-MM'
            $table->decimal('amount', 10, 2);
            $table->string('reference')->nullable(); // Invoice number, etc.
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_utility_id', 'period'], 'site_util_actual_unique');
        });

        // ──────────────────────────────────────────────────────────
        // 2. Track what was posted (estimate vs actual) per utility per period
        //    so the true-up job knows if a reversal+repost is needed
        // ──────────────────────────────────────────────────────────
        Schema::create('site_utility_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_utility_id')->constrained('site_utilities')->cascadeOnDelete();
            $table->string('period', 7);
            $table->enum('posting_type', ['estimate', 'actual', 'true_up']);
            $table->decimal('amount', 10, 2);
            $table->foreignId('financial_event_id')->nullable()->constrained('fin_financial_events')->nullOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_utility_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_utility_postings');
        Schema::dropIfExists('site_utility_actuals');
    }
};
