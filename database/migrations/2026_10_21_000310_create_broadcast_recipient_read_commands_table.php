<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_recipient_read_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('command_id');
            $table->unsignedSmallInteger('schema_version');
            $table->string('operation', 8);
            $table->char('request_fingerprint', 64);
            // Owner validates an ordered reference-only array of at most 200 members.
            // Capture is committed before effects; effects cannot replace this batch.
            $table->json('original_references');
            $table->char('batch_digest', 64);
            $table->string('state', 10);
            $table->dateTime('captured_at', 6);
            $table->dateTime('intended_read_at', 6);
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->json('result')->nullable();
            $table->timestamps(6);
            $table->unique(['actor_user_id', 'command_id'], 'broadcast_read_actor_command_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipient_read_commands');
    }
};
