<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_staff_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('requirement_name', 255);
            $table->string('category', 50);
            $table->text('description')->nullable();
            $table->boolean('certification_required')->default(false);
            $table->integer('expiry_period_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'requirement_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_staff_requirements');
    }
};
