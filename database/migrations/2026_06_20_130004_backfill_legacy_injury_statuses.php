<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Injuries & RTW redesign — reconcile any legacy workplace-injury status values
 * (from earlier demo seeds / the retired UI taxonomy open|active|recovering|
 * returned_to_work) to the canonical lifecycle reported → under_treatment →
 * return_to_work → recovered → closed. Without this, demo/legacy rows render a
 * non-canonical status in the detail modal and can't be progressed through the
 * transition graph (which only knows the canonical states).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workplace_injuries')) {
            return;
        }

        $map = [
            'open' => 'reported',
            'active' => 'reported',
            'recovering' => 'return_to_work',
            'returned_to_work' => 'return_to_work',
        ];

        foreach ($map as $legacy => $canonical) {
            DB::table('workplace_injuries')->where('status', $legacy)->update(['status' => $canonical]);
        }
    }

    public function down(): void
    {
        // One-way data reconciliation — no rollback.
    }
};
