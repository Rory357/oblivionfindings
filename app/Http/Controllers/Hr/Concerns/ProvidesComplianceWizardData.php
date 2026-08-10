<?php

namespace App\Http\Controllers\Hr\Concerns;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;

/**
 * Shared payload for the Compliance hub wizards (people picker, requirement
 * tiles, assignable roles + site types). Every hub surface that opens a wizard
 * renders the same options, so the build lives in one place.
 */
trait ProvidesComplianceWizardData
{
    protected function complianceWizardData(User $viewer): array
    {
        $peopleQuery = User::query()
            ->with('hrEmployeeProfile:id,user_id,position_title,work_email')
            ->orderBy('name');
        app(UserSiteAccessService::class)->applyStaffScope($peopleQuery, $viewer);
        $people = $peopleQuery
            ->get(['id', 'name'])
            ->map(fn (User $person) => [
                'value' => (string) $person->id,
                'label' => $person->name,
                'sub' => $person->hrEmployeeProfile?->position_title
                    ?: $person->hrEmployeeProfile?->work_email,
            ])
            ->values();

        $requirements = HrComplianceRequirement::query()
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

        // Site-type rules must come from the canonical Sites register, not from
        // whatever rows already happen to exist in the matrix. Otherwise an
        // all-Sites-only matrix can never create its first specific rule.
        $siteTypes = Site::query()
            ->active()
            ->notArchived()
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->filter(fn ($type) => filled($type) && mb_strtolower(trim((string) $type)) !== 'all')
            ->unique()
            ->values();

        return [
            'people' => $people,
            'requirements' => $requirements,
            'roles' => $roles,
            'siteTypes' => $siteTypes,
        ];
    }
}
