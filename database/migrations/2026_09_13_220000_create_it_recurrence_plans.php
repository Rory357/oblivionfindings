<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_recurrence_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('cron_expression', 100);
            $table->string('timezone', 64)->default('Pacific/Auckland');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->json('exception_dates')->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->json('ticket_template');
            $table->string('status', 20)->default('active');
            $table->timestamp('next_due_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'next_due_at']);
        });
        Schema::create('it_recurrence_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('it_recurrence_plans')->cascadeOnDelete();
            $table->string('occurrence_key', 40);
            $table->string('status', 20);
            $table->foreignId('ticket_id')->nullable()->constrained('it_tickets')->nullOnDelete();
            $table->string('detail', 500)->nullable();
            $table->timestamp('created_at');
            $table->unique(['plan_id', 'occurrence_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_recurrence_runs');
        Schema::dropIfExists('it_recurrence_plans');
    }
};
