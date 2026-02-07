<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategic_plans', function (Blueprint $table) {
            $table->id();
            
            // Plan identity
            $table->string('title');
            $table->string('planning_horizon'); // 3_year, 5_year
            $table->date('period_start');
            $table->date('period_end');
            
            // Strategic content
            $table->text('vision_statement');
            $table->text('mission_statement');
            $table->json('values');
            
            // Status: draft, consultation, approved, archived
            $table->string('status')->default('draft');
            
            // Approval
            $table->foreignId('approval_resolution_id')->nullable()->constrained('resolutions')->nullOnDelete();
            $table->datetime('approved_by_board_at')->nullable();
            
            // Version control
            $table->integer('version_number')->default(1);
            $table->text('version_notes')->nullable();
            $table->foreignId('supersedes_plan_id')->nullable()->constrained('strategic_plans')->nullOnDelete();
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_plans');
    }
};
