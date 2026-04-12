<?php

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SignalType::firstOrCreate(
            ['code' => 'medication_transit_exception'],
            [
                'name' => 'Medication Transit Exception',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'high',
                'description' => 'A controlled medication has left the house for transit and requires active chain-of-custody tracking',
                'is_active' => true,
            ]
        );

        $signalType = SignalType::where('code', 'medication_transit_exception')->first();

        SignalRule::firstOrCreate(
            [
                'signal_type_code' => 'medication_transit_exception',
                'name' => 'Medication: Transit Exception',
            ],
            [
                'signal_type_id' => $signalType?->id,
                'priority' => 15,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 240,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core', 'coordinators'],
                'is_active' => true,
                'conditions' => [],
            ]
        );
    }

    public function down(): void
    {
        SignalRule::where('signal_type_code', 'medication_transit_exception')->delete();
        SignalType::where('code', 'medication_transit_exception')->delete();
    }
};
