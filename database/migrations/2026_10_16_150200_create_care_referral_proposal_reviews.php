<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referral_proposal_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('care_referral_proposals')->restrictOnDelete();
            $table->string('kind', 40);
            $table->foreignId('funding_case_id')->nullable()->constrained('care_referral_funding_cases')->restrictOnDelete();
            $table->string('result', 32);
            $table->longText('details');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->index(['proposal_id', 'kind', 'id'], 'care_proposal_review_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_referral_proposal_reviews');
    }
};
