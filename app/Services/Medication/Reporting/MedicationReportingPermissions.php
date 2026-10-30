<?php

namespace App\Services\Medication\Reporting;

use App\Models\Permission;
use App\Models\Role;

final class MedicationReportingPermissions
{
    public static function install(): void
    {
        foreach ([
            'medications.reports.view' => ['View medication reports at approved Sites', ['admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead', 'auditor', 'finance']],
            'medications.audit.export' => ['Export the medication audit trail at approved Sites', ['admin', 'provider_manager', 'coordinator', 'auditor']],
        ] as $key => [$description, $roles]) {
            $permission = Permission::query()->updateOrCreate(['key' => $key], ['description' => $description, 'group' => 'medications', 'module' => 'Clinical']);
            Role::query()->whereIn('name', $roles)->each(fn ($role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
        }
    }
}
