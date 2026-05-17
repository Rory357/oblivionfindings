<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meal_recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('meal_recipes')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('meal_products')->nullOnDelete();
            $table->string('free_text_name')->nullable();
            $table->decimal('quantity', 12, 4);
            $table->string('unit', 24);
            $table->string('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['recipe_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_recipe_ingredients');
    }
};
