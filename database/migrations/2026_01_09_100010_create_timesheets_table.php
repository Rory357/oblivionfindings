<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            $table->date('work_date')->index();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('break_minutes')->default(0);
            $table->text('notes')->nullable();

            $table->string('status')->default('draft')->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'work_date']);
            $table->index(['client_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheets');
    }
};
