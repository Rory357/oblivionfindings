<?php

use App\Domain\Referrals\Services\ReferralSearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referral_search_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained('care_referrals')->cascadeOnDelete();
            // HMAC-SHA256 of a normalised name prefix; never the name itself.
            $table->char('token_hash', 64);
            $table->unique(['referral_id', 'token_hash'], 'care_referral_search_token_unique');
            $table->index(['token_hash', 'referral_id'], 'care_referral_search_token_lookup');
        });

        // Backfill existing referrals. `php artisan referrals:rebuild-search-index` repeats this after a key rotation.
        app(ReferralSearchIndex::class)->rebuild();
    }

    public function down(): void
    {
        Schema::dropIfExists('care_referral_search_tokens');
    }
};
