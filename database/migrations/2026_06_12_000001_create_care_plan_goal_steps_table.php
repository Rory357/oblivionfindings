<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plan_goal_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('care_plan_goal_id')->constrained('care_plan_goals')->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_complete')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['care_plan_goal_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_goal_steps');
    }
};
