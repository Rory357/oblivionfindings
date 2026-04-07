<?php

namespace App\Services;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Models\Shift;
use App\Models\User;

class CoverageRoleService
{
    /**
     * @return array<string, string>
     */
    public function supportedRoles(): array
    {
        return [
            'caregiver' => 'Caregiver',
            'driver' => 'Driver',
            'med_competent' => 'Medication competent',
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, minimum: int}>
     */
    public function normalizeRequirements(?array $requirements): array
    {
        return collect($requirements ?? [])
            ->map(function ($value, $key) {
                if (is_array($value)) {
                    $roleKey = (string) ($value['key'] ?? $key);
                    $minimum = (int) ($value['minimum'] ?? 0);
                } else {
                    $roleKey = (string) $key;
                    $minimum = (int) $value;
                }

                if ($roleKey === '' || $minimum <= 0) {
                    return null;
                }

                return [
                    'key' => $roleKey,
                    'label' => $this->supportedRoles()[$roleKey] ?? ucfirst(str_replace('_', ' ', $roleKey)),
                    'minimum' => $minimum,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function userHasRole(User $user, string $roleKey): bool
    {
        return match ($roleKey) {
            'caregiver' => true,
            'driver' => $this->userCanDrive($user),
            'med_competent' => $this->userIsMedicationCompetent($user),
            default => false,
        };
    }

    /**
     * @return string[]
     */
    public function rolesForUser(User $user): array
    {
        return collect(array_keys($this->supportedRoles()))
            ->filter(fn (string $roleKey) => $this->userHasRole($user, $roleKey))
            ->values()
            ->all();
    }

    /**
     * @return string[]
     */
    public function rolesForShift(Shift $shift): array
    {
        $configured = collect($shift->coverage_roles ?? [])
            ->map(fn ($role) => (string) $role)
            ->filter()
            ->values();

        if ($configured->isNotEmpty()) {
            return $configured->all();
        }

        $roles = collect(['caregiver']);
        if ($shift->shift_type === 'travel') {
            $roles->push('driver');
        }
        if ($shift->serviceContext?->type === 'medication') {
            $roles->push('med_competent');
        }

        return $roles->unique()->values()->all();
    }

    /**
     * @return array<int, array{key: string, label: string, minimum: int}>
     */
    public function requirementsForShift(Shift $shift): array
    {
        $configuredRoles = collect($shift->coverage_roles ?? [])
            ->map(fn ($role) => (string) $role)
            ->filter()
            ->values()
            ->all();

        if ($configuredRoles !== []) {
            return collect($configuredRoles)
                ->map(fn (string $roleKey) => [
                    'key' => $roleKey,
                    'label' => $this->supportedRoles()[$roleKey] ?? ucfirst(str_replace('_', ' ', $roleKey)),
                    'minimum' => 1,
                ])
                ->values()
                ->all();
        }

        return [];
    }

    protected function userCanDrive(User $user): bool
    {
        $eligibility = $user->relationLoaded('hrDriverEligibility')
            ? $user->hrDriverEligibility
            : $user->hrDriverEligibility()->first();

        if (! $eligibility instanceof HrDriverEligibility) {
            return false;
        }

        return $eligibility->status === 'eligible' && (bool) $eligibility->can_drive_clients;
    }

    protected function userIsMedicationCompetent(User $user): bool
    {
        if ($user->canDo('medications.administer.record')) {
            return true;
        }

        return $user->relationLoaded('medicationCompetencyAssessments')
            ? $user->medicationCompetencyAssessments->contains(fn ($assessment) => method_exists($assessment, 'isPassed') ? $assessment->isPassed() : $assessment->status === 'passed')
            : $user->medicationCompetencyAssessments()->active()->exists();
    }
}
