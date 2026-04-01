<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_key_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('goal_id')->constrained('hr_goals')->cascadeOnDelete();
            $table->string('title');
            $table->decimal('target_value', 10, 2)->default(100);
            $table->decimal('current_value', 10, 2)->default(0);
            $table->string('unit')->nullable(); // %, count, NZD, hours, etc.
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->string('status')->default('not_started'); // not_started, in_progress, completed, cancelled
            $table->date('due_date')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['goal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_key_results');
    }
};
