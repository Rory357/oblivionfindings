<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_signal_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_signal_id')->constrained('fleet_signals')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending|processing|sent|failed
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_signal_outbox');
    }
};
