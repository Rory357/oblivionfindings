<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meal_dietary_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('key', 64);
            $table->string('label');
            $table->enum('kind', ['dietary', 'allergen'])->default('dietary');
            $table->enum('severity', ['info', 'warn', 'critical'])->default('info');
            $table->string('color', 16)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['kind', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_dietary_tags');
    }
};
