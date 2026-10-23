<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_handover_attempts', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('actor_id'); $table->uuid('request_id');
            $table->string('action', 16); $table->unsignedBigInteger('shift_id'); $table->unsignedBigInteger('requested_handover_id')->nullable();
            $table->unsignedInteger('expected_version')->nullable(); $table->char('expected_source', 64); $table->char('intent_hash', 64);
            $table->timestamps();
            $table->unique(['actor_id', 'request_id']); $table->index(['shift_id', 'requested_handover_id'], 'handover_attempts_shift_request_index');
        });
        Schema::create('operations_handover_completions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('attempt_id')->unique();
            $table->unsignedBigInteger('handover_id'); $table->json('receipt'); $table->char('intended_after_hash', 64); $table->timestamps();
        });
        Schema::create('operations_handover_witnesses', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('attempt_id')->unique(); $table->char('key_id', 64); $table->char('signature', 64); $table->timestamps();
        });
        // IDs are retained evidence, not cascade/delete constraints on existing care records or accounts.
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_handover_witnesses'); Schema::dropIfExists('operations_handover_completions'); Schema::dropIfExists('operations_handover_attempts');
    }
};
