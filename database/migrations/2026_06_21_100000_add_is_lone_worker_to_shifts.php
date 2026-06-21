<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit "lone / remote worker" marker on a shift, so the ROSTER — not the
 * Lone Worker Safety page's heuristic (on-call / solo-cover) — becomes the system
 * of record for which shifts need lone-worker monitoring. Nullable-safe default
 * false; the page still falls back to the derivation for unflagged shifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('is_lone_worker')->default(false)->after('is_on_call');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('is_lone_worker');
        });
    }
};
