<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Finance deletes (soft-deletes) a draft made from a referral proposal version, staff may
 * create a fresh draft from that same version (Stephan, 21 Oct 2026). The deleted agreement and
 * its link stay as history, so a proposal version may now have more than one link and more than
 * one source agreement. One LIVE draft per version is enforced by the referral row lock in
 * ReferralServiceAgreements::create. Each agreement still has exactly one link.
 *
 * The plain index is added before the unique one is dropped, because the foreign key needs one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_referral_service_agreements', function (Blueprint $table): void {
            if (! Schema::hasIndex('care_referral_service_agreements', 'care_referral_agreements_proposal_index')) {
                $table->index('proposal_id', 'care_referral_agreements_proposal_index');
            }
        });
        Schema::table('care_referral_service_agreements', function (Blueprint $table): void {
            if (Schema::hasIndex('care_referral_service_agreements', 'care_referral_service_agreements_proposal_id_unique')) {
                $table->dropUnique('care_referral_service_agreements_proposal_id_unique');
            }
        });
        Schema::table('service_agreements', function (Blueprint $table): void {
            if (! Schema::hasIndex('service_agreements', 'service_agreements_source_referral_proposal_index')) {
                $table->index('source_referral_proposal_id', 'service_agreements_source_referral_proposal_index');
            }
        });
        Schema::table('service_agreements', function (Blueprint $table): void {
            if (Schema::hasIndex('service_agreements', 'service_agreements_source_referral_proposal_id_unique')) {
                $table->dropUnique('service_agreements_source_referral_proposal_id_unique');
            }
        });
    }

    public function down(): void
    {
        // Restoring the unique keys fails once a version has a fresh draft; that history is kept.
        Schema::table('care_referral_service_agreements', function (Blueprint $table): void {
            if (! Schema::hasIndex('care_referral_service_agreements', 'care_referral_service_agreements_proposal_id_unique')) {
                $table->unique('proposal_id', 'care_referral_service_agreements_proposal_id_unique');
            }
        });
        Schema::table('service_agreements', function (Blueprint $table): void {
            if (! Schema::hasIndex('service_agreements', 'service_agreements_source_referral_proposal_id_unique')) {
                $table->unique('source_referral_proposal_id', 'service_agreements_source_referral_proposal_id_unique');
            }
        });
    }
};
