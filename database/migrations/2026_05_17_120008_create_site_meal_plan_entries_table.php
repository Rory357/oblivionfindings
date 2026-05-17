<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_meal_plan_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->date('plan_date');
            $table->enum('meal_slot', [
                'breakfast',
                'morning_tea',
                'lunch',
                'afternoon_tea',
                'dinner',
                'supper',
            ]);
            $table->foreignId('recipe_id')->nullable()->constrained('meal_recipes')->nullOnDelete();
            $table->string('ad_hoc_name')->nullable();
            $table->unsignedSmallInteger('servings')->default(1);
            $table->text('notes')->nullable();
            $table->json('client_ids')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'plan_date'], 'smpe_site_date_idx');
            $table->index(['site_id', 'plan_date', 'meal_slot'], 'smpe_site_date_slot_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_meal_plan_entries');
    }
};
