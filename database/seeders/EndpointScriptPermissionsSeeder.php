<?php

namespace Database\Seeders;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptOutputs;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScripts;
use App\Models\Permission;
use Illuminate\Database\Seeder;

/** Registration only. No role grants or execution activation. */
final class EndpointScriptPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            EndpointScripts::UPLOAD => 'Upload immutable Windows scripts for review',
            EndpointScripts::REVIEW => 'Independently review or withdraw immutable Windows scripts',
            EndpointScriptPolicy::MANAGE => 'Configure computer script policy',
            EndpointScriptPolicy::RUN => 'Request independently approved computer scripts',
            EndpointScriptPolicy::UNATTENDED => 'Use unattended computer script policy',
            EndpointScriptOutputs::VIEW => 'Read private retained computer script output',
        ] as $key => $description) {
            Permission::firstOrCreate(['key' => $key], ['description' => $description, 'group' => 'security_devices', 'module' => 'Security & Devices']);
        }
    }
}
