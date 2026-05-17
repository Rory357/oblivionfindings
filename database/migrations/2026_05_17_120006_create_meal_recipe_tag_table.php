<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meal_recipe_tag', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained('meal_recipes')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('meal_dietary_tags')->cascadeOnDelete();
            $table->primary(['recipe_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_recipe_tag');
    }
};
