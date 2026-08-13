<?php

namespace App\Domain\Hr\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The canonical grant boundary for employee-intake roles.
 *
 * Employee management permits an actor to create an employment record. It does
 * not permit them to mint administrator-grade or Clinical Lead authority
 * without the matching grant capability, and portal personas are never
 * employee roles. Both option payloads and writes use this service so a hidden
 * or stale role cannot be injected after the page renders.
 */
class EmployeeRoleAssignmentService
{
    /** @var list<string> */
    private const EXTERNAL_PERSONA_ROLES = ['client', 'next_of_kin'];

    public const CLINICAL_LEAD_GRANT_PERMISSION = 'hr.employees.assignClinicalLead';

    /** @return Builder<Role> */
    public function assignableRoles(User $actor): Builder
    {
        $query = Role::query();
        if (! $actor->canDo('hr.employees.manage')) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNotIn('name', self::EXTERNAL_PERSONA_ROLES)
            ->when(
                ! $this->isAdministrator($actor),
                fn (Builder $query) => $query->where('level', '<', 100),
            )
            ->when(
                ! $actor->canDo(self::CLINICAL_LEAD_GRANT_PERMISSION),
                fn (Builder $query) => $query->where('name', '!=', 'clinical_lead'),
            );
    }

    /** @return list<string> */
    public function assignableRoleNames(User $actor): array
    {
        return $this->assignableRoles($actor)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Revalidate the selected role in the identity-write transaction.
     *
     * @throws \InvalidArgumentException
     */
    public function assertAssignable(string $roleName, User $actor): Role
    {
        if (! $actor->canDo('hr.employees.manage')) {
            throw new \InvalidArgumentException('You are not allowed to assign the selected employee access role.');
        }

        if (in_array($roleName, self::EXTERNAL_PERSONA_ROLES, true)) {
            throw new \InvalidArgumentException(
                "The '{$roleName}' role is an external portal persona and cannot be assigned through employee intake."
            );
        }

        $role = $this->assignableRoles($actor)
            ->where('name', $roleName)
            ->first();
        if ($role) {
            return $role;
        }

        $requestedLevel = Role::query()->where('name', $roleName)->value('level');
        if ($roleName === 'admin' || (int) $requestedLevel >= 100) {
            throw new \InvalidArgumentException(
                'Only an administrator can assign an administrator-level role.'
            );
        }

        throw new \InvalidArgumentException('You are not allowed to assign the selected employee access role.');
    }

    private function isAdministrator(User $actor): bool
    {
        return $actor->role === 'admin' || $actor->hasRole('admin');
    }
}
