<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_training_review_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('hs_training_review_cases')->restrictOnDelete();
            $table->foreignId('compliance_obligation_id')->unique()->constrained('compliance_obligations')->restrictOnDelete();
            $table->foreignId('hs_representative_id')->constrained('hs_representatives')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('root_obligation_id')->constrained('compliance_obligations')->restrictOnDelete();
            $table->date('anniversary_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('reviewed_on')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('attestation_evidence_id')->nullable()->constrained('compliance_evidence')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['root_obligation_id', 'period_end'], 'hs_training_review_cycle_unique');
            $table->index('hs_representative_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_training_review_sources');
    }
};
