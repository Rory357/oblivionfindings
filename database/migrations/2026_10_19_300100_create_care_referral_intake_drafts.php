<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unsubmitted phone/in-person intake, private to the staff member who started it.
        Schema::create('care_referral_intake_drafts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->longText('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'expires_at'], 'care_referral_intake_drafts_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_referral_intake_drafts');
    }
};
