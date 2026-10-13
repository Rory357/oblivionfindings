<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_planning_records', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 64);
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->date('due_date');
            $table->string('status', 24)->default('planned');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('details');
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['kind', 'site_id', 'status', 'due_date'], 'work_planning_scope');
            $table->index(['owner_id', 'kind']);
        });
        Schema::create('work_planning_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('work_planning_records')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['record_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_planning_revisions');
        Schema::dropIfExists('work_planning_records');
    }
};
