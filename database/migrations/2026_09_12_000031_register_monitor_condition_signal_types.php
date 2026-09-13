<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Register source vocabulary only. Existing operational rules decide routing.
        foreach (['device_monitor_failed' => ['Technical check failed', 'high'],
            'device_monitor_recovered' => ['Technical check recovered', 'info']] as $code => [$name, $severity]) {
            if (! DB::table('control_room_signal_types')->where('code', $code)->exists()) {
                DB::table('control_room_signal_types')->insert([
                    'code' => $code, 'name' => $name, 'category' => 'home_facility',
                    'default_severity' => $severity, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Retain registered codes: historical signals and configured rules may refer to them.
    }
};
