<?php

use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Safety fix round (7 Nov 2026). Device safety events (panic, duress, fire and intruder alarms,
 * forced doors, water leaks, falls) now go to Control Room's own signal types instead of the
 * generic catch-all (DeviceEventObserver::SAFETY_TYPE_MAP). Installations must not depend on
 * running the demo ControlRoomSeeder for those types to exist, so any missing one is added with
 * the seeder's own name, category and severity. Existing rows are left exactly as they are.
 * care_alert_unanswered is new: an unanswered care alert only S&D knows about reaches Control Room.
 */
return new class extends Migration
{
    private const TYPES = [
        ['code' => 'panic_sos_person', 'name' => 'Panic/SOS (Person)', 'category' => 'people_safety', 'default_severity' => 'critical', 'description' => 'A person pressed a panic or SOS button, or an alarm panel reported a duress code.'],
        ['code' => 'fall_detected', 'name' => 'Fall Detected', 'category' => 'people_safety', 'default_severity' => 'critical', 'description' => 'A care sensor reported a possible fall requiring an operational response.'],
        ['code' => 'fire_alarm', 'name' => 'Fire Alarm', 'category' => 'home_facility', 'default_severity' => 'critical', 'description' => 'A fire or smoke alarm went off at a house.'],
        ['code' => 'intrusion_alarm', 'name' => 'Intrusion Alarm', 'category' => 'home_facility', 'default_severity' => 'high', 'description' => 'An intruder alarm went off at a house.'],
        ['code' => 'door_forced_open', 'name' => 'Door Forced/Open', 'category' => 'home_facility', 'default_severity' => 'high', 'description' => 'A door was forced open.'],
        ['code' => 'water_leak', 'name' => 'Water Leak', 'category' => 'home_facility', 'default_severity' => 'high', 'description' => 'A water leak sensor reported a leak.'],
        // An S&D care alert (out of bed, a door at night, hot water) nobody said they were on in time.
        ['code' => 'care_alert_unanswered', 'name' => 'Care alert not answered', 'category' => 'people_safety', 'default_severity' => 'critical', 'description' => 'A care alert at a house that nobody on shift said they were attending to within the time set in Security & devices.'],
    ];

    public function up(): void
    {
        // Installations built from the schema dump (.com among them, checked 7 Nov 2026) recorded the
        // PR H migrations 2026_04_18_000001/2 as run but kept none of their rows: no security_devices
        // signal source, no device_* types, no Security & Devices rules. Every device event was then
        // unroutable. Add whatever is missing, exactly as PR H meant; existing rows are never changed.
        app(SecurityDevicesSignalSeeder::class)->run();

        foreach (self::TYPES as $type) {
            if (DB::table('control_room_signal_types')->where('code', $type['code'])->exists()) {
                continue;
            }
            DB::table('control_room_signal_types')->insert([...$type, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Shared reference rows: signals, rules, playbooks and history may use them. Keep them.
    }
};
