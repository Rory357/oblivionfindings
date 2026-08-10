<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrSkill;
use App\Domain\Hr\Services\SkillsMatrixService;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SkillsController extends Controller
{
    public function __construct(
        private readonly SkillsMatrixService $skillsService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — skills list */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $category = $request->query('category');
        $search = trim((string) $request->query('q', ''));

        $skills = $this->skillsService
            ->withVisibleAssessmentCount(HrSkill::query(), $user)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (HrSkill $skill): array => [
                'id' => $skill->id,
                'name' => $skill->name,
                'category' => $skill->category,
                'description' => $skill->description,
                'is_active' => (bool) $skill->is_active,
                'employee_skills_count' => (int) $skill->employee_skills_count,
            ]);

        $categories = HrSkill::query()
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values();

        $skillGaps = $this->skillsService->getSkillGaps($user);

        return Inertia::render('hr/performance/skills/index', [
            'skills' => $skills,
            'categories' => $categories,
            'skillGaps' => $skillGaps,
            'filters' => [
                'category' => $category,
                'q' => $search,
            ],
            'can' => [
                'create' => $this->canManage($user),
                'manage' => $this->canManage($user),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Matrix — employees vs skills grid */
    /* ------------------------------------------------------------------ */

    public function matrix(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $matrixData = $this->skillsService->getSkillsMatrix($user);

        return Inertia::render('hr/performance/skills/matrix', [
            'employees' => $matrixData['employees'],
            'skills' => $matrixData['skills'],
            'proficiencyLevels' => SkillsMatrixService::PROFICIENCY_LEVELS,
            'can' => [
                'assess' => $this->canManage($user),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Skill — create a new skill */
    /* ------------------------------------------------------------------ */

    public function storeSkill(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'category' => trim((string) $request->input('category')),
        ]);
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('hr_skills', 'name')->where(
                    fn ($query) => $query->where('category', $request->input('category')),
                ),
            ],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            HrSkill::query()->create([
                'name' => $validated['name'],
                'category' => $validated['category'],
                'description' => $validated['description'] ?? null,
                'is_active' => true,
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000'
                && ! str_contains($exception->getMessage(), 'hr_skills_category_name_uq')
            ) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'name' => 'A skill with this name already exists in the selected category.',
            ]);
        }

        return redirect()->back()->with('success', 'Skill created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Assess Employee — record a skill assessment */
    /* ------------------------------------------------------------------ */

    public function assessEmployee(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $validated = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'skill_id' => ['required', 'integer', 'exists:hr_skills,id'],
            'proficiency_level' => ['required', 'string', Rule::in(SkillsMatrixService::PROFICIENCY_LEVELS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->skillsService->assessSkill(
            $user,
            $validated['employee_profile_id'],
            $validated['skill_id'],
            [
                'proficiency_level' => $validated['proficiency_level'],
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return redirect()->back()->with('success', 'Skill assessment recorded.');
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.skills.view')
            || $user->canDo('hr.skills.manage')
            || $user->canDo('hr.performance.view')
            || $user->canDo('hr.performance.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.skills.manage')
            || $user->canDo('hr.performance.manage')
        );
    }
}
