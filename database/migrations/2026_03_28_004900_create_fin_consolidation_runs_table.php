<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_consolidation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('fin_consolidation_groups')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fin_fiscal_periods')->nullOnDelete();
            $table->enum('status', ['draft', 'processing', 'completed', 'failed'])->default('draft');
            $table->decimal('total_revenue', 14, 2)->default(0);
            $table->decimal('total_expenses', 14, 2)->default(0);
            $table->decimal('total_assets', 14, 2)->default(0);
            $table->decimal('total_liabilities', 14, 2)->default(0);
            $table->decimal('total_equity', 14, 2)->default(0);
            $table->integer('eliminations_count')->default(0);
            $table->decimal('eliminations_amount', 14, 2)->default(0);
            $table->json('report_data')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_consolidation_runs');
    }
};
