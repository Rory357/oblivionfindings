<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meal_product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('meal_products')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('meal_dietary_tags')->cascadeOnDelete();
            $table->primary(['product_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_product_tag');
    }
};
