<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_call_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('call_id')->constrained('chat_calls');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('client_note_id')->constrained('client_notes');
            $table->foreignId('family_note_id')->nullable()->constrained('family_notes');
            $table->timestamps();
            $table->unique(['call_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_records');
    }
};
