<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_external_heartbeat_states', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('state', 24);
            $table->string('reason_code', 64)->nullable();
            $table->char('endpoint_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_suppressed_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'last_evaluated_at'], 'monitoring_external_heartbeat_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_external_heartbeat_states');
    }
};
