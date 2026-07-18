<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_work_task_dependencies', function (Blueprint $table): void {
            $table->foreignId('task_id')->constrained('it_work_tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('it_work_tasks')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['task_id', 'depends_on_task_id'], 'it_work_task_dependencies_pk');
            $table->index('depends_on_task_id', 'it_work_task_dependencies_reverse_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_work_task_dependencies');
    }
};
