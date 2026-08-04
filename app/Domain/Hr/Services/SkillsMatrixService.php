<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEmployeeSkill;
use App\Domain\Hr\Models\HrSkill;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SkillsMatrixService
{
    public function __construct(private readonly HrPerformanceAccessService $access) {}

    /**
     * Proficiency levels in order.
     */
    public const PROFICIENCY_LEVELS = ['beginner', 'intermediate', 'advanced', 'expert'];

    /**
     * Assess or update an employee's skill level.
     *
     * @param  array{proficiency_level: string, notes?: string|null}  $data
     */
    public function assessSkill(User $actor, int $employeeProfileId, int $skillId, array $data): HrEmployeeSkill
    {
        return DB::transaction(function () use ($actor, $employeeProfileId, $skillId, $data): HrEmployeeSkill {
            $profile = $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $actor)
                ->lockForUpdate()
                ->findOrFail($employeeProfileId);
            $skill = HrSkill::query()
                ->active()
                ->lockForUpdate()
                ->findOrFail($skillId);

            $assessment = HrEmployeeSkill::query()
                ->firstOrNew([
                    'employee_profile_id' => $profile->getKey(),
                    'skill_id' => $skill->getKey(),
                ]);
            $assessment->fill([
                'proficiency_level' => $data['proficiency_level'],
                'self_assessed' => false,
                'assessed_by' => $actor->getKey(),
                'assessed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);
            $assessment->save();

            return $assessment->refresh();
        });
    }

    /**
     * Get the full skills matrix: employees vs skills with proficiency levels.
     *
     * @return array{employees: array, skills: array}
     */
    public function getSkillsMatrix(User $viewer): array
    {
        $skills = HrSkill::query()
            ->active()
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'category']);

        $employees = $this->access
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $viewer)
            ->with('user:id,name')
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'position_title', 'department']);

        $employeeSkills = HrEmployeeSkill::query()
            ->whereIn('employee_profile_id', $employees->pluck('id'))
            ->whereIn('skill_id', $skills->pluck('id'))
            ->get(['id', 'employee_profile_id', 'skill_id', 'proficiency_level'])
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
     * @return Collection<int, HrEmployeeSkill>
     */
    public function findEmployeesWithSkill(User $viewer, int $skillId, ?string $minProficiency = null): Collection
    {
        $skill = HrSkill::query()->active()->findOrFail($skillId);
        $profileIds = $this->access
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $viewer)
            ->select('hr_employee_profiles.id');
        $query = HrEmployeeSkill::query()
            ->where('skill_id', $skill->getKey())
            ->whereIn('employee_profile_id', $profileIds)
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
     * @return array<int, array<string, int|float|string>>
     */
    public function getSkillGaps(User $viewer, float $coverageThreshold = 50): array
    {
        $profileIds = $this->visibleCurrentProfileIds($viewer);
        $totalEmployees = (clone $profileIds)->count();

        if ($totalEmployees === 0) {
            return [];
        }

        $skills = HrSkill::query()
            ->active()
            ->withCount(['employeeSkills' => fn (Builder $query): Builder => $query
                ->whereIn('employee_profile_id', clone $profileIds)])
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

    /** @return Builder<HrSkill> */
    public function withVisibleAssessmentCount(Builder $query, User $viewer): Builder
    {
        $profileIds = $this->visibleCurrentProfileIds($viewer);

        return $query->withCount(['employeeSkills' => fn (Builder $assessment): Builder => $assessment
            ->whereIn('employee_profile_id', $profileIds)]);
    }

    /** @return Builder<HrEmployeeProfile> */
    private function visibleCurrentProfileIds(User $viewer): Builder
    {
        return $this->access
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $viewer)
            ->select('hr_employee_profiles.id');
    }
}
