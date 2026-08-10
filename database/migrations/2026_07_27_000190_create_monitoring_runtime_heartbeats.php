<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_runtime_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('component', 32)->unique();
            $table->string('queue', 64);
            $table->uuid('last_dispatched_token');
            $table->timestamp('last_dispatched_at');
            $table->uuid('last_consumed_token')->nullable();
            $table->timestamp('last_consumed_dispatch_at')->nullable();
            $table->timestamp('last_consumed_at')->nullable();
            $table->timestamps();

            $table->index('last_consumed_at', 'monitoring_runtime_heartbeats_consumed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_runtime_heartbeats');
    }
};
