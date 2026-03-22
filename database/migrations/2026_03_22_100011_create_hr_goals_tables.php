<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Goals
        Schema::create('hr_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('goal_type'); // individual, team, company
            $table->string('category')->nullable();
            $table->foreignId('parent_goal_id')->nullable()->constrained('hr_goals')->nullOnDelete();
            $table->decimal('target_value', 10, 2)->nullable();
            $table->decimal('current_value', 10, 2)->nullable();
            $table->string('unit')->nullable();
            $table->integer('progress_percentage')->default(0);
            $table->string('status')->default('draft'); // draft, active, completed, cancelled
            $table->string('priority')->default('medium'); // low, medium, high
            $table->date('start_date');
            $table->date('due_date');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('performance_review_id')->nullable()->constrained('hr_performance_reviews')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'goal_type']);
        });

        // Goal updates / progress log
        Schema::create('hr_goal_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained('hr_goals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('previous_value', 10, 2)->nullable();
            $table->decimal('new_value', 10, 2)->nullable();
            $table->integer('progress_percentage');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_goal_updates');
        Schema::dropIfExists('hr_goals');
    }
};
