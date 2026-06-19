<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CompetencyController extends Controller
{
    use ResolvesHrTenant;

    /**
     * List competencies grouped by category.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $competencies = HrCompetency::query()
            ->active()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        $grouped = $competencies->groupBy('category')->map(fn ($items) => $items->values());

        $staff = User::orderBy('name')->get(['id', 'name', 'email']);

        return Inertia::render('hr/performance/competencies/index', [
            'competencies' => $competencies,
            'grouped' => $grouped,
            'staff' => $staff,
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /**
     * Create a new competency.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:255'],
            'proficiency_levels' => ['nullable', 'array'],
            'proficiency_levels.*' => ['string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        // NB: hr_competencies has no created_by column — don't write it.
        HrCompetency::create([
            'tenant_id' => $this->resolveHrTenantIdForUser($user),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'proficiency_levels' => $data['proficiency_levels'] ?? ['Beginner', 'Developing', 'Competent', 'Advanced', 'Expert'],
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return redirect()->back()->with('success', 'Competency created.');
    }

    /**
     * Update a competency.
     */
    public function update(Request $request, HrCompetency $competency)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', 'max:255'],
            'proficiency_levels' => ['nullable', 'array'],
            'proficiency_levels.*' => ['string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $competency->update($data);

        return redirect()->back()->with('success', 'Competency updated.');
    }

    public function createAssessment(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $competencies = HrCompetency::query()
            ->forTenant($tenantId)
            ->active()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        $staffUserIds = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->pluck('user_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values();

        $staff = User::query()
            ->whereIn('id', $staffUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/performance/competencies/assess', [
            'competencies' => $competencies,
            'staff' => $staff,
        ]);
    }

    /**
     * Assess an employee against competencies.
     */
    public function assess(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'assessments' => ['required', 'array', 'min:1'],
            'assessments.*.competency_id' => ['required', 'integer', 'exists:hr_competencies,id'],
            'assessments.*.proficiency_level' => ['required', 'integer', 'min:1', 'max:5'],
            'assessments.*.target_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'assessments.*.notes' => ['nullable', 'string', 'max:2000'],
            'performance_review_id' => ['nullable', 'integer', 'exists:hr_performance_reviews,id'],
        ]);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $data['employee_user_id'])
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'employee_user_id' => 'Select an employee with an HR profile before recording competencies.',
            ]);
        }

        DB::transaction(function () use ($user, $data, $profile, $tenantId) {
            foreach ($data['assessments'] as $assessment) {
                HrCompetencyAssessment::create([
                    'tenant_id' => $tenantId,
                    'employee_profile_id' => $profile->id,
                    'competency_id' => $assessment['competency_id'],
                    'assessed_by' => $user->id,
                    'performance_review_id' => $data['performance_review_id'] ?? null,
                    'assessed_level' => $assessment['proficiency_level'],
                    'target_level' => $assessment['target_level'] ?? null,
                    'assessment_date' => now()->toDateString(),
                    'notes' => $assessment['notes'] ?? null,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Competency assessment recorded.');
    }

    /**
     * Employee competency profile with radar chart data.
     */
    public function employeeProfile(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $employee = User::findOrFail($profile->user_id);

        // Latest assessment per competency
        $latestAssessments = HrCompetencyAssessment::where('employee_profile_id', $profile->id)
            ->with(['competency', 'assessor:id,name'])
            ->orderByDesc('assessment_date')
            ->get()
            ->unique('competency_id')
            ->values()
            ->map(fn (HrCompetencyAssessment $assessment) => $this->serializeAssessment($assessment));

        // Historical assessments
        $history = HrCompetencyAssessment::where('employee_profile_id', $profile->id)
            ->with(['competency:id,name', 'assessor:id,name'])
            ->orderByDesc('assessment_date')
            ->limit(50)
            ->get()
            ->map(fn (HrCompetencyAssessment $assessment) => $this->serializeAssessment($assessment));

        // Build radar chart data
        $radarData = $latestAssessments->map(fn ($a) => [
            'competency' => $a['competency']['name'] ?? '',
            'level' => $a['proficiency_level'],
            'target' => $a['target_level'],
        ])->toArray();

        return Inertia::render('hr/performance/competencies/profile', [
            'employee' => $employee->only('id', 'name', 'email'),
            'profile' => $profile,
            'latestAssessments' => $latestAssessments,
            'history' => $history,
            'radarData' => $radarData,
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    private function serializeAssessment(HrCompetencyAssessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'competency' => $assessment->competency ? [
                'id' => $assessment->competency->id,
                'name' => $assessment->competency->name,
                'category' => $assessment->competency->category,
            ] : null,
            'assessor' => $assessment->assessor ? [
                'id' => $assessment->assessor->id,
                'name' => $assessment->assessor->name,
            ] : null,
            'assessed_level' => $assessment->assessed_level,
            'proficiency_level' => $assessment->assessed_level,
            'target_level' => $assessment->target_level,
            'assessment_date' => $assessment->assessment_date?->toDateString(),
            'notes' => $assessment->notes,
        ];
    }
}
