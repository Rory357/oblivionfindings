<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Te Tiriti commitments move to the five Hauora (Wai 2575) principles:
 * tino rangatiratanga, equity, active protection, options and partnership.
 *
 *   participation → tino_rangatiratanga (Māori decide about their own health and services)
 *   protection    → active_protection
 *   partnership, equity and options keep their keys.
 */
return new class extends Migration
{
    private const MAP = [
        'participation' => 'tino_rangatiratanga',
        'protection' => 'active_protection',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('te_tiriti_obligations')) {
            return;
        }

        foreach (self::MAP as $from => $to) {
            DB::table('te_tiriti_obligations')->where('principle', $from)->update(['principle' => $to]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('te_tiriti_obligations')) {
            return;
        }

        foreach (self::MAP as $from => $to) {
            DB::table('te_tiriti_obligations')->where('principle', $to)->update(['principle' => $from]);
        }
    }
};
