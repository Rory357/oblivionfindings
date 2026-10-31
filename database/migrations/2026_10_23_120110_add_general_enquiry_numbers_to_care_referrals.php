<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * General enquiries (Contact us, and general questions from the enquiry form) get their own
 * ENQ- display number (Stephan, 21 Oct 2026). Referrals keep their own reference format.
 *
 * The number comes from a one-row counter that only ever goes up, so a number is never reused,
 * even if a record were later removed. Existing records are not renumbered: anyone who already
 * holds a receipt keeps the reference they were given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_referrals', function (Blueprint $table): void {
            $table->unsignedInteger('enquiry_number')->nullable()->unique()->after('reference');
        });
        Schema::create('care_referral_enquiry_sequence', function (Blueprint $table): void {
            // One organisation-wide counter; this is not an organisation selector.
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('last_number');
            $table->timestamps();
        });
        // The first enquiry is ENQ-1001.
        DB::table('care_referral_enquiry_sequence')->insert(['id' => 1, 'last_number' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('care_referral_enquiry_sequence');
        Schema::table('care_referrals', function (Blueprint $table): void {
            $table->dropUnique(['enquiry_number']);
            $table->dropColumn('enquiry_number');
        });
    }
};
