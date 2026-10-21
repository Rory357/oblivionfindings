<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_notes', fn (Blueprint $table) => $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete());
        Schema::create('chat_family_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ops_conversations');
            $table->foreignId('actor_id')->constrained('users');
            $table->uuid('mutation_id');
            $table->string('request_hash', 64);
            $table->foreignId('family_note_id')->constrained('family_notes');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'mutation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_family_followups');
        Schema::table('family_notes', fn (Blueprint $table) => $table->dropConstrainedForeignId('assigned_to_user_id'));
    }
};
