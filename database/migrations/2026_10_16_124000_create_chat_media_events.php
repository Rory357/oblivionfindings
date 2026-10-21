<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_media_events', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->foreignUuid('call_id')->constrained('chat_calls');
            $table->string('event', 60);
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_media_events');
    }
};
