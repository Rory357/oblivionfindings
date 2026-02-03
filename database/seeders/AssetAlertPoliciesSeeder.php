<?php

namespace Database\Seeders;

use App\Models\AssetAlertPolicy;
use Illuminate\Database\Seeder;

class AssetAlertPoliciesSeeder extends Seeder
{
    public function run(): void
    {
        $policies = [
            [
                'name' => 'Default Geofence Breach',
                'policy_type' => 'geofence',
                'severity' => 'medium',
                'conditions' => ['breach_type' => 'soft'],
                'actions' => ['notify' => ['role' => 'coordinator']],
                'is_active' => true,
            ],
            [
                'name' => 'SOS Triggered',
                'policy_type' => 'sos',
                'severity' => 'critical',
                'conditions' => ['sos_flag' => true],
                'actions' => ['notify' => ['role' => 'on_call'], 'procedure' => 'Incident Escalation'],
                'is_active' => true,
            ],
            [
                'name' => 'Tamper Alert',
                'policy_type' => 'tamper',
                'severity' => 'high',
                'conditions' => ['tamper_flag' => true],
                'actions' => ['notify' => ['role' => 'coordinator']],
                'is_active' => true,
            ],
        ];

        foreach ($policies as $policy) {
            AssetAlertPolicy::firstOrCreate(
                ['name' => $policy['name']],
                $policy
            );
        }
    }
}
