<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            
            // Line item details
            $table->string('category'); // staffing, operations, fleet, compliance, capital, admin
            $table->string('subcategory')->nullable();
            $table->string('description');
            $table->string('account_code')->nullable();
            
            // Budget amounts
            $table->decimal('budget_amount', 12, 2);
            $table->decimal('forecast_amount', 12, 2)->nullable(); // Updated quarterly
            $table->decimal('actual_amount', 12, 2)->default(0); // Rolled up from transactions
            
            // Variance tracking
            $table->decimal('variance_amount', 12, 2)->nullable();
            $table->decimal('variance_pct', 5, 2)->nullable();
            $table->text('variance_explanation')->nullable();
            $table->boolean('variance_explained')->default(false);
            
            // Notes
            $table->text('notes')->nullable();
            
            $table->timestamps();

            $table->index(['budget_id', 'category']);
            $table->index(['budget_id', 'variance_pct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_line_items');
    }
};
