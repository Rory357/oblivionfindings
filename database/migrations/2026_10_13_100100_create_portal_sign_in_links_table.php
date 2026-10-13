<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emailed sign-in links for the family portal. A link works once, for 15
 * minutes, and only in the browser that asked for it (the device hash matches
 * a cookie set on request). Only hashes are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_sign_in_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('device_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at', 'revoked_at'], 'portal_sil_user_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_sign_in_links');
    }
};
