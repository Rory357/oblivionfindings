<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEmployeeSkill;
use App\Domain\Hr\Models\HrSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SkillsMatrixService
{
    /**
     * Proficiency levels in order.
     */
    public const PROFICIENCY_LEVELS = ['beginner', 'intermediate', 'advanced', 'expert'];

    /**
     * Assess or update an employee's skill level.
     *
     * @param  int     $tenantId
     * @param  int     $employeeProfileId
     * @param  int     $skillId
     * @param  array   $data
     * @return HrEmployeeSkill
     */
    public function assessSkill(int $tenantId, int $employeeProfileId, int $skillId, array $data): HrEmployeeSkill
    {
        return DB::transaction(function () use ($tenantId, $employeeProfileId, $skillId, $data) {
            return HrEmployeeSkill::updateOrCreate(
                [
                    'employee_profile_id' => $employeeProfileId,
                    'skill_id' => $skillId,
                ],
                [
                    'tenant_id' => $tenantId,
                    'proficiency_level' => $data['proficiency_level'],
                    'self_assessed' => $data['self_assessed'] ?? true,
                    'assessed_by' => $data['assessed_by'] ?? null,
                    'assessed_at' => $data['assessed_by'] ? now() : null,
                    'notes' => $data['notes'] ?? null,
                ]
            );
        });
    }

    /**
     * Get the full skills matrix: employees vs skills with proficiency levels.
     *
     * @param  int|null  $tenantId
     * @return array{employees: array, skills: array, matrix: array}
     */
    public function getSkillsMatrix(?int $tenantId): array
    {
        $skills = HrSkill::forTenant($tenantId)
            ->active()
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'category']);

        $employees = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->with('user:id,name')
            ->get(['id', 'user_id', 'position_title', 'department']);

        $employeeSkills = HrEmployeeSkill::forTenant($tenantId)
            ->get()
            ->groupBy('employee_profile_id');

        $matrix = [];
        foreach ($employees as $employee) {
            $row = [
                'employee_id' => $employee->id,
                'name' => $employee->user?->name ?? 'Unknown',
                'position' => $employee->position_title,
                'department' => $employee->department,
                'skills' => [],
            ];

            $empSkills = $employeeSkills->get($employee->id, collect());
            foreach ($skills as $skill) {
                $es = $empSkills->firstWhere('skill_id', $skill->id);
                $row['skills'][$skill->id] = $es ? $es->proficiency_level : null;
            }

            $matrix[] = $row;
        }

        return [
            'employees' => $matrix,
            'skills' => $skills->toArray(),
        ];
    }

    /**
     * Find employees with a specific skill at or above a given proficiency.
     *
     * @param  int|null  $tenantId
     * @param  int       $skillId
     * @param  string|null  $minProficiency
     * @return \Illuminate\Support\Collection
     */
    public function findEmployeesWithSkill(?int $tenantId, int $skillId, ?string $minProficiency = null)
    {
        $query = HrEmployeeSkill::forTenant($tenantId)
            ->where('skill_id', $skillId)
            ->with(['employeeProfile.user:id,name', 'skill:id,name']);

        if ($minProficiency) {
            $levelIndex = array_search($minProficiency, self::PROFICIENCY_LEVELS);
            if ($levelIndex !== false) {
                $validLevels = array_slice(self::PROFICIENCY_LEVELS, $levelIndex);
                $query->whereIn('proficiency_level', $validLevels);
            }
        }

        return $query->get();
    }

    /**
     * Identify skill gaps: skills where less than a threshold of employees have coverage.
     *
     * @param  int|null  $tenantId
     * @param  float     $coverageThreshold  Percentage (0-100)
     * @return array
     */
    public function getSkillGaps(?int $tenantId, float $coverageThreshold = 50): array
    {
        $totalEmployees = HrEmployeeProfile::forTenant($tenantId)->active()->count();

        if ($totalEmployees === 0) {
            return [];
        }

        $skills = HrSkill::forTenant($tenantId)
            ->active()
            ->withCount('employeeSkills')
            ->get();

        $gaps = [];
        foreach ($skills as $skill) {
            $coverage = ($skill->employee_skills_count / $totalEmployees) * 100;
            if ($coverage < $coverageThreshold) {
                $gaps[] = [
                    'skill_id' => $skill->id,
                    'name' => $skill->name,
                    'category' => $skill->category,
                    'coverage_pct' => round($coverage, 1),
                    'employees_with_skill' => $skill->employee_skills_count,
                    'total_employees' => $totalEmployees,
                ];
            }
        }

        return collect($gaps)->sortBy('coverage_pct')->values()->all();
    }
}
