<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_vendors', function (Blueprint $table) {
            // Dated vendor obligations beyond insurance expiry, so contract renewals
            // and the next scheduled visit also surface on the Site Calendar.
            $table->date('contract_renewal_date')->nullable()->after('insurance_expiry');
            $table->date('next_visit_date')->nullable()->after('contract_renewal_date');
        });
    }

    public function down(): void
    {
        Schema::table('site_vendors', function (Blueprint $table) {
            $table->dropColumn(['contract_renewal_date', 'next_visit_date']);
        });
    }
};
