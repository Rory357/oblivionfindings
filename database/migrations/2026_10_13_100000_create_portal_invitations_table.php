<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family portal invitations. Staff invite a family member from the client's
 * portal users screen; the emailed link lets them choose a password (or link
 * Google/Microsoft) within 7 days. Only a SHA-256 hash of the token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'accepted_at', 'revoked_at'], 'portal_inv_user_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_invitations');
    }
};
