<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an approved and accepted referral support proposal version to the draft service agreement
 * created from it, both ways: the referral keeps one row per proposal version, and the agreement
 * keeps its source referral and proposal. The agreement side is nullable and survives a referral
 * retention purge; the referral side refuses to lose an agreement it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('care_referral_service_agreements')) {
            Schema::create('care_referral_service_agreements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('referral_id')->constrained('care_referrals')->restrictOnDelete();
                $table->foreignId('proposal_id')->unique()->constrained('care_referral_proposals')->restrictOnDelete();
                $table->unsignedInteger('proposal_version');
                $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
                $table->foreignId('service_agreement_id')->unique()->constrained('service_agreements')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->char('mapping_digest', 64);
                $table->longText('receipt');
                $table->timestamp('created_at');
                $table->index(['referral_id', 'proposal_version'], 'care_referral_agreements_version_index');
            });
        }

        Schema::table('service_agreements', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_agreements', 'source_referral_id')) {
                $table->foreignId('source_referral_id')->nullable()->constrained('care_referrals')->nullOnDelete();
            }
            if (! Schema::hasColumn('service_agreements', 'source_referral_proposal_id')) {
                $table->foreignId('source_referral_proposal_id')->nullable()->unique()->constrained('care_referral_proposals')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_agreements', function (Blueprint $table): void {
            if (Schema::hasColumn('service_agreements', 'source_referral_proposal_id')) {
                $table->dropForeign(['source_referral_proposal_id']);
                $table->dropUnique(['source_referral_proposal_id']);
                $table->dropColumn('source_referral_proposal_id');
            }
            if (Schema::hasColumn('service_agreements', 'source_referral_id')) {
                $table->dropForeign(['source_referral_id']);
                $table->dropColumn('source_referral_id');
            }
        });
        Schema::dropIfExists('care_referral_service_agreements');
    }
};
