<?php

namespace App\Domain\Hr\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
    public function assertAssignable(int $roleId, string $roleName, User $actor): Role
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Employee role assignment must be validated inside its identity-write transaction.');
        }

        if (! $actor->canDo('hr.employees.manage')) {
            throw new \InvalidArgumentException('You are not allowed to assign the selected employee access role.');
        }

        // EmployeeIntakeService includes this exact Role ID in the complete
        // User/Role prefix. Fetching it by primary key is therefore reentrant;
        // never resolve by name here because a rename plus replacement could
        // introduce a Role that was absent from the prelocked union.
        $role = Role::query()->whereKey($roleId)->lockForUpdate()->first();
        if (! $role || (string) $role->name !== $roleName) {
            throw new \InvalidArgumentException(
                'The employee role changed while the identity write was waiting. Please retry.'
            );
        }

        if (in_array((string) $role->name, self::EXTERNAL_PERSONA_ROLES, true)) {
            throw new \InvalidArgumentException(
                "The '{$roleName}' role is an external portal persona and cannot be assigned through employee intake."
            );
        }

        if (! $this->isAdministrator($actor) && (int) $role->level >= 100) {
            throw new \InvalidArgumentException(
                'Only an administrator can assign an administrator-level role.'
            );
        }

        if (
            (string) $role->name === 'clinical_lead'
            && ! $actor->canDo(self::CLINICAL_LEAD_GRANT_PERMISSION)
        ) {
            throw new \InvalidArgumentException('You are not allowed to assign the selected employee access role.');
        }

        return $role;
    }

    private function isAdministrator(User $actor): bool
    {
        return $actor->role === 'admin' || $actor->hasRole('admin');
    }
}
