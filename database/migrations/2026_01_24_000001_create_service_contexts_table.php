<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_contexts', function (Blueprint $table) {
            $table->id();
            // Stable code enum stored as string for portability.
            $table->string('type', 50)->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_contexts');
    }
};
