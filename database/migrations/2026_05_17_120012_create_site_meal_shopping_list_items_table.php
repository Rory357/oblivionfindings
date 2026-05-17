<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_meal_shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('site_meal_shopping_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('meal_products')->nullOnDelete();
            $table->string('free_text_name')->nullable();
            $table->decimal('needed_qty', 14, 4);
            $table->string('unit', 24);
            $table->enum('source', ['meal_plan', 'restock_to_par', 'manual'])->default('manual');
            $table->json('source_meta')->nullable();
            $table->decimal('received_qty', 14, 4)->nullable();
            $table->unsignedInteger('estimated_cost_cents')->nullable();
            $table->boolean('is_checked')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['list_id', 'source'], 'smsli_list_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_meal_shopping_list_items');
    }
};
