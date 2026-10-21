<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_messages', function (Blueprint $table): void {
            $table->unsignedInteger('chat_edit_version')->default(0);
        });
        Schema::create('chat_message_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('ops_messages')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('mutation_id');
            $table->string('request_hash', 64);
            $table->unsignedInteger('version');
            // Restricted history: never included in normal Chat projections or logs.
            $table->longText('previous_state');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'mutation_id']);
            $table->unique(['message_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_revisions');
        Schema::table('ops_messages', fn (Blueprint $table) => $table->dropColumn('chat_edit_version'));
    }
};
