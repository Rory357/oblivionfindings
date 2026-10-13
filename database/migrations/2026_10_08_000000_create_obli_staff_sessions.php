<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obli_authorization_codes', function (Blueprint $table): void {
            $table->string('code_hash', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('challenge', 43);
            $table->string('redirect_uri', 120);
            $table->string('issuer', 2048);
            $table->string('account_fingerprint', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
        });
        Schema::create('obli_staff_sessions', function (Blueprint $table): void {
            $table->string('token_hash', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer', 2048);
            $table->string('account_fingerprint', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obli_staff_sessions');
        Schema::dropIfExists('obli_authorization_codes');
    }
};
