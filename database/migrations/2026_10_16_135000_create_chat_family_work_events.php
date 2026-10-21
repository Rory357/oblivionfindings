<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_family_work_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ops_conversations');
            $table->foreignId('actor_id')->constrained('users');
            $table->uuid('mutation_id');
            $table->string('request_hash', 64);
            $table->string('action', 20);
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->string('queue_status', 20);
            $table->text('encrypted_note')->nullable();
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'mutation_id']);
            $table->index(['conversation_id', 'id']);
        });
        Schema::table('ops_conversation_participants', fn (Blueprint $table) => $table->unsignedBigInteger('family_work_history_after_id')->nullable());
        // This is a new, empty history. Existing restricted memberships may see future events only.
        DB::table('ops_conversation_participants')->whereNotNull('history_after_id')->update(['family_work_history_after_id' => 0]);
    }

    public function down(): void
    {
        Schema::table('ops_conversation_participants', fn (Blueprint $table) => $table->dropColumn('family_work_history_after_id'));
        Schema::dropIfExists('chat_family_work_events');
    }
};
