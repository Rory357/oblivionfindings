<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referral_document_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->char('token_hash', 64)->unique();
            $table->string('passcode_hash');
            $table->string('audience', 20);
            $table->string('state', 20)->default('pending');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->longText('details');
            $table->longText('credentials')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('evidence_id')->nullable()->unique()->constrained('care_referral_evidence')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['referral_id', 'request_key'], 'referral_document_request_retry_unique');
            $table->index(['referral_id', 'audience', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_referral_document_requests');
    }
};
