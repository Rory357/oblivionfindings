<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_browser_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('chat_message_deliveries')->cascadeOnDelete();
            // Keep the receipt if a subscription is removed; never follow a new owner or replacement keys.
            $table->unsignedBigInteger('subscription_id');
            $table->char('subscription_fingerprint', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('expires_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('last_error', 60)->nullable();
            $table->timestamps();
            $table->unique(['delivery_id', 'subscription_id'], 'chat_push_recipient_device_unique');
            $table->index(['status', 'available_at']);
            $table->index(['status', 'lease_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_browser_push_deliveries');
    }
};
