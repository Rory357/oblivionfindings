<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('respite_referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_referrals', 'funding_source')) {
                $table->string('funding_source')->nullable()->after('referral_reason');
            }
            if (! Schema::hasColumn('respite_referrals', 'funding_reference')) {
                $table->string('funding_reference')->nullable()->after('funding_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('respite_referrals', function (Blueprint $table) {
            foreach (['funding_source', 'funding_reference'] as $col) {
                if (Schema::hasColumn('respite_referrals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
