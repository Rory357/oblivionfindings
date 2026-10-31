<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referral_internal_arrangements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->foreignId('proposal_id')->unique()->constrained('care_referral_proposals')->restrictOnDelete();
            $table->longText('terms');
            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authored_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_referral_internal_arrangements');
    }
};
