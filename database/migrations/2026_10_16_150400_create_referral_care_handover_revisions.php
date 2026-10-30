<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_referral_care_handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversion_id')->constrained('care_referral_conversions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->uuid('submission_key');
            $table->char('intent_hash', 64);
            $table->longText('snapshot');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->unique(['conversion_id', 'version'], 'care_handover_revision_unique');
            $table->unique(['conversion_id', 'submission_key'], 'care_handover_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_referral_care_handovers');
    }
};
