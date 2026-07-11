<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture-at-source context on AP bills. Operational bills (asset maintenance,
 * repairs, …) carry the site/asset they belong to plus the cost-allocation
 * event_type, so approving the bill can create the FinCostAllocation rows that
 * feed site budgets/forecasts — previously only FinancialEventService-posted
 * journals allocated, so moving a flow from direct GL posting to bills would
 * silently drop it from site cost reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bills', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')->nullable()->after('spend_approval_id')->index();
            $table->unsignedBigInteger('asset_id')->nullable()->after('site_id')->index();
            $table->string('allocation_event_type', 50)->nullable()->after('asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('fin_bills', function (Blueprint $table) {
            $table->dropColumn(['site_id', 'asset_id', 'allocation_event_type']);
        });
    }
};
