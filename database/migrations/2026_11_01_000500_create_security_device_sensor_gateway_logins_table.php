<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase B, sensor relay (D21): each sensor gateway or plug gets its own login. Only a bcrypt
 * hash is kept; the password is shown once when the login is made. A login may send only to its
 * own topic, and the reading's Site always comes from the login, never from the message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_sensor_gateway_logins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('label', 120);
            $table->string('username', 64)->unique();
            $table->string('password_hash', 255);
            $table->string('topic_prefix', 160)->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_reading_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_sensor_gateway_logins');
    }
};
