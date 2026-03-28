<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_vendors', function (Blueprint $table) {
            $table->boolean('hs_induction_completed')->default(false)->after('is_active');
            $table->date('hs_induction_date')->nullable()->after('hs_induction_completed');
            $table->unsignedBigInteger('hs_induction_completed_by')->nullable()->after('hs_induction_date');
            $table->string('hs_induction_document_path')->nullable()->after('hs_induction_completed_by');
            $table->boolean('qualifications_verified')->default(false)->after('hs_induction_document_path');
            $table->text('qualifications_notes')->nullable()->after('qualifications_verified');
            $table->boolean('insurance_verified')->default(false)->after('qualifications_notes');
            $table->date('insurance_expiry')->nullable()->after('insurance_verified');
            $table->string('insurance_provider')->nullable()->after('insurance_expiry');
            $table->string('insurance_policy_number')->nullable()->after('insurance_provider');
            $table->text('site_specific_hs_plan')->nullable()->after('insurance_policy_number');
            $table->string('hs_performance_rating')->nullable()->after('site_specific_hs_plan');
            $table->date('hs_last_reviewed_at')->nullable()->after('hs_performance_rating');

            $table->foreign('hs_induction_completed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_vendors', function (Blueprint $table) {
            $table->dropForeign(['hs_induction_completed_by']);
            $table->dropColumn([
                'hs_induction_completed',
                'hs_induction_date',
                'hs_induction_completed_by',
                'hs_induction_document_path',
                'qualifications_verified',
                'qualifications_notes',
                'insurance_verified',
                'insurance_expiry',
                'insurance_provider',
                'insurance_policy_number',
                'site_specific_hs_plan',
                'hs_performance_rating',
                'hs_last_reviewed_at',
            ]);
        });
    }
};
