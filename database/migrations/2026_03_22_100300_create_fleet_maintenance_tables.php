<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->string('type');
            $table->json('items');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fleet_checklist_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('fleet_checklist_templates')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('responses');
            $table->boolean('passed')->default(true);
            $table->text('notes')->nullable();
            $table->dateTime('completed_at');
            $table->timestamps();

            $table->index(['asset_id', 'completed_at']);
        });

        Schema::create('fleet_work_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority')->default('medium');
            $table->string('category');
            $table->string('status')->default('open');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->decimal('actual_cost', 10, 2)->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['status', 'priority']);
        });

        Schema::create('fleet_service_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('interval_km')->nullable();
            $table->unsignedInteger('interval_days')->nullable();
            $table->dateTime('last_completed_at')->nullable();
            $table->decimal('last_completed_km', 10, 1)->nullable();
            $table->dateTime('next_due_at')->nullable();
            $table->decimal('next_due_km', 10, 1)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_service_schedules');
        Schema::dropIfExists('fleet_work_orders');
        Schema::dropIfExists('fleet_checklist_runs');
        Schema::dropIfExists('fleet_checklist_templates');
    }
};
