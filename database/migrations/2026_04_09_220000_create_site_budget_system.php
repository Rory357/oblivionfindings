<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────
        // Site Budget Lines — month-level operational budgets per site
        //
        // Complements the existing governance Budget system (annual,
        // board-approved). This table enables operational managers to
        // set monthly budgets by site + category for variance tracking.
        //
        // Actuals are NEVER stored here — they are calculated
        // dynamically from fin_cost_allocations.
        // ──────────────────────────────────────────────────────────
        Schema::create('site_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();

            // Period: month granularity
            $table->string('period', 7); // 'YYYY-MM'

            // Category: maps to event_type groups in fin_cost_allocations
            $table->string('category', 50); // payroll, rent, utilities, maintenance, fleet, house_operating, other

            // Budget
            $table->decimal('planned_amount', 12, 2);
            $table->text('notes')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'period', 'category'], 'site_budget_unique');
            $table->index(['tenant_id', 'period']);
            $table->index(['site_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_budget_lines');
    }
};
