<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Installations must not depend on running the demo ControlRoomSeeder
        // to classify a verified fall. Preserve any existing configuration.
        if (! DB::table('control_room_signal_types')->where('code', 'fall_detected')->exists()) {
            DB::table('control_room_signal_types')->insert([
                'code' => 'fall_detected',
                'name' => 'Fall detected',
                'category' => 'people_safety',
                'default_severity' => 'critical',
                'description' => 'A care sensor reported a possible fall requiring an operational response.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain this shared reference: signals, rules and historical evidence
        // may use it, including records created before this migration.
    }
};
