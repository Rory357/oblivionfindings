<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;

/**
 * Shared payload for the Compliance hub wizards (people picker, requirement
 * tiles, assignable roles + site types). Every hub surface that opens a wizard
 * renders the same options, so the build lives in one place.
 */
trait ProvidesComplianceWizardData
{
    protected function complianceWizardData(?int $tenantId): array
    {
        $people = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name,email')
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'position_title'])
            ->filter(fn ($p) => $p->user !== null)
            ->map(fn ($p) => [
                'value' => (string) $p->user_id,
                'label' => $p->user->name,
                'sub' => $p->position_title ?: $p->user->email,
            ])
            ->values();

        $requirements = HrComplianceRequirement::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category', 'check_type', 'validity_months', 'hard_stop'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'category' => $r->category,
                'check_type' => $r->check_type,
                'validity_months' => $r->validity_months,
                'hard_stop' => (bool) $r->hard_stop,
            ])
            ->values();

        // Roles assignable in the matrix — machine `name` (must match a user's role
        // for evaluation) but the picker shows the human label via the map below.
        $roles = Role::query()
            ->orderByDesc('level')
            ->get(['name', 'label'])
            ->map(fn ($r) => ['value' => $r->name, 'label' => $r->label ?: $r->name])
            ->values();

        $siteTypes = HrComplianceMatrix::where('tenant_id', $tenantId)
            ->whereNotNull('site_type')
            ->distinct()
            ->pluck('site_type')
            ->values();

        return [
            'people' => $people,
            'requirements' => $requirements,
            'roles' => $roles,
            'siteTypes' => $siteTypes,
        ];
    }
}
