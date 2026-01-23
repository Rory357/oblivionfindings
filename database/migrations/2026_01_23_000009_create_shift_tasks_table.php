<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();

            $table->string('label');
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['shift_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_tasks');
    }
};
