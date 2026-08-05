<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_saved_ticket_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'it_saved_ticket_filters_user_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_saved_ticket_filters');
    }
};
