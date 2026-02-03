<?php

namespace Database\Seeders;

use App\Models\ProcedureTemplate;
use Illuminate\Database\Seeder;

class AssetProcedureTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Asset Registration',
                'domain' => 'asset',
                'version' => 1,
                'trigger_event' => 'assets.create',
                'description' => 'Register a new asset with ownership, QR, and baseline condition.',
                'steps_json' => [
                    ['title' => 'Confirm ownership', 'required' => true],
                    ['title' => 'Capture photos + serial', 'required' => true],
                    ['title' => 'Generate QR tag', 'required' => true],
                ],
                'required_roles' => ['coordinator'],
                'active' => true,
            ],
            [
                'name' => 'Tracker Pairing',
                'domain' => 'asset',
                'version' => 1,
                'trigger_event' => 'assets.tracker.paired',
                'description' => 'Pair a live tracking device and confirm consent.',
                'steps_json' => [
                    ['title' => 'Verify consent', 'required' => true],
                    ['title' => 'Scan device', 'required' => true],
                    ['title' => 'Test ping', 'required' => true],
                ],
                'required_roles' => ['coordinator'],
                'active' => true,
            ],
            [
                'name' => 'Daily Presence / Use Check',
                'domain' => 'asset',
                'version' => 1,
                'trigger_event' => 'assets.scan.logged',
                'description' => 'Confirm asset presence and condition after QR scan.',
                'steps_json' => [
                    ['title' => 'Confirm location', 'required' => true],
                    ['title' => 'Check condition', 'required' => true],
                    ['title' => 'Record notes', 'required' => false],
                ],
                'required_roles' => ['support_worker'],
                'active' => true,
            ],
            [
                'name' => 'Asset Movement Outside Policy',
                'domain' => 'asset',
                'version' => 1,
                'trigger_event' => 'assets.geofence.breached',
                'description' => 'Respond to an asset moving outside policy.',
                'steps_json' => [
                    ['title' => 'Verify location', 'required' => true],
                    ['title' => 'Contact staff/whanau', 'required' => true],
                    ['title' => 'Escalate if needed', 'required' => false],
                ],
                'required_roles' => ['coordinator'],
                'active' => true,
            ],
            [
                'name' => 'Asset Lost / Theft',
                'domain' => 'asset',
                'version' => 1,
                'trigger_event' => 'assets.lost.reported',
                'description' => 'Report and respond to lost or stolen asset.',
                'steps_json' => [
                    ['title' => 'Confirm loss', 'required' => true],
                    ['title' => 'Notify manager', 'required' => true],
                    ['title' => 'Create incident record', 'required' => true],
                ],
                'required_roles' => ['coordinator'],
                'active' => true,
            ],
        ];

        foreach ($templates as $template) {
            ProcedureTemplate::firstOrCreate(
                ['name' => $template['name'], 'domain' => $template['domain']],
                $template
            );
        }
    }
}
