<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('expo');
            $table->string('token', 512);
            $table->string('device_id')->nullable();
            $table->string('platform', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'token']);
            $table->index(['user_id', 'provider', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_push_subscriptions');
    }
};
