<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obli_computer_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('session_hash', 64);
            $table->uuid('request_uuid');
            $table->string('intent_hash', 64);
            $table->unsignedBigInteger('site_id');
            $table->string('challenge_hash', 64)->unique();
            $table->text('challenge_encrypted')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->uuid('agent_uuid')->nullable();
            $table->string('assignment_fingerprint', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_uuid']);
            $table->index(['expires_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obli_computer_contexts');
    }
};
