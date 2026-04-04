<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_event_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timeline_event_id')->constrained('timeline_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['timeline_event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_event_comments');
    }
};
