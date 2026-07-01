<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_goal_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name'); // picker label, e.g. "Reduce medication errors"
            $table->string('title'); // prefilled objective title
            $table->text('description')->nullable();
            $table->string('goal_type')->default('team');
            $table->string('category')->nullable();
            $table->string('priority')->default('medium');
            // KR blueprints: [{title, kr_type, start_value, target_value, unit, weight}]
            $table->json('key_results')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_goal_templates');
    }
};
