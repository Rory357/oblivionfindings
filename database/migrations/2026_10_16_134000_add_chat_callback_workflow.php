<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops_conversation_participants', fn (Blueprint $table) => $table->unsignedBigInteger('callback_history_after_id')->nullable());
        // Existing restricted memberships get a conservative boundary; no callback-history grants are invented.
        DB::table('ops_conversation_participants')->whereNotNull('history_after_id')->orderBy('id')->chunkById(200, function ($members) {
            foreach ($members as $member) {
                $boundary = DB::table('chat_callbacks')->where('conversation_id', $member->conversation_id)
                    ->when($member->history_joined_at, fn ($query) => $query->where('created_at', '<=', $member->history_joined_at))->max('id') ?? 0;
                DB::table('ops_conversation_participants')->where('id', $member->id)->update(['callback_history_after_id' => $boundary]);
            }
        });
        Schema::table('chat_callbacks', function (Blueprint $table) {
            $table->string('preferred_time', 300)->nullable();
            $table->string('agreed_time', 300)->nullable();
        });
        Schema::create('chat_callback_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('callback_id')->constrained('chat_callbacks');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 20);
            $table->timestamp('created_at');
        });
        Schema::create('chat_callback_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users');
            $table->uuid('mutation_id');
            $table->foreignId('callback_id')->constrained('chat_callbacks');
            $table->string('request_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'mutation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_callback_mutations');
        Schema::dropIfExists('chat_callback_events');
        Schema::table('chat_callbacks', fn (Blueprint $table) => $table->dropColumn(['preferred_time', 'agreed_time']));
        Schema::table('ops_conversation_participants', fn (Blueprint $table) => $table->dropColumn('callback_history_after_id'));
    }
};
