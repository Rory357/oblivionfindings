<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B: provision the Fleet safety signal source.
 *
 * Control Room accepts a Fleet safety signal, including a recorded event sent
 * from the vehicle profile's Alerts & Control Room view, only through an
 * active `queclink_fleet` source; without one the delivery is kept as
 * unroutable (SignalProcessingService::ingestFromFleetSignal). Only
 * ControlRoomSeeder created that source and deploys never seed, so on a fresh
 * database no vehicle alert reached Control Room.
 *
 * This adds the seeder's row only when no row has the slug. An existing row
 * is never changed, so a source an admin set inactive or to maintenance stays
 * that way. Nothing else is needed to route: signal types, rules, queues,
 * SLAs and playbooks are optional Control Room configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_room_signal_sources')
            || DB::table('control_room_signal_sources')->where('slug', 'queclink_fleet')->exists()) {
            return;
        }

        $now = now();
        DB::table('control_room_signal_sources')->insert([
            'name' => 'Queclink Fleet',
            'slug' => 'queclink_fleet',
            'vendor' => 'queclink',
            'status' => 'active',
            'config' => null,
            'capabilities' => null,
            'signal_count_24h' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Keeps the source on purpose. The row is identical to the one
        // ControlRoomSeeder creates, so this migration cannot prove it made it,
        // and signals, alerts, rules and devices refer to it. Removing it would
        // make every Fleet safety signal, SOS included, unroutable again.
    }
};
