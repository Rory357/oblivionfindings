<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Services\EngagementService;
use App\Domain\Hr\Services\HrWellbeingAccessService;
use App\Domain\Hr\Services\WellbeingCareService;
use App\Domain\Hr\Services\WellbeingIndicatorService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WellbeingController extends Controller
{
    public function __construct(
        private readonly WellbeingIndicatorService $wellbeingIndicatorService,
        private readonly EngagementService $engagementService,
        private readonly WellbeingCareService $careService,
        private readonly HrWellbeingAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.wellbeing.view'), 403);

        $this->access->currentStaff($user, $user);
        $canManage = $user->canDo('hr.performance.manage');
        $statusFilter = (string) $request->string('status', 'all');
        $ownerFilter = $request->integer('owner');
        $allowedStatuses = ['open', 'in_progress', 'completed', 'cancelled'];
        $openStatuses = ['open', 'in_progress'];

        if (! in_array($statusFilter, array_merge(['all'], $allowedStatuses), true)) {
            $statusFilter = 'all';
        }

        $summary = $canManage
            ? $this->wellbeingIndicatorService->getSummary($user)
            : ['total_staff' => 0, 'flagged_red' => 0, 'flagged_amber' => 0, 'healthy' => 0];
        $flaggedStaff = $canManage
            ? $this->wellbeingIndicatorService->getFlaggedStaff($user)->take(30)->values()
            : collect();

        $activeStaffCount = $canManage ? $this->access->staffOptions($user)->count() : 0;

        $surveys = HrEngagementSurvey::query()
            ->with('questions:id,survey_id,question_type')
            ->when(! $canManage, fn ($query) => $query->whereIn('status', ['published', 'closed']))
            ->orderByRaw("CASE WHEN status = 'published' THEN 0 WHEN status = 'draft' THEN 1 WHEN status = 'closed' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->lazy(100)
            ->filter(fn (HrEngagementSurvey $survey) => $canManage
                ? $this->access->canManageSurvey($user, $survey)
                : $this->engagementService->isCurrentRecipient($survey, $user))
            ->take($canManage ? 60 : 20)
            ->collect()
            ->map(fn (HrEngagementSurvey $survey) => $this->presentSurvey($survey, $user, $canManage, $activeStaffCount))
            ->values();

        $visiblePlans = $this->access->visibleActionPlans($user, $canManage);
        $visibleStaffIds = $this->access->staffOptions($user)->pluck('id');
        $actionPlans = $visiblePlans
            ->when($statusFilter !== 'all', fn (Collection $plans) => $plans->where('status', $statusFilter))
            ->when($canManage && $ownerFilter > 0, fn (Collection $plans) => $plans->where('owner_user_id', $ownerFilter))
            ->sortBy(fn (HrEngagementActionPlan $plan) => sprintf(
                '%d|%s',
                match ($plan->status) {
                    'open' => 0, 'in_progress' => 1, default => 2
                },
                optional($plan->due_date)->toDateString() ?? '9999-12-31',
            ))
            ->take(60)
            ->map(fn (HrEngagementActionPlan $plan) => $this->presentActionPlan($plan, $canManage, $user->id, $visibleStaffIds))
            ->values();

        // Owners that already hold plans (kept for backward-compatible filters).
        $actionPlanOwners = $visiblePlans
            ->map(fn (HrEngagementActionPlan $plan) => $plan->owner ? ['id' => $plan->owner->id, 'name' => $plan->owner->name] : null)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        // Full active-staff list for assigning owners / picking subjects in the wizards.
        $staffOptions = $canManage ? $this->ownerOptions($user) : collect();

        $slaSummary = $this->engagementService->actionPlanSlaSummary($visiblePlans);
        $ownerWorkload = $canManage ? $this->engagementService->actionPlanOwnerWorkload($visiblePlans) : [];

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
            'sites' => $canManage ? $this->siteOptions($user) : [],
            'activeStaffCount' => $activeStaffCount,
            'trend' => $canManage ? $this->wellbeingIndicatorService->getTrend($user) : [],
            'my' => $this->employeeView($user),
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
        $respondentHash = hash_hmac('sha256', $survey->id.':'.$user->id, (string) config('app.key'));
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
            return $start.' – '.$end;
        }

        return $start ?? $end ?? 'Not scheduled';
    }

    /**
     * @return array<string, mixed>
     */
    private function presentActionPlan(
        HrEngagementActionPlan $plan,
        bool $canManage,
        int $viewerId,
        Collection $visibleStaffIds,
    ): array {
        $openStatuses = ['open', 'in_progress'];
        $daysUntilDue = $plan->due_date
            ? now()->startOfDay()->diffInDays($plan->due_date->copy()->startOfDay(), false)
            : null;

        $linkLabel = $plan->survey
            ? ('From '.$plan->survey->title)
            : ($plan->staff ? ('From flag · '.$plan->staff->name) : 'Standalone');

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
                    'author' => $note->kind === 'system'
                        ? 'System'
                        : ($note->author && $visibleStaffIds->contains($note->author->id)
                            ? $note->author->name
                            : 'Unavailable staff'),
                    'kind' => $note->kind,
                    'body' => $note->body,
                    'created_human' => $note->created_at ? Carbon::parse($note->created_at)->diffForHumans() : null,
                ])->values()->all()
                : [],
        ];
    }

    private function ownerOptions(User $viewer): Collection
    {
        return $this->access->staffOptions($viewer)
            ->map(fn ($owner) => ['id' => $owner->id, 'name' => $owner->name]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function siteOptions(User $viewer): array
    {
        $staffIds = $this->access->staffOptions($viewer)->pluck('id');

        return $this->access->visibleSites($viewer)
            ->map(function (Site $site) use ($staffIds) {
                $staff = HrEmployeeProfile::query()
                    ->whereIn('user_id', $staffIds)
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
    private function buildNeeds(Collection $flagged, array $sla, Collection $surveys): array
    {
        $needs = [];

        $unackedRed = $flagged->filter(fn ($p) => $p['flag_level'] === 'red' && $p['latest_action'] === null)->count();
        if ($unackedRed > 0) {
            $needs[] = ['key' => 'red', 'label' => $unackedRed.' red '.($unackedRed === 1 ? 'flag' : 'flags').' unacknowledged', 'tab' => 'signals'];
        }

        if (($sla['overdue'] ?? 0) > 0) {
            $needs[] = ['key' => 'over', 'label' => $sla['overdue'].' '.($sla['overdue'] === 1 ? 'plan' : 'plans').' overdue', 'tab' => 'plans'];
        }

        $closing = $surveys->first(fn ($s) => $s['status'] === 'published' && $s['closes_in_days'] !== null && $s['closes_in_days'] >= 0 && $s['closes_in_days'] <= 3);
        if ($closing) {
            $label = $closing['closes_in_days'] === 0
                ? $closing['title'].' closes today'
                : $closing['title'].' closes in '.$closing['closes_in_days'].' '.($closing['closes_in_days'] === 1 ? 'day' : 'days');
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
    private function employeeView(User $user): array
    {
        $respondentKey = (string) config('app.key');

        $mySurveys = HrEngagementSurvey::query()
            ->whereIn('status', ['published', 'closed'])
            ->orderByDesc('created_at')
            ->lazy(100)
            ->filter(fn (HrEngagementSurvey $survey) => $this->engagementService->isCurrentRecipient($survey, $user))
            ->take(10)
            ->collect()
            ->map(function (HrEngagementSurvey $survey) use ($user, $respondentKey) {
                $hash = hash_hmac('sha256', $survey->id.':'.$user->id, $respondentKey);
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
        $this->access->currentStaff($user, $user);

        $canManage = $user->canDo('hr.performance.manage')
            && $this->access->canManageSurvey($user, $survey);
        $isRecipient = $this->engagementService->isCurrentRecipient($survey, $user);
        abort_unless(
            $canManage || ($isRecipient && in_array($survey->status, ['published', 'closed'], true)),
            404,
        );

        $survey->load($canManage ? ['questions', 'actionPlans.owner:id,name'] : ['questions']);
        $respondentHash = hash_hmac('sha256', $survey->id.':'.$user->id, (string) config('app.key'));
        $actionPlanOwners = $canManage ? $this->ownerOptions($user) : collect();

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
                'action_plans' => $canManage ? $survey->actionPlans
                    ->filter(fn (HrEngagementActionPlan $plan) => $this->access->canAccessActionPlan($user, $plan))
                    ->map(fn (HrEngagementActionPlan $plan) => [
                        'id' => $plan->id,
                        'title' => $plan->title,
                        'status' => $plan->status,
                        'priority' => $plan->priority,
                        'progress_percent' => (int) $plan->progress_percent,
                        'due_date' => optional($plan->due_date)->toDateString(),
                        'owner' => $plan->owner ? ['id' => $plan->owner->id, 'name' => $plan->owner->name] : null,
                    ])->values() : [],
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
                    'respondent' => $survey->is_anonymous ? ('Respondent '.($index + 1)) : ($r->user?->name ?? 'Unknown'),
                    'answers' => $r->answers ?? [],
                    'overall_score' => $r->overall_score,
                    'submitted_at' => optional($r->submitted_at)->toDateTimeString(),
                ])->values() : [],
            'actionPlanOwners' => $actionPlanOwners,
            'can' => [
                'manage' => $canManage,
                'respond' => $isRecipient
                    && $survey->status === 'published'
                    && (! $survey->starts_at || ! $survey->starts_at->isFuture())
                    && (! $survey->ends_at || ! $survey->ends_at->isPast()),
            ],
        ]);
    }

    public function storeSurvey(Request $request)
    {
        $user = $this->manager($request);

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

        $validated['audience_type'] = $validated['audience_type'] ?? 'all';
        $validated['audience_site_ids'] = $this->access->validateSurveyAudience(
            $user,
            $validated['audience_type'],
            $validated['audience_site_ids'] ?? [],
        );
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
        $user = $this->manager($request);
        $survey = $this->access->surveyForManager($user, $survey);

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

        $audienceType = $validated['audience_type'] ?? $survey->audience_type ?? 'all';
        $audienceSiteIds = $validated['audience_site_ids'] ?? $survey->audience_site_ids ?? [];
        $validated['audience_type'] = $audienceType;
        $validated['audience_site_ids'] = $this->access->validateSurveyAudience($user, $audienceType, $audienceSiteIds);

        $this->engagementService->updateSurvey($survey, $user, $validated);

        return redirect()->back()->with('success', 'Survey updated.');
    }

    public function publishSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $this->manager($request);
        $survey = $this->access->surveyForManager($user, $survey);

        $this->engagementService->publishSurvey($survey, $user);

        return redirect()->back()->with('success', 'Survey published.');
    }

    public function closeSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $this->manager($request);
        $survey = $this->access->surveyForManager($user, $survey);

        $this->engagementService->closeSurvey($survey, $user);

        return redirect()->back()->with('success', 'Survey closed.');
    }

    public function submitResponse(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->access->currentStaff($user, $user);
        abort_unless($this->engagementService->isCurrentRecipient($survey, $user), 404);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        $this->engagementService->submitResponse($survey, $user, $validated['answers']);

        return redirect()->back()->with('success', 'Survey response submitted.');
    }

    public function storeActionPlan(Request $request, HrEngagementSurvey $survey)
    {
        $user = $this->manager($request);
        $survey = $this->access->surveyForManager($user, $survey);

        $validated = $request->validate([
            'owner_user_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::in(['low', 'medium', 'high'])],
            'status' => ['nullable', 'string', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'due_date' => ['nullable', 'date'],
        ]);
        $owner = $this->access->currentStaff($user, (int) $validated['owner_user_id']);

        $validated['owner_user_id'] = $owner->id;
        $plan = $this->careService->createSurveyPlan($user, $survey, $validated);
        $this->careService->notifyOwnerAssigned($plan, $user);

        return redirect()->back()->with('success', 'Action plan created.');
    }

    public function updateActionPlan(Request $request, HrEngagementActionPlan $plan)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->access->currentStaff($user, $user);
        $plan = $this->access->actionPlan($user, $plan);

        $canManage = $user->canDo('hr.performance.manage');
        $isOwner = $plan->owner_user_id === $user->id;
        abort_unless($canManage || $isOwner, 404);

        $validated = $request->validate([
            'title' => [$canManage ? 'sometimes' : 'prohibited', 'string', 'max:255'],
            'description' => [$canManage ? 'nullable' : 'prohibited', 'string', 'max:5000'],
            'priority' => [$canManage ? 'sometimes' : 'prohibited', 'string', Rule::in(['low', 'medium', 'high'])],
            'status' => ['sometimes', 'string', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'progress_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'due_date' => [$canManage ? 'nullable' : 'prohibited', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->careService->updatePlan($plan, $user, $validated);

        return redirect()->back()->with('success', 'Action plan updated.');
    }

    /*
    |--------------------------------------------------------------------------
    | Flag triage (acknowledge / snooze / dismiss / undo)
    |--------------------------------------------------------------------------
    */
    public function acknowledgeFlag(Request $request, User $user)
    {
        return $this->storeFlagAction($request, $user, 'acknowledge');
    }

    public function snoozeFlag(Request $request, User $user)
    {
        return $this->storeFlagAction($request, $user, 'snooze');
    }

    public function dismissFlag(Request $request, User $user)
    {
        return $this->storeFlagAction($request, $user, 'dismiss');
    }

    private function storeFlagAction(Request $request, User $staff, string $action)
    {
        $actor = $this->manager($request);
        $staff = $this->access->currentStaff($actor, $staff);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'snooze_until' => [$action === 'snooze' ? 'required' : 'nullable', 'date', 'after:today'],
        ]);

        $this->careService->recordFlagAction(
            $actor,
            $staff->id,
            $action,
            $validated['reason'] ?? null,
            $validated['snooze_until'] ?? null,
        );

        return redirect()->back()->with('success', 'Flag '.$action.'d.');
    }

    public function undoFlag(Request $request, User $user)
    {
        $actor = $this->manager($request);
        $user = $this->access->currentStaff($actor, $user);

        $this->careService->undoLastFlagAction($actor, $user->id);

        return redirect()->back()->with('success', 'Action undone.');
    }

    /*
    |--------------------------------------------------------------------------
    | Wellbeing check-ins
    |--------------------------------------------------------------------------
    */
    public function storeCheckin(Request $request)
    {
        $actor = $this->manager($request);

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer'],
            'type' => ['required', 'string', Rule::in(['1on1', 'welfare', 'return_to_work'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'mood' => ['nullable', 'string', Rule::in(['good', 'mixed', 'low'])],
            'follow_up_date' => ['nullable', 'date'],
            'is_private' => ['boolean'],
        ]);
        $staff = $this->access->currentStaff($actor, (int) $validated['staff_user_id']);
        $validated['staff_user_id'] = $staff->id;

        $this->careService->createCheckin($actor, $validated);

        return redirect()->back()->with('success', 'Check-in logged.');
    }

    public function updateCheckin(Request $request, HrWellbeingCheckin $checkin)
    {
        $actor = $this->manager($request);
        $checkin = $this->access->checkin($actor, $checkin);

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
        $this->access->currentStaff($actor, $actor);
        $checkin = HrWellbeingCheckin::query()
            ->where('staff_user_id', $actor->id)
            ->where('is_private', false)
            ->findOrFail($checkin->getKey());

        $this->careService->acknowledgeCheckin($checkin, $actor->id);

        return redirect()->back()->with('success', 'Check-in acknowledged.');
    }

    /*
    |--------------------------------------------------------------------------
    | EAP referrals
    |--------------------------------------------------------------------------
    */
    public function storeEapReferral(Request $request)
    {
        $actor = $this->manager($request);

        $validated = $request->validate([
            'staff_user_id' => ['required', 'integer'],
            'reason_category' => ['required', 'string', Rule::in(['workload', 'personal', 'wellbeing', 'other'])],
            'provider' => ['nullable', 'string', 'max:255'],
            'consent_given' => ['accepted'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $staff = $this->access->currentStaff($actor, (int) $validated['staff_user_id']);
        $validated['staff_user_id'] = $staff->id;

        $this->careService->createEapReferral($actor, $validated);

        return redirect()->back()->with('success', 'EAP referral submitted confidentially.');
    }

    /*
    |--------------------------------------------------------------------------
    | Standalone action plans + lifecycle + notes
    |--------------------------------------------------------------------------
    */
    public function storeStandaloneActionPlan(Request $request)
    {
        $actor = $this->manager($request);

        $validated = $request->validate([
            'owner_user_id' => ['required', 'integer'],
            'staff_user_id' => ['nullable', 'integer'],
            'survey_id' => ['nullable', 'integer'],
            'source_type' => ['nullable', 'string', Rule::in(['survey', 'flag', 'manual'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::in(['low', 'medium', 'high'])],
            'due_date' => ['nullable', 'date'],
        ]);
        $validated['owner_user_id'] = $this->access
            ->currentStaff($actor, (int) $validated['owner_user_id'])
            ->id;
        if (! empty($validated['staff_user_id'])) {
            $validated['staff_user_id'] = $this->access
                ->currentStaff($actor, (int) $validated['staff_user_id'])
                ->id;
        }

        if (! empty($validated['survey_id'])) {
            $validated['survey_id'] = $this->access
                ->surveyForManager($actor, (int) $validated['survey_id'])
                ->id;
        }
        abort_unless(
            ($validated['source_type'] ?? 'manual') !== 'flag' || ! empty($validated['staff_user_id']),
            422,
            'A flag-linked plan requires a staff subject.',
        );

        $plan = $this->careService->createStandalonePlan($actor, $validated);
        $this->careService->notifyOwnerAssigned($plan, $actor);

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
        $this->access->currentStaff($actor, $actor);
        $plan = $this->access->actionPlan($actor, $plan);
        abort_unless($actor->canDo('hr.performance.manage') || $plan->owner_user_id === $actor->id, 404);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $this->careService->addPlanNote($plan, $actor, trim($validated['body']), 'note');

        return redirect()->back()->with('success', 'Note added.');
    }

    private function authorisePlanManager(Request $request, HrEngagementActionPlan $plan): User
    {
        $actor = $this->manager($request);
        $this->access->actionPlan($actor, $plan);

        return $actor;
    }

    /*
    |--------------------------------------------------------------------------
    | Survey operations (duplicate / nudge / archive / delete / export)
    |--------------------------------------------------------------------------
    */
    public function duplicateSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $actor = $this->manager($request);
        $survey = $this->access->surveyForManager($actor, $survey);

        $copy = $this->engagementService->duplicateSurvey($survey, $actor);

        return redirect()->route('hr.wellbeing.surveys.show', $copy->id)->with('success', 'Survey duplicated as a draft.');
    }

    public function nudgeSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $actor = $this->manager($request);
        $survey = $this->access->surveyForManager($actor, $survey);

        $count = $this->engagementService->nudgeNonResponders($survey, $actor);

        return redirect()->back()->with('success', $count > 0 ? ('Nudged '.$count.' non-'.($count === 1 ? 'responder' : 'responders').'.') : 'Everyone has already responded.');
    }

    public function archiveSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $actor = $this->manager($request);
        $survey = $this->access->surveyForManager($actor, $survey);

        $this->engagementService->archiveSurvey($survey, $actor);

        return redirect()->back()->with('success', 'Survey archived.');
    }

    public function destroySurvey(Request $request, HrEngagementSurvey $survey)
    {
        $actor = $this->manager($request);
        $survey = $this->access->surveyForManager($actor, $survey);

        $this->engagementService->archiveDraftSurvey($survey, $actor);

        return redirect()->route('hr.wellbeing.index')->with('success', 'Draft survey archived.');
    }

    public function exportSurvey(Request $request, HrEngagementSurvey $survey): StreamedResponse
    {
        $actor = $this->manager($request);
        $survey = $this->access->surveyForManager($actor, $survey);

        $survey->load(['questions', 'responses']);
        $summary = $this->engagementService->summary($survey);
        $isAnonymous = (bool) $survey->is_anonymous;

        $filename = 'survey-'.$survey->id.'-results.csv';

        return response()->streamDownload(function () use ($survey, $summary, $isAnonymous) {
            $out = fopen('php://output', 'w');

            $this->putCsv($out, ['Survey', $survey->title]);
            $this->putCsv($out, ['Type', $survey->survey_type]);
            $this->putCsv($out, ['Anonymous', $isAnonymous ? 'Yes' : 'No']);
            $this->putCsv($out, ['Responses', $summary['response_count']]);
            if ($summary['enps'] !== null) {
                $this->putCsv($out, ['eNPS', $summary['enps']]);
            }
            $this->putCsv($out, []);

            // Per-question averages (anonymity-safe aggregate).
            $this->putCsv($out, ['Question', 'Type', 'Responses', 'Average']);
            foreach ($summary['question_stats'] as $stat) {
                $this->putCsv($out, [$stat['question_text'], $stat['question_type'], $stat['responses'], $stat['average'] ?? '—']);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->currentStaff($user, $user);

        return $user;
    }
}
