<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('ops_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('start');
            $table->unsignedInteger('length');
            $table->string('label', 256);
            $table->timestamp('created_at');
            $table->unique(['message_id', 'start']);
            $table->index(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_mentions');
    }
};
