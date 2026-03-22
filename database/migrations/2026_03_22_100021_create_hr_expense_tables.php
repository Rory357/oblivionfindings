<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_expense_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('claim_number');
            $table->string('title');
            $table->string('status')->default('draft'); // draft, submitted, approved, rejected, paid
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('currency')->default('NZD');
            $table->datetime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->datetime('paid_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'claim_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained('hr_expense_claims')->cascadeOnDelete();
            $table->string('description');
            $table->string('category'); // travel, meals, accommodation, supplies, mileage, other
            $table->decimal('amount', 10, 2);
            $table->date('expense_date');
            $table->string('receipt_path')->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_expense_items');
        Schema::dropIfExists('hr_expense_claims');
    }
};
