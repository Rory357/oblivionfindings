<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase B, D3 "Check now": an admin asks one hub to look for its update now instead of
 * waiting for its night window. Set by the admin, cleared when the hub reports back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->timestamp('update_check_requested_at')->nullable()->after('update_state');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_collectors', function (Blueprint $table): void {
            $table->dropColumn('update_check_requested_at');
        });
    }
};
