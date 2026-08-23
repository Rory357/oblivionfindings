<?php

namespace App\Domain\Hr\Enums;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\StaffBackgroundCheck;
use App\Models\User;

/**
 * Canonical authorization decision for the shared compliance CSV transport.
 * Every dataset declares the models it emits and every corresponding action
 * permission. Mixed-domain renewals therefore require the complete envelope.
 */
enum ComplianceExportDataset: string
{
    case Staff = 'staff';
    case Vetting = 'vetting';
    case Drivers = 'drivers';
    case Renewals = 'renewals';

    /**
     * Declare every model that owns a value emitted by this dataset and the
     * action capability that permits that disclosure.
     *
     * @return array<class-string, string>
     */
    public function emittedModelPermissions(): array
    {
        return match ($this) {
            self::Staff => [
                User::class => 'hr.compliance.view',
                HrEmployeeProfile::class => 'hr.compliance.view',
                HrComplianceRequirement::class => 'hr.compliance.view',
                HrStaffComplianceStatus::class => 'hr.compliance.view',
            ],
            self::Vetting => [
                User::class => 'hr.vetting.view',
                StaffBackgroundCheck::class => 'hr.vetting.view',
            ],
            self::Drivers => [
                User::class => 'hr.driver.view',
                HrDriverEligibility::class => 'hr.driver.view',
            ],
            self::Renewals => [
                User::class => 'hr.employees.viewAny',
                HrComplianceRequirement::class => 'hr.compliance.view',
                HrStaffComplianceStatus::class => 'hr.compliance.view',
                StaffBackgroundCheck::class => 'hr.vetting.view',
                HrDriverEligibility::class => 'hr.driver.view',
            ],
        };
    }

    /** @return list<string> */
    public function requiredPermissions(): array
    {
        return array_values(array_unique($this->emittedModelPermissions()));
    }

    public function allows(User $user): bool
    {
        foreach ($this->requiredPermissions() as $permission) {
            if (! $user->canDo($permission)) {
                return false;
            }
        }

        return true;
    }

    public static function routePermissionEnvelope(): string
    {
        $permissions = [];

        foreach (self::cases() as $dataset) {
            $permissions = [...$permissions, ...$dataset->requiredPermissions()];
        }

        return implode('|', array_values(array_unique($permissions)));
    }
}
