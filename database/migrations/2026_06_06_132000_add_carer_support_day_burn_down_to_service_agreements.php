<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_agreements', function (Blueprint $table) {
            if (! Schema::hasColumn('service_agreements', 'carer_support_days_allocated')) {
                $table->unsignedSmallInteger('carer_support_days_allocated')->nullable()->after('allocated_hours_per_week');
            }
            if (! Schema::hasColumn('service_agreements', 'carer_support_days_used')) {
                $table->unsignedSmallInteger('carer_support_days_used')->default(0)->after('carer_support_days_allocated');
            }
            if (! Schema::hasColumn('service_agreements', 'carer_support_entitlement_year')) {
                $table->string('carer_support_entitlement_year', 9)->nullable()->after('carer_support_days_used');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_agreements', function (Blueprint $table) {
            $columns = [
                'carer_support_entitlement_year',
                'carer_support_days_used',
                'carer_support_days_allocated',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('service_agreements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
