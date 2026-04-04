<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_event_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timeline_event_id')->constrained('timeline_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 10);
            $table->timestamps();

            $table->unique(['timeline_event_id', 'user_id', 'emoji']);
            $table->index('timeline_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_event_reactions');
    }
};
