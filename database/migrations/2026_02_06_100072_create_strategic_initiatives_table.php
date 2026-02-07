<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategic_initiatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategic_goal_id')->constrained()->onDelete('cascade');
            
            // Initiative details
            $table->string('title');
            $table->text('description');
            
            // Budget
            $table->decimal('budget_allocated', 12, 2)->default(0);
            $table->decimal('budget_spent', 12, 2)->default(0);
            
            // Timeline
            $table->date('start_date');
            $table->date('target_completion');
            $table->date('actual_completion')->nullable();
            
            // Status
            $table->string('status')->default('planning'); // planning, in_progress, complete, on_hold, cancelled
            
            // Dependencies and risks
            $table->json('dependencies')->nullable(); // Other initiative IDs
            $table->json('risks')->nullable();
            
            // Owner
            $table->foreignId('owner_id')->constrained('users');
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['strategic_goal_id', 'status']);
            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_initiatives');
    }
};
