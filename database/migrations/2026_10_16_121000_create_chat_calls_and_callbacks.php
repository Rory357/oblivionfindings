<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('availability', 20)->default('unavailable');
            $table->boolean('sound_enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('chat_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_id')->constrained('ops_conversations');
            $table->foreignId('started_by')->constrained('users');
            $table->foreignId('host_id')->constrained('users');
            $table->uuid('mutation_id');
            $table->string('status', 20)->default('ringing');
            $table->timestamp('ring_until');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['started_by', 'mutation_id']);
        });
        Schema::create('chat_call_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('call_id')->constrained('chat_calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->uuid('media_identity')->unique();
            $table->uuid('device_id')->nullable();
            $table->string('status', 20)->default('invited');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['call_id', 'user_id']);
        });
        Schema::create('chat_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ops_conversations');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('requested');
            $table->text('request_note')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_callbacks');
        Schema::dropIfExists('chat_call_participants');
        Schema::dropIfExists('chat_calls');
        Schema::dropIfExists('chat_preferences');
    }
};
