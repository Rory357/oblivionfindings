<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->foreignId('budget_line_item_id')->constrained()->onDelete('cascade');
            
            // Adjustment details
            $table->string('adjustment_type'); // increase, decrease, reallocate
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            
            // Approval workflow
            $table->string('status')->default('draft'); // draft, submitted, under_review, approved, rejected
            $table->boolean('threshold_applies')->default(false); // Requires board vote
            $table->foreignId('approval_resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Proposer
            $table->foreignId('proposed_by')->constrained('users');
            $table->datetime('proposed_at');
            
            // Reviewer notes
            $table->text('review_notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['budget_id', 'status']);
            $table->index(['threshold_applies', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_adjustments');
    }
};
