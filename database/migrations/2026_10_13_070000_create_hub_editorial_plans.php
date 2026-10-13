<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_editorial_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->date('target_date')->index();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('template', 30);
            $table->text('brief')->nullable();
            $table->json('audience');
            $table->foreignId('hub_page_id')->nullable()->unique()->constrained('hub_pages')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_editorial_plans');
    }
};
