<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exact "this handover involves controlled drugs" flag, stamped at save time from
 * whether the client has any active controlled medication. Replaces the prior
 * heuristic (scanning the medications_due text for a "(CD)" tag) so the eMAR
 * "CD count unverified" alert is precise — without an index-time N+1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->boolean('cd_required')->default(false)->after('cd_verification');
        });
    }

    public function down(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->dropColumn('cd_required');
        });
    }
};
