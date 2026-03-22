<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrSkill;
use App\Domain\Hr\Services\SkillsMatrixService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SkillsController extends Controller
{
    public function __construct(
        private readonly SkillsMatrixService $skillsService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — skills list                                                */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.skills.viewAny'), 403);

        $tenantId = null;
        $category = $request->query('category');
        $search = trim((string) $request->query('q', ''));

        $skills = HrSkill::forTenant($tenantId)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->withCount('employeeSkills')
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        $categories = HrSkill::forTenant($tenantId)
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values();

        $skillGaps = $this->skillsService->getSkillGaps($tenantId);

        return Inertia::render('hr/skills/index', [
            'skills' => $skills,
            'categories' => $categories,
            'skillGaps' => $skillGaps,
            'filters' => [
                'category' => $category,
                'q' => $search,
            ],
            'can' => [
                'create' => $user->canDo('hr.skills.create'),
                'manage' => $user->canDo('hr.skills.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Matrix — employees vs skills grid                                  */
    /* ------------------------------------------------------------------ */

    public function matrix(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.skills.viewAny'), 403);

        $tenantId = null;

        $matrixData = $this->skillsService->getSkillsMatrix($tenantId);

        return Inertia::render('hr/skills/matrix', [
            'employees' => $matrixData['employees'],
            'skills' => $matrixData['skills'],
            'proficiencyLevels' => SkillsMatrixService::PROFICIENCY_LEVELS,
            'can' => [
                'assess' => $user->canDo('hr.skills.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Skill — create a new skill                                   */
    /* ------------------------------------------------------------------ */

    public function storeSkill(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.skills.create'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        HrSkill::create([
            'tenant_id' => $user->tenant_id,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Skill created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Assess Employee — record a skill assessment                        */
    /* ------------------------------------------------------------------ */

    public function assessEmployee(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.skills.manage'), 403);

        $validated = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'skill_id' => ['required', 'integer', 'exists:hr_skills,id'],
            'proficiency_level' => ['required', 'string', Rule::in(SkillsMatrixService::PROFICIENCY_LEVELS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->skillsService->assessSkill(
            $user->tenant_id,
            $validated['employee_profile_id'],
            $validated['skill_id'],
            [
                'proficiency_level' => $validated['proficiency_level'],
                'self_assessed' => false,
                'assessed_by' => $user->id,
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return redirect()->back()->with('success', 'Skill assessment recorded.');
    }
}
