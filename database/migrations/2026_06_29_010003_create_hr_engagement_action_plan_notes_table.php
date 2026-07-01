<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes / activity timeline for an engagement action plan. The Update Action Plan
 * modal renders these newest-first with author name and relative time. System
 * entries (plan created, status changed) are also captured here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_engagement_action_plan_notes')) {
            return;
        }

        Schema::create('hr_engagement_action_plan_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('hr_engagement_action_plans')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind')->default('note'); // note | system
            $table->text('body');
            $table->timestamps();

            $table->index(['plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_engagement_action_plan_notes');
    }
};
