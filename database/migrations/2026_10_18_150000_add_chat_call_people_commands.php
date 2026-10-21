<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_call_participants', function (Blueprint $table): void {
            $table->unsignedInteger('invitation_version')->default(1);
            $table->foreignId('invited_by')->nullable()->constrained('users');
        });
        Schema::create('chat_call_people_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('call_id')->constrained('chat_calls')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->uuid('mutation_id');
            $table->string('action', 20);
            $table->char('payload_hash', 64);
            $table->foreignId('target_id')->nullable()->constrained('users');
            $table->uuid('invitation_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['call_id', 'actor_id', 'mutation_id'], 'chat_call_people_command_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_people_commands');
        Schema::table('chat_call_participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('invitation_version');
        });
    }
};
