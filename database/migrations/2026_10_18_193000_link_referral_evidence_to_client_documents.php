<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->foreignId('source_referral_evidence_id')->nullable()->constrained('care_referral_evidence')->restrictOnDelete();
            $table->text('referral_release')->nullable();
            $table->unique(['client_id', 'source_referral_evidence_id'], 'client_referral_evidence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->dropUnique('client_referral_evidence_unique');
            $table->dropConstrainedForeignId('source_referral_evidence_id');
            $table->dropColumn('referral_release');
        });
    }
};
