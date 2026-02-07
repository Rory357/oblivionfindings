<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            
            // Budget identity
            $table->string('fiscal_year'); // 2025-2026
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            
            // Financials
            $table->decimal('total_budget', 14, 2);
            $table->string('currency')->default('NZD');
            
            // Status: drafting, proposed, under_review, approved, rejected
            $table->string('status')->default('drafting');
            
            // Workflow
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('proposed_at')->nullable();
            $table->foreignId('approval_resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
            $table->datetime('approved_by_board_at')->nullable();
            
            // Version control
            $table->integer('version_number')->default(1);
            $table->foreignId('supersedes_budget_id')->nullable()->constrained('budgets')->nullOnDelete();
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fiscal_year', 'status']);
            $table->unique(['fiscal_year', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
