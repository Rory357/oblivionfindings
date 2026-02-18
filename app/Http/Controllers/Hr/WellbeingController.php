<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Services\EngagementService;
use App\Domain\Hr\Services\WellbeingIndicatorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class WellbeingController extends Controller
{
    public function __construct(
        private readonly WellbeingIndicatorService $wellbeingIndicatorService,
        private readonly EngagementService $engagementService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.wellbeing.view'), 403);

        $tenantId = $user->tenant_id ?? null;
        $canManage = $user->canDo('hr.performance.manage');
        $statusFilter = (string) $request->string('status', 'all');
        $ownerFilter = $request->integer('owner');
        $allowedStatuses = ['open', 'in_progress', 'completed', 'cancelled'];
        $openStatuses = ['open', 'in_progress'];

        if (! in_array($statusFilter, array_merge(['all'], $allowedStatuses), true)) {
            $statusFilter = 'all';
        }

        $summary = $this->wellbeingIndicatorService->getSummary($tenantId);
        $flaggedStaff = $this->wellbeingIndicatorService->getFlaggedStaff($tenantId)->take(20)->values();

        $surveys = HrEngagementSurvey::query()
            ->with('questions:id,survey_id,question_type')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(! $canManage, fn ($query) => $query->whereIn('status', ['published', 'closed']))
            ->orderByDesc('created_at')
            ->limit($canManage ? 50 : 20)
            ->get()
            ->map(function (HrEngagementSurvey $survey) use ($user, $canManage) {
                $respondentHash = hash_hmac('sha256', $survey->id . ':' . $user->id, (string) config('app.key'));
                $hasResponded = $survey->responses()
                    ->where(function ($query) use ($survey, $user, $respondentHash) {
                        if ($survey->is_anonymous) {
                            $query->where('respondent_hash', $respondentHash);
                        } else {
                            $query->where('user_id', $user->id);
                        }
                    })
                    ->exists();

                return [
                    'id' => $survey->id,
                    'title' => $survey->title,
                    'description' => $survey->description,
                    'survey_type' => $survey->survey_type,
                    'status' => $survey->status,
                    'is_anonymous' => (bool) $survey->is_anonymous,
                    'starts_at' => optional($survey->starts_at)->toDateString(),
                    'ends_at' => optional($survey->ends_at)->toDateString(),
                    'question_count' => $survey->questions->count(),
                    'questions' => $canManage
                        ? $survey->questions
                            ->sortBy('sort_order')
                            ->values()
                            ->map(fn ($question) => [
                                'question_type' => $question->question_type,
                                'question_text' => $question->question_text,
                                'options' => $question->options ?? [],
                                'is_required' => (bool) $question->is_required,
                                'sort_order' => (int) $question->sort_order,
                            ])
                            ->all()
                        : [],
                    'response_count' => $survey->responses()->count(),
                    'has_responded' => $hasResponded,
                ];
            })
            ->values();

        $actionPlans = HrEngagementActionPlan::query()
            ->with(['owner:id,name', 'survey:id,title'])
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(! $canManage, fn ($query) => $query->where('owner_user_id', $user->id))
            ->when($statusFilter !== 'all', fn ($query) => $query->where('status', $statusFilter))
            ->when($canManage && $ownerFilter > 0, fn ($query) => $query->where('owner_user_id', $ownerFilter))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 WHEN status = 'in_progress' THEN 1 ELSE 2 END")
            ->orderBy('due_date')
            ->limit(60)
            ->get()
            ->map(function (HrEngagementActionPlan $plan) use ($canManage, $openStatuses, $user) {
                $daysUntilDue = $plan->due_date
                    ? now()->startOfDay()->diffInDays($plan->due_date->copy()->startOfDay(), false)
                    : null;

                return [
                    'id' => $plan->id,
                    'title' => $plan->title,
                    'priority' => $plan->priority,
                    'status' => $plan->status,
                    'progress_percent' => (int) $plan->progress_percent,
                    'due_date' => optional($plan->due_date)->toDateString(),
                    'days_until_due' => $daysUntilDue,
                    'is_overdue' => $daysUntilDue !== null && $daysUntilDue < 0 && in_array($plan->status, $openStatuses, true),
                    'is_due_soon' => $daysUntilDue !== null && $daysUntilDue >= 0 && $daysUntilDue <= 7 && in_array($plan->status, $openStatuses, true),
                    'can_update' => $canManage || $plan->owner_user_id === $user->id,
                    'owner' => $plan->owner ? ['id' => $plan->owner->id, 'name' => $plan->owner->name] : null,
                    'survey' => $plan->survey ? ['id' => $plan->survey->id, 'title' => $plan->survey->title] : null,
                ];
            })
            ->values();

        $actionPlanOwners = HrEngagementActionPlan::query()
            ->with('owner:id,name')
            ->whereNotNull('owner_user_id')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get()
            ->map(fn (HrEngagementActionPlan $plan) => $plan->owner ? ['id' => $plan->owner->id, 'name' => $plan->owner->name] : null)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $slaSummary = $this->engagementService->actionPlanSlaSummary($tenantId, $user->id, $canManage);
        $ownerWorkload = $canManage ? $this->engagementService->actionPlanOwnerWorkload($tenantId) : [];

        return Inertia::render('hr/wellbeing/index', [
            'wellbeingSummary' => $summary,
            'flaggedStaff' => $flaggedStaff,
            'surveys' => $surveys,
            'actionPlans' => $actionPlans,
            'slaSummary' => $slaSummary,
            'ownerWorkload' => $ownerWorkload,
            'actionPlanOwners' => $actionPlanOwners,
            'filters' => [
                'status' => $statusFilter,
                'owner' => $canManage && $ownerFilter > 0 ? $ownerFilter : null,
            ],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function showSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $survey->tenant_id);

        $canDashboardView = $user->canDo('hr.wellbeing.view');
        $canManage = $user->canDo('hr.performance.manage');
        if (! $canDashboardView && ! $canManage && $survey->status === 'draft') {
            abort(404);
        }

        $survey->load(['questions', 'actionPlans.owner:id,name']);
        $respondentHash = hash_hmac('sha256', $survey->id . ':' . $user->id, (string) config('app.key'));
        $actionPlanOwners = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->when($survey->tenant_id !== null, fn ($query) => $query->where('tenant_id', $survey->tenant_id))
            ->with('user:id,name')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($owner) => ['id' => $owner->id, 'name' => $owner->name]);

        $response = $survey->responses()
            ->where(function ($query) use ($survey, $user, $respondentHash) {
                if ($survey->is_anonymous) {
                    $query->where('respondent_hash', $respondentHash);
                } else {
                    $query->where('user_id', $user->id);
                }
            })
            ->first();

        return Inertia::render('hr/wellbeing/survey', [
            'survey' => [
                'id' => $survey->id,
                'title' => $survey->title,
                'description' => $survey->description,
                'survey_type' => $survey->survey_type,
                'status' => $survey->status,
                'is_anonymous' => (bool) $survey->is_anonymous,
                'starts_at' => optional($survey->starts_at)->toDateString(),
                'ends_at' => optional($survey->ends_at)->toDateString(),
                'questions' => $survey->questions->map(fn ($question) => [
                    'id' => $question->id,
                    'question_type' => $question->question_type,
                    'question_text' => $question->question_text,
                    'options' => $question->options ?? [],
                    'is_required' => (bool) $question->is_required,
                    'sort_order' => (int) $question->sort_order,
                ])->values(),
                'action_plans' => $survey->actionPlans->map(fn (HrEngagementActionPlan $plan) => [
                    'id' => $plan->id,
                    'title' => $plan->title,
                    'status' => $plan->status,
                    'priority' => $plan->priority,
                    'progress_percent' => (int) $plan->progress_percent,
                    'due_date' => optional($plan->due_date)->toDateString(),
                    'owner' => $plan->owner ? ['id' => $plan->owner->id, 'name' => $plan->owner->name] : null,
                ])->values(),
            ],
            'existingResponse' => $response ? [
                'id' => $response->id,
                'answers' => $response->answers ?? [],
                'submitted_at' => optional($response->submitted_at)->toDateTimeString(),
            ] : null,
            'summary' => $canManage ? $this->engagementService->summary($survey) : null,
            'actionPlanOwners' => $actionPlanOwners,
            'can' => [
                'manage' => $canManage,
                'respond' => $survey->status === 'published',
            ],
        ]);
    }

    public function storeSurvey(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'survey_type' => ['required', 'string', Rule::in(['pulse', 'enps', 'engagement'])],
            'is_anonymous' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_type' => ['required', 'string', Rule::in(['enps', 'scale', 'text', 'choice', 'boolean'])],
            'questions.*.question_text' => ['required', 'string', 'max:1000'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.is_required' => ['nullable', 'boolean'],
            'questions.*.sort_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $survey = $this->engagementService->createSurvey($user, $validated);

        return redirect()->route('hr.wellbeing.surveys.show', $survey->id)->with('success', 'Survey created.');
    }

    public function updateSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $survey->tenant_id);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'survey_type' => ['sometimes', 'string', Rule::in(['pulse', 'enps', 'engagement'])],
            'is_anonymous' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'questions' => ['sometimes', 'array', 'min:1'],
            'questions.*.question_type' => ['required_with:questions', 'string', Rule::in(['enps', 'scale', 'text', 'choice', 'boolean'])],
            'questions.*.question_text' => ['required_with:questions', 'string', 'max:1000'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.is_required' => ['nullable', 'boolean'],
            'questions.*.sort_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->engagementService->updateSurvey($survey, $user, $validated);

        return redirect()->back()->with('success', 'Survey updated.');
    }

    public function publishSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $survey->tenant_id);

        $this->engagementService->publishSurvey($survey, $user);

        return redirect()->back()->with('success', 'Survey published.');
    }

    public function closeSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $survey->tenant_id);

        $this->engagementService->closeSurvey($survey, $user);

        return redirect()->back()->with('success', 'Survey closed.');
    }

    public function submitResponse(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $survey->tenant_id);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        $this->engagementService->submitResponse($survey, $user, $validated['answers']);

        return redirect()->back()->with('success', 'Survey response submitted.');
    }

    public function storeActionPlan(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $survey->tenant_id);

        $validated = $request->validate([
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::in(['low', 'medium', 'high'])],
            'status' => ['nullable', 'string', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'due_date' => ['nullable', 'date'],
        ]);

        HrEngagementActionPlan::create([
            'survey_id' => $survey->id,
            'tenant_id' => $survey->tenant_id,
            'owner_user_id' => $validated['owner_user_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'],
            'status' => $validated['status'] ?? 'open',
            'progress_percent' => (int) ($validated['progress_percent'] ?? 0),
            'due_date' => $validated['due_date'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Action plan created.');
    }

    public function updateActionPlan(Request $request, HrEngagementActionPlan $plan)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $plan->tenant_id);

        $canManage = $user->canDo('hr.performance.manage');
        $isOwner = $plan->owner_user_id === $user->id;
        abort_unless($canManage || $isOwner, 403);

        $validated = $request->validate([
            'title' => [$canManage ? 'sometimes' : 'prohibited', 'string', 'max:255'],
            'description' => [$canManage ? 'nullable' : 'prohibited', 'string', 'max:5000'],
            'priority' => [$canManage ? 'sometimes' : 'prohibited', 'string', Rule::in(['low', 'medium', 'high'])],
            'status' => ['sometimes', 'string', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'progress_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'due_date' => [$canManage ? 'nullable' : 'prohibited', 'date'],
        ]);

        $payload = [
            ...$validated,
            'updated_by' => $user->id,
        ];
        if (($payload['status'] ?? null) === 'completed') {
            $payload['completed_at'] = now()->toDateString();
            $payload['progress_percent'] = 100;
        }

        $plan->update($payload);

        return redirect()->back()->with('success', 'Action plan updated.');
    }

    private function assertTenantAccess(?int $tenantId, ?int $resourceTenantId): void
    {
        if ($tenantId !== null && $tenantId !== $resourceTenantId) {
            abort(404);
        }
    }
}
