<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_feed_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_feed_id')->constrained('fin_bank_feeds');
            $table->datetime('synced_at');
            $table->enum('status', ['success', 'failed', 'partial']);
            $table->unsignedInteger('transactions_fetched')->default(0);
            $table->unsignedInteger('transactions_imported')->default(0);
            $table->unsignedInteger('transactions_skipped')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['bank_feed_id', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_feed_logs');
    }
};
