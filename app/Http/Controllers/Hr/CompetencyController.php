<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CompetencyController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(private readonly HrPerformanceAccessService $access) {}

    /**
     * List competencies grouped by category.
     */
    public function index(Request $request)
    {
        $user = $this->viewer($request);

        $competencies = HrCompetency::query()
            ->active()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        $grouped = $competencies->groupBy('category')->map(fn ($items) => $items->values());

        $staff = $this->staffOptions($user, useProfileIds: true);

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
        $user = $this->manager($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('hr_competencies', 'name')],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:255'],
            'proficiency_levels' => ['nullable', 'array'],
            'proficiency_levels.*' => ['string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        HrCompetency::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'proficiency_levels' => $data['proficiency_levels'] ?? ['Beginner', 'Developing', 'Competent', 'Advanced', 'Expert'],
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Competency created.');
    }

    /**
     * Update a competency.
     */
    public function update(Request $request, HrCompetency $competency)
    {
        $this->manager($request);

        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('hr_competencies', 'name')->ignore($competency->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', 'max:255'],
            'proficiency_levels' => ['nullable', 'array'],
            'proficiency_levels.*' => ['string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($competency, $data): void {
            HrCompetency::query()
                ->lockForUpdate()
                ->findOrFail($competency->getKey())
                ->update($data);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Competency updated.');
    }

    /**
     * Deactivate (soft-retire) a competency — keeps history, hides from pickers.
     */
    public function deactivate(Request $request, HrCompetency $competency)
    {
        $this->manager($request);

        DB::transaction(function () use ($competency): void {
            HrCompetency::query()
                ->lockForUpdate()
                ->findOrFail($competency->getKey())
                ->update(['is_active' => false]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Competency deactivated.');
    }

    /**
     * Assessor signs off (declares competent) on a recorded assessment.
     */
    public function signOffAssessment(Request $request, HrCompetencyAssessment $assessment)
    {
        $user = $this->manager($request);
        $assessment = $this->access->competencyAssessment($user, $assessment);

        DB::transaction(function () use ($assessment, $user): void {
            $locked = $this->access
                ->applyCompetencyAssessmentScope(HrCompetencyAssessment::query(), $user)
                ->lockForUpdate()
                ->findOrFail($assessment->getKey());

            if ($locked->assessor_declared_at === null) {
                $locked->update([
                    'assessor_declared_at' => now(),
                    'assessed_by' => $locked->assessed_by ?? $user->id,
                ]);
            }
        }, attempts: 1);

        return redirect()->back()->with('success', 'Assessment signed off.');
    }

    /**
     * Upload evidence for a recorded assessment (private disk).
     */
    public function uploadAssessmentEvidence(Request $request, HrCompetencyAssessment $assessment)
    {
        $user = $this->manager($request);
        $assessment = $this->access->competencyAssessment($user, $assessment);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);

        $path = $request->file('file')->store('hr/competency-assessments/'.$assessment->id, 'private');
        $oldPath = null;

        try {
            DB::transaction(function () use ($assessment, $path, $user, &$oldPath): void {
                $locked = $this->access
                    ->applyCompetencyAssessmentScope(HrCompetencyAssessment::query(), $user)
                    ->lockForUpdate()
                    ->findOrFail($assessment->getKey());
                $oldPath = $locked->evidence_path;
                $locked->update(['evidence_path' => $path]);
            }, attempts: 1);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);

            throw $exception;
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('private')->delete($oldPath);
        }

        return redirect()->back()->with('success', 'Evidence uploaded.');
    }

    /**
     * Stream an assessment's evidence (private disk, hardened headers).
     */
    public function downloadAssessmentEvidence(Request $request, HrCompetencyAssessment $assessment)
    {
        $user = $this->viewer($request);
        $assessment = $this->access->competencyAssessment($user, $assessment);
        abort_unless($assessment->evidence_path, 404);

        return $this->streamPrivateAttachment(
            'private',
            $assessment->evidence_path,
            basename($assessment->evidence_path),
            Storage::disk('private')->mimeType($assessment->evidence_path) ?: null,
            'inline',
        );
    }

    public function createAssessment(Request $request)
    {
        $user = $this->manager($request);

        $competencies = HrCompetency::query()
            ->active()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        $staff = $this->staffOptions($user);

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
        $user = $this->manager($request);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer'],
            'assessments' => ['required', 'array', 'min:1'],
            'assessments.*.competency_id' => ['required', 'integer', 'distinct'],
            'assessments.*.proficiency_level' => ['required', 'integer', 'min:1', 'max:5'],
            'assessments.*.target_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'assessments.*.notes' => ['nullable', 'string', 'max:2000'],
            'performance_review_id' => ['nullable', 'integer'],
        ]);

        $profile = $this->access
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
            ->where('user_id', $data['employee_user_id'])
            ->firstOrFail();

        $competencyIds = collect($data['assessments'])
            ->pluck('competency_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $competencies = HrCompetency::query()
            ->active()
            ->whereIn('id', $competencyIds)
            ->get()
            ->keyBy('id');

        if ($competencies->count() !== $competencyIds->count()) {
            throw ValidationException::withMessages([
                'assessments' => 'Select active competencies from the application catalogue.',
            ]);
        }

        $review = isset($data['performance_review_id'])
            ? $this->access->performanceReview($user, (int) $data['performance_review_id'])
            : null;
        if ($review && (int) $review->employee_user_id !== (int) $profile->user_id) {
            throw ValidationException::withMessages([
                'performance_review_id' => 'The performance review must belong to the assessed employee.',
            ]);
        }

        DB::transaction(function () use ($user, $data, $profile, $competencyIds, $review): void {
            $lockedProfile = $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
                ->lockForUpdate()
                ->findOrFail($profile->getKey());
            $lockedCompetencies = HrCompetency::query()
                ->active()
                ->whereIn('id', $competencyIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedCompetencies->count() !== $competencyIds->count()) {
                throw ValidationException::withMessages([
                    'assessments' => 'Select active competencies from the application catalogue.',
                ]);
            }

            $lockedReview = null;
            if ($review) {
                $lockedReview = $this->access
                    ->applyHistoricalSubjectScope(HrPerformanceReview::query(), $user)
                    ->lockForUpdate()
                    ->findOrFail($review->getKey());
                if ((int) $lockedReview->employee_user_id !== (int) $lockedProfile->user_id) {
                    throw ValidationException::withMessages([
                        'performance_review_id' => 'The performance review must belong to the assessed employee.',
                    ]);
                }
            }

            foreach ($data['assessments'] as $assessment) {
                HrCompetencyAssessment::create([
                    'employee_profile_id' => $lockedProfile->id,
                    'competency_id' => $lockedCompetencies->get((int) $assessment['competency_id'])->id,
                    'assessed_by' => $user->id,
                    'performance_review_id' => $lockedReview?->id,
                    'assessed_level' => $assessment['proficiency_level'],
                    'target_level' => $assessment['target_level'] ?? null,
                    'assessment_date' => now()->toDateString(),
                    'notes' => $assessment['notes'] ?? null,
                ]);
            }
        }, attempts: 1);

        return redirect()->back()->with('success', 'Competency assessment recorded.');
    }

    /**
     * Employee competency profile with radar chart data.
     */
    public function employeeProfile(Request $request, HrEmployeeProfile $profile)
    {
        $user = $this->viewer($request);
        $profile = $this->access
            ->applyHistoricalProfileScope(HrEmployeeProfile::query(), $user)
            ->findOrFail($profile->getKey());

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
            'profile' => ['id' => $profile->id],
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
            'has_evidence' => (bool) $assessment->evidence_path,
            'assessor_declared_at' => $assessment->assessor_declared_at?->toDateString(),
        ];
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        return $this->access->currentStaff($user, $user);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return $this->access->currentStaff($user, $user);
    }

    private function staffOptions(User $viewer, bool $useProfileIds = false): array
    {
        return $this->access
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $viewer)
            ->with('user:id,name,email')
            ->get(['id', 'user_id'])
            ->filter(fn (HrEmployeeProfile $profile): bool => $profile->user !== null)
            ->sortBy(fn (HrEmployeeProfile $profile): string => mb_strtolower($profile->user->name))
            ->map(fn (HrEmployeeProfile $profile): array => [
                'id' => $useProfileIds ? $profile->id : $profile->user_id,
                'name' => $profile->user->name,
                'email' => $profile->user->email,
            ])
            ->values()
            ->all();
    }
}
