<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referral_start_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('service_start_id')->constrained('care_referral_service_starts')->restrictOnDelete();
            $table->uuid('submission_key');
            $table->string('operation', 24);
            $table->char('intent_hash', 64);
            $table->longText('decision');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->unique(['referral_id', 'submission_key'], 'referral_start_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_referral_start_decisions');
    }
};
