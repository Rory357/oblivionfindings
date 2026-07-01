<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Services\EngagementService;
use App\Domain\Hr\Services\WellbeingCareService;
use App\Domain\Hr\Services\WellbeingIndicatorService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WellbeingController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly WellbeingIndicatorService $wellbeingIndicatorService,
        private readonly EngagementService $engagementService,
        private readonly WellbeingCareService $careService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.wellbeing.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $canManage = $user->canDo('hr.performance.manage');
        $statusFilter = (string) $request->string('status', 'all');
        $ownerFilter = $request->integer('owner');
        $allowedStatuses = ['open', 'in_progress', 'completed', 'cancelled'];
        $openStatuses = ['open', 'in_progress'];

        if (! in_array($statusFilter, array_merge(['all'], $allowedStatuses), true)) {
            $statusFilter = 'all';
        }

        $summary = $this->wellbeingIndicatorService->getSummary($tenantId);
        $flaggedStaff = $this->wellbeingIndicatorService->getFlaggedStaff($tenantId)->take(30)->values();

        $activeStaffCount = HrEmployeeProfile::query()
            ->where('is_active', true)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->count();

        $surveys = HrEngagementSurvey::query()
            ->with('questions:id,survey_id,question_type')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(! $canManage, fn ($query) => $query->whereIn('status', ['published', 'closed']))
            ->orderByRaw("CASE WHEN status = 'published' THEN 0 WHEN status = 'draft' THEN 1 WHEN status = 'closed' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit($canManage ? 60 : 20)
            ->get()
            ->map(fn (HrEngagementSurvey $survey) => $this->presentSurvey($survey, $user, $canManage, $activeStaffCount))
            ->values();

        $actionPlans = HrEngagementActionPlan::query()
            ->with(['owner:id,name', 'survey:id,title', 'staff:id,name', 'notes.author:id,name'])
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(! $canManage, fn ($query) => $query->where('owner_user_id', $user->id))
            ->when($statusFilter !== 'all', fn ($query) => $query->where('status', $statusFilter))
            ->when($canManage && $ownerFilter > 0, fn ($query) => $query->where('owner_user_id', $ownerFilter))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 WHEN status = 'in_progress' THEN 1 ELSE 2 END")
            ->orderBy('due_date')
            ->limit(60)
            ->get()
            ->map(fn (HrEngagementActionPlan $plan) => $this->presentActionPlan($plan, $canManage, $user->id))
            ->values();

        // Owners that already hold plans (kept for backward-compatible filters).
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

        // Full active-staff list for assigning owners / picking subjects in the wizards.
        $staffOptions = $this->ownerOptions($tenantId);

        $slaSummary = $this->engagementService->actionPlanSlaSummary($tenantId, $user->id, $canManage);
        $ownerWorkload = $canManage ? $this->engagementService->actionPlanOwnerWorkload($tenantId) : [];

        // Latest published/closed survey with eNPS — feeds the hero stat.
        $latestEnps = null;
        $liveSurvey = null;
        foreach ($surveys as $row) {
            if ($liveSurvey === null && $row['status'] === 'published') {
                $liveSurvey = $row;
            }
            if ($latestEnps === null && $row['enps'] !== null) {
                $latestEnps = $row['enps'];
            }
        }

        $visibleFlagged = $flaggedStaff;
        $needAttention = $visibleFlagged
            ->filter(fn ($p) => $p['flag_level'] === 'red' || $p['latest_action'] === null)
            ->count();
        $greenPct = $summary['total_staff'] > 0
            ? (int) round($summary['healthy'] / $summary['total_staff'] * 100)
            : 100;

        $heroSummary = [
            ...$summary,
            'open_plans' => $slaSummary['open_total'],
            'overdue' => $slaSummary['overdue'],
            'enps' => $latestEnps,
            'needAttention' => $needAttention,
            'greenPct' => $greenPct,
        ];

        $needs = $this->buildNeeds($visibleFlagged, $slaSummary, $surveys);

        return Inertia::render('hr/wellbeing/index', [
            'wellbeingSummary' => $heroSummary,
            'flaggedStaff' => $flaggedStaff,
            'surveys' => $surveys,
            'liveSurvey' => $liveSurvey,
            'actionPlans' => $actionPlans,
            'slaSummary' => $slaSummary,
            'ownerWorkload' => $ownerWorkload,
            'actionPlanOwners' => $actionPlanOwners,
            'staffOptions' => $staffOptions,
            'needs' => $needs,
            'templates' => $canManage ? $this->engagementService->templates() : [],
            'sites' => $canManage ? $this->siteOptions($tenantId) : [],
            'activeStaffCount' => $activeStaffCount,
            'tenantTrend' => $canManage ? $this->wellbeingIndicatorService->getTenantTrend($tenantId) : [],
            'my' => $this->employeeView($user, $tenantId),
            'filters' => [
                'status' => $statusFilter,
                'owner' => $canManage && $ownerFilter > 0 ? $ownerFilter : null,
            ],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSurvey(HrEngagementSurvey $survey, $user, bool $canManage, int $activeStaffCount): array
    {
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

        $recipientCount = ($survey->audience_type === 'site')
            ? $this->engagementService->recipientCount($survey)
            : $activeStaffCount;
        $responseCount = $survey->responses()->count();
        $responsePct = $recipientCount > 0 ? (int) round($responseCount / $recipientCount * 100) : 0;

        $closesInDays = $survey->ends_at
            ? (int) now()->startOfDay()->diffInDays($survey->ends_at->copy()->startOfDay(), false)
            : null;

        return [
            'id' => $survey->id,
            'title' => $survey->title,
            'description' => $survey->description,
            'survey_type' => $survey->survey_type,
            'status' => $survey->status,
            'is_anonymous' => (bool) $survey->is_anonymous,
            'audience_type' => $survey->audience_type ?? 'all',
            'audience_site_ids' => $survey->audience_site_ids ?? [],
            'starts_at' => optional($survey->starts_at)->toDateString(),
            'ends_at' => optional($survey->ends_at)->toDateString(),
            'window' => $this->surveyWindow($survey),
            'closes_in_days' => $closesInDays,
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
            'response_count' => $responseCount,
            'recipient_count' => $recipientCount,
            'response_pct' => $responsePct,
            'enps' => $canManage && in_array($survey->survey_type, ['enps'], true)
                ? ($this->engagementService->summary($survey)['enps'] ?? null)
                : null,
            'has_responded' => $hasResponded,
        ];
    }

    private function surveyWindow(HrEngagementSurvey $survey): string
    {
        if (! $survey->starts_at && ! $survey->ends_at) {
            return 'Not scheduled';
        }

        $start = $survey->starts_at?->format('j M');
        $end = $survey->ends_at?->format('j M');

        if ($start && $end) {
            return $start . ' – ' . $end;
        }

        return $start ?? $end ?? 'Not scheduled';
    }

    /**
     * @return array<string, mixed>
     */
    private function presentActionPlan(HrEngagementActionPlan $plan, bool $canManage, int $viewerId): array
    {
        $openStatuses = ['open', 'in_progress'];
        $daysUntilDue = $plan->due_date
            ? now()->startOfDay()->diffInDays($plan->due_date->copy()->startOfDay(), false)
            : null;

        $linkLabel = $plan->survey
            ? ('From ' . $plan->survey->title)
            : ($plan->staff ? ('From flag · ' . $plan->staff->name) : 'Standalone');

        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'description' => $plan->description,
            'priority' => $plan->priority,
            'status' => $plan->status,
            'progress_percent' => (int) $plan->progress_percent,
            'due_date' => optional($plan->due_date)->toDateString(),
            'days_until_due' => $daysUntilDue,
            'is_overdue' => $daysUntilDue !== null && $daysUntilDue < 0 && in_array($plan->status, $openStatuses, true),
            'is_due_soon' => $daysUntilDue !== null && $daysUntilDue >= 0 && $daysUntilDue <= 7 && in_array($plan->status, $openStatuses, true),
            'can_update' => $canManage || $plan->owner_user_id === $viewerId,
            'owner' => $plan->owner ? ['id' => $plan->owner->id, 'name' => $plan->owner->name] : null,
            'survey' => $plan->survey ? ['id' => $plan->survey->id, 'title' => $plan->survey->title] : null,
            'staff' => $plan->staff ? ['id' => $plan->staff->id, 'name' => $plan->staff->name] : null,
            'source_type' => $plan->source_type,
            'link_label' => $linkLabel,
            'notes' => $plan->relationLoaded('notes')
                ? $plan->notes->map(fn ($note) => [
                    'id' => $note->id,
                    'author' => $note->author?->name ?? ($note->kind === 'system' ? 'System' : 'Unknown'),
                    'kind' => $note->kind,
                    'body' => $note->body,
                    'created_human' => $note->created_at ? Carbon::parse($note->created_at)->diffForHumans() : null,
                ])->values()->all()
                : [],
        ];
    }

    private function ownerOptions(?int $tenantId): \Illuminate\Support\Collection
    {
        return HrEmployeeProfile::query()
            ->where('is_active', true)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->with('user:id,name')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($owner) => ['id' => $owner->id, 'name' => $owner->name]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function siteOptions(?int $tenantId): array
    {
        return Site::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Site $site) use ($tenantId) {
                $staff = HrEmployeeProfile::query()
                    ->where('is_active', true)
                    ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                    ->where(function ($q) use ($site) {
                        $q->where('primary_site_id', $site->id)
                            ->orWhereJsonContains('secondary_site_ids', $site->id);
                    })
                    ->count();

                return ['id' => $site->id, 'name' => $site->name, 'staff_count' => $staff];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildNeeds(\Illuminate\Support\Collection $flagged, array $sla, \Illuminate\Support\Collection $surveys): array
    {
        $needs = [];

        $unackedRed = $flagged->filter(fn ($p) => $p['flag_level'] === 'red' && $p['latest_action'] === null)->count();
        if ($unackedRed > 0) {
            $needs[] = ['key' => 'red', 'label' => $unackedRed . ' red ' . ($unackedRed === 1 ? 'flag' : 'flags') . ' unacknowledged', 'tab' => 'signals'];
        }

        if (($sla['overdue'] ?? 0) > 0) {
            $needs[] = ['key' => 'over', 'label' => $sla['overdue'] . ' ' . ($sla['overdue'] === 1 ? 'plan' : 'plans') . ' overdue', 'tab' => 'plans'];
        }

        $closing = $surveys->first(fn ($s) => $s['status'] === 'published' && $s['closes_in_days'] !== null && $s['closes_in_days'] >= 0 && $s['closes_in_days'] <= 3);
        if ($closing) {
            $label = $closing['closes_in_days'] === 0
                ? $closing['title'] . ' closes today'
                : $closing['title'] . ' closes in ' . $closing['closes_in_days'] . ' ' . ($closing['closes_in_days'] === 1 ? 'day' : 'days');
            $needs[] = ['key' => 'close', 'label' => $label, 'tab' => 'surveys'];
        }

        return $needs;
    }

    /**
     * Employee (My HR) view-model: the viewer's own open surveys and the
     * non-private check-ins logged about them.
     *
     * @return array<string, mixed>
     */
    private function employeeView($user, ?int $tenantId): array
    {
        $respondentKey = (string) config('app.key');

        $mySurveys = HrEngagementSurvey::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereIn('status', ['published', 'closed'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (HrEngagementSurvey $survey) use ($user, $respondentKey) {
                $hash = hash_hmac('sha256', $survey->id . ':' . $user->id, $respondentKey);
                $responded = $survey->responses()
                    ->where(fn ($q) => $survey->is_anonymous ? $q->where('respondent_hash', $hash) : $q->where('user_id', $user->id))
                    ->exists();
                $open = $survey->status === 'published' && ! $responded
                    && (! $survey->ends_at || ! $survey->ends_at->isPast());

                return [
                    'id' => $survey->id,
                    'title' => $survey->title,
                    'is_anonymous' => (bool) $survey->is_anonymous,
                    'closes_in_days' => $survey->ends_at ? (int) now()->startOfDay()->diffInDays($survey->ends_at->copy()->startOfDay(), false) : null,
                    'open' => $open,
                    'responded' => $responded,
                ];
            })
            ->filter(fn ($s) => $s['open'] || $s['responded'])
            ->values();

        $myCheckins = HrWellbeingCheckin::query()
            ->where('staff_user_id', $user->id)
            ->where('is_private', false)
            ->with('manager:id,name')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (HrWellbeingCheckin $checkin) => [
                'id' => $checkin->id,
                'type' => $checkin->type,
                'manager' => $checkin->manager?->name,
                'notes' => $checkin->notes,
                'created_human' => $checkin->created_at ? Carbon::parse($checkin->created_at)->diffForHumans() : null,
                'acknowledged' => $checkin->acknowledged_at !== null,
            ])
            ->values();

        $firstName = trim((string) $user->name);
        $firstName = $firstName !== '' ? explode(' ', $firstName)[0] : 'there';

        return [
            'name' => $firstName,
            'surveys' => $mySurveys,
            'checkins' => $myCheckins,
        ];
    }

    public function showSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

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
            'responses' => $canManage ? $survey->responses()
                ->with($survey->is_anonymous ? [] : ['user:id,name'])
                ->orderByDesc('submitted_at')
                ->get()
                ->map(fn ($r, int $index) => [
                    'id' => $r->id,
                    'respondent' => $survey->is_anonymous ? ('Respondent ' . ($index + 1)) : ($r->user?->name ?? 'Unknown'),
                    'answers' => $r->answers ?? [],
                    'overall_score' => $r->overall_score,
                    'submitted_at' => optional($r->submitted_at)->toDateTimeString(),
                ])->values() : [],
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
            'audience_type' => ['nullable', 'string', Rule::in(['all', 'site'])],
            'audience_site_ids' => ['nullable', 'array'],
            'audience_site_ids.*' => ['integer'],
            'publish' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_type' => ['required', 'string', Rule::in(['enps', 'scale', 'text', 'choice', 'boolean'])],
            'questions.*.question_text' => ['required', 'string', 'max:1000'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.is_required' => ['nullable', 'boolean'],
            'questions.*.sort_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $validated['tenant_id'] = $this->resolveHrTenantIdForUser($user);
        $publish = (bool) ($validated['publish'] ?? false);

        $survey = $this->engagementService->createSurvey($user, $validated);

        if ($publish) {
            $this->engagementService->publishSurvey($survey, $user);

            return redirect()->route('hr.wellbeing.index')->with('success', 'Survey published — invitations sent.');
        }

        return redirect()->route('hr.wellbeing.surveys.show', $survey->id)->with('success', 'Survey created.');
    }

    public function updateSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'survey_type' => ['sometimes', 'string', Rule::in(['pulse', 'enps', 'engagement'])],
            'is_anonymous' => ['boolean'],
            'audience_type' => ['nullable', 'string', Rule::in(['all', 'site'])],
            'audience_site_ids' => ['nullable', 'array'],
            'audience_site_ids.*' => ['integer'],
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $this->engagementService->publishSurvey($survey, $user);

        return redirect()->back()->with('success', 'Survey published.');
    }

    public function closeSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $this->engagementService->closeSurvey($survey, $user);

        return redirect()->back()->with('success', 'Survey closed.');
    }

    public function submitResponse(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);
        $tenantStaffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $ownerRule = $tenantStaffIds !== [] ? Rule::in($tenantStaffIds) : Rule::exists('users', 'id');

        $validated = $request->validate([
            'owner_user_id' => ['required', 'integer', $ownerRule],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::in(['low', 'medium', 'high'])],
            'status' => ['nullable', 'string', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'due_date' => ['nullable', 'date'],
        ]);

        $plan = HrEngagementActionPlan::create([
            'survey_id' => $survey->id,
            'tenant_id' => $survey->tenant_id,
            'owner_user_id' => $validated['owner_user_id'],
            'source_type' => 'survey',
            'source_id' => $survey->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'],
            'status' => $validated['status'] ?? 'open',
            'progress_percent' => (int) ($validated['progress_percent'] ?? 0),
            'due_date' => $validated['due_date'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->careService->addPlanNote($plan, $user, 'Plan created from survey: ' . $survey->title . '.', 'system');

        return redirect()->back()->with('success', 'Action plan created.');
    }

    public function updateActionPlan(Request $request, HrEngagementActionPlan $plan)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $plan->tenant_id);

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
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = $validated['note'] ?? null;
        unset($validated['note']);

        $previousStatus = $plan->status;
        $payload = [
            ...$validated,
            'updated_by' => $user->id,
        ];
        if (($payload['status'] ?? null) === 'completed') {
            $payload['completed_at'] = now()->toDateString();
            $payload['progress_percent'] = 100;
        }

        $plan->update($payload);

        if (array_key_exists('status', $validated) && $validated['status'] !== $previousStatus) {
            $this->careService->addPlanNote($plan, $user, 'Status changed to ' . str_replace('_', ' ', $validated['status']) . '.', 'system');
        }
        if ($note !== null && trim($note) !== '') {
            $this->careService->addPlanNote($plan, $user, trim($note), 'note');
        }

        return redirect()->back()->with('success', 'Action plan updated.');
    }

    /*
    |--------------------------------------------------------------------------
    | Flag triage (acknowledge / snooze / dismiss / undo)
    |--------------------------------------------------------------------------
    */
    public function acknowledgeFlag(Request $request, \App\Models\User $user)
    {
        return $this->storeFlagAction($request, $user, 'acknowledge');
    }

    public function snoozeFlag(Request $request, \App\Models\User $user)
    {
        return $this->storeFlagAction($request, $user, 'snooze');
    }

    public function dismissFlag(Request $request, \App\Models\User $user)
    {
        return $this->storeFlagAction($request, $user, 'dismiss');
    }

    private function storeFlagAction(Request $request, \App\Models\User $staff, string $action)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertFlagSubjectInTenant($tenantId, $staff->id);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'snooze_until' => [$action === 'snooze' ? 'required' : 'nullable', 'date', 'after:today'],
        ]);

        $this->careService->recordFlagAction(
            $actor,
            $tenantId,
            $staff->id,
            $action,
            $validated['reason'] ?? null,
            $validated['snooze_until'] ?? null,
        );

        return redirect()->back()->with('success', 'Flag ' . $action . 'd.');
    }

    public function undoFlag(Request $request, \App\Models\User $user)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertFlagSubjectInTenant($tenantId, $user->id);

        $this->careService->undoLastFlagAction($actor, $user->id);

        return redirect()->back()->with('success', 'Action undone.');
    }

    private function assertFlagSubjectInTenant(int $tenantId, int $staffUserId): void
    {
        $profileTenant = HrEmployeeProfile::query()->where('user_id', $staffUserId)->value('tenant_id');
        $this->assertHrTenantAccess($tenantId, is_numeric($profileTenant) ? (int) $profileTenant : null);
    }

    /*
    |--------------------------------------------------------------------------
    | Wellbeing check-ins
    |--------------------------------------------------------------------------
    */
    public function storeCheckin(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $staffRule = $staffIds !== [] ? Rule::in($staffIds) : Rule::exists('users', 'id');

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer', $staffRule],
            'type' => ['required', 'string', Rule::in(['1on1', 'welfare', 'return_to_work'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'mood' => ['nullable', 'string', Rule::in(['good', 'mixed', 'low'])],
            'follow_up_date' => ['nullable', 'date'],
            'is_private' => ['boolean'],
        ]);

        $this->careService->createCheckin($actor, $tenantId, $validated);

        return redirect()->back()->with('success', 'Check-in logged.');
    }

    public function updateCheckin(Request $request, HrWellbeingCheckin $checkin)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertHrTenantAccess($tenantId, $checkin->tenant_id);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(['1on1', 'welfare', 'return_to_work'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'mood' => ['nullable', 'string', Rule::in(['good', 'mixed', 'low'])],
            'follow_up_date' => ['nullable', 'date'],
            'is_private' => ['boolean'],
        ]);

        $this->careService->updateCheckin($checkin, $validated);

        return redirect()->back()->with('success', 'Check-in updated.');
    }

    public function acknowledgeCheckin(Request $request, HrWellbeingCheckin $checkin)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        // Only the staff member the check-in is about may acknowledge it, and never a private one.
        abort_unless($checkin->staff_user_id === $actor->id && ! $checkin->is_private, 403);

        $this->careService->acknowledgeCheckin($checkin);

        return redirect()->back()->with('success', 'Check-in acknowledged.');
    }

    /*
    |--------------------------------------------------------------------------
    | EAP referrals
    |--------------------------------------------------------------------------
    */
    public function storeEapReferral(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $staffRule = $staffIds !== [] ? Rule::in($staffIds) : Rule::exists('users', 'id');

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer', $staffRule],
            'reason_category' => ['required', 'string', Rule::in(['workload', 'personal', 'wellbeing', 'other'])],
            'provider' => ['nullable', 'string', 'max:255'],
            'consent_given' => ['accepted'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->careService->createEapReferral($actor, $tenantId, $validated);

        return redirect()->back()->with('success', 'EAP referral submitted confidentially.');
    }

    /*
    |--------------------------------------------------------------------------
    | Standalone action plans + lifecycle + notes
    |--------------------------------------------------------------------------
    */
    public function storeStandaloneActionPlan(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $ownerRule = $staffIds !== [] ? Rule::in($staffIds) : Rule::exists('users', 'id');

        $validated = $request->validate([
            'owner_user_id' => ['required', 'integer', $ownerRule],
            'staff_user_id' => ['nullable', 'integer', $staffIds !== [] ? Rule::in($staffIds) : Rule::exists('users', 'id')],
            'survey_id' => ['nullable', 'integer', Rule::exists('hr_engagement_surveys', 'id')],
            'source_type' => ['nullable', 'string', Rule::in(['survey', 'flag', 'manual'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::in(['low', 'medium', 'high'])],
            'due_date' => ['nullable', 'date'],
        ]);

        if (! empty($validated['survey_id'])) {
            $surveyTenant = HrEngagementSurvey::query()->whereKey($validated['survey_id'])->value('tenant_id');
            $this->assertHrTenantAccess($tenantId, is_numeric($surveyTenant) ? (int) $surveyTenant : null);
        }

        $this->careService->createStandalonePlan($actor, $tenantId, $validated);

        return redirect()->back()->with('success', 'Action plan created.');
    }

    public function reopenActionPlan(Request $request, HrEngagementActionPlan $plan)
    {
        $actor = $this->authorisePlanManager($request, $plan);
        $this->careService->reopenPlan($plan, $actor);

        return redirect()->back()->with('success', 'Action plan reopened.');
    }

    public function cancelActionPlan(Request $request, HrEngagementActionPlan $plan)
    {
        $actor = $this->authorisePlanManager($request, $plan);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $this->careService->cancelPlan($plan, $actor, $validated['reason'] ?? null);

        return redirect()->back()->with('success', 'Action plan cancelled.');
    }

    public function storeActionPlanNote(Request $request, HrEngagementActionPlan $plan)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertHrTenantAccess($tenantId, $plan->tenant_id);
        abort_unless($actor->canDo('hr.performance.manage') || $plan->owner_user_id === $actor->id, 403);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $this->careService->addPlanNote($plan, $actor, trim($validated['body']), 'note');

        return redirect()->back()->with('success', 'Note added.');
    }

    private function authorisePlanManager(Request $request, HrEngagementActionPlan $plan): \App\Models\User
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertHrTenantAccess($tenantId, $plan->tenant_id);

        return $actor;
    }

    /*
    |--------------------------------------------------------------------------
    | Survey operations (duplicate / nudge / archive / delete / export)
    |--------------------------------------------------------------------------
    */
    public function duplicateSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $copy = $this->engagementService->duplicateSurvey($survey, $actor);

        return redirect()->route('hr.wellbeing.surveys.show', $copy->id)->with('success', 'Survey duplicated as a draft.');
    }

    public function nudgeSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $count = $this->engagementService->nudgeNonResponders($survey, $actor);

        return redirect()->back()->with('success', $count > 0 ? ('Nudged ' . $count . ' non-' . ($count === 1 ? 'responder' : 'responders') . '.') : 'Everyone has already responded.');
    }

    public function archiveSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $this->engagementService->archiveSurvey($survey, $actor);

        return redirect()->back()->with('success', 'Survey archived.');
    }

    public function destroySurvey(Request $request, HrEngagementSurvey $survey)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $this->engagementService->deleteSurvey($survey);

        return redirect()->route('hr.wellbeing.index')->with('success', 'Draft survey deleted.');
    }

    public function exportSurvey(Request $request, HrEngagementSurvey $survey): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $survey->load(['questions', 'responses']);
        $summary = $this->engagementService->summary($survey);
        $isAnonymous = (bool) $survey->is_anonymous;

        $filename = 'survey-' . $survey->id . '-results.csv';

        return response()->streamDownload(function () use ($survey, $summary, $isAnonymous) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Survey', $survey->title]);
            fputcsv($out, ['Type', $survey->survey_type]);
            fputcsv($out, ['Anonymous', $isAnonymous ? 'Yes' : 'No']);
            fputcsv($out, ['Responses', $summary['response_count']]);
            if ($summary['enps'] !== null) {
                fputcsv($out, ['eNPS', $summary['enps']]);
            }
            fputcsv($out, []);

            // Per-question averages (anonymity-safe aggregate).
            fputcsv($out, ['Question', 'Type', 'Responses', 'Average']);
            foreach ($summary['question_stats'] as $stat) {
                fputcsv($out, [$stat['question_text'], $stat['question_type'], $stat['responses'], $stat['average'] ?? '—']);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
