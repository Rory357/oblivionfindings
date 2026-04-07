<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signal_type');
            $table->string('severity_hint')->default('medium');
            $table->dateTime('occurred_at');
            $table->string('idempotency_key', 64)->unique();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['signal_type', 'occurred_at']);
            $table->index(['shift_id', 'signal_type']);
            $table->index(['site_id', 'signal_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_signals');
    }
};
