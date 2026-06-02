<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_meal_week_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            // meals: [{ day: 0-6 (Mon-Sun), slot, recipe_id, servings }]
            $table->json('meals')->nullable();
            $table->boolean('is_starter')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'name'], 'smwt_site_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_meal_week_templates');
    }
};
