<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SupervisionController extends Controller
{
    use ResolvesHrTenant;

    /**
     * Supervision & performance overview.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $search = trim((string) $request->query('q', ''));
        $staffId = $request->query('staff_id');

        // Paginated supervision notes
        $notes = HrSupervisionNote::query()
            ->forTenant($tenantId)
            ->with(['employee:id,name', 'supervisor:id,name'])
            ->when($staffId, fn ($q) => $q->where('employee_user_id', $staffId))
            ->when($search !== '', fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('topics_discussed', 'like', "%{$search}%")
                   ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$search}%"));
            }))
            ->orderByDesc('session_date')
            ->paginate(20)
            ->withQueryString();

        // Transform to match frontend SupervisionNote type. Includes the full
        // editable fields so the SupervisionDialog can edit a note inline.
        $notes->getCollection()->transform(fn ($note) => [
            'id' => $note->id,
            'staff_user' => $note->employee ? ['id' => $note->employee->id, 'name' => $note->employee->name] : ['id' => 0, 'name' => 'Unknown'],
            'supervisor' => $note->supervisor ? ['id' => $note->supervisor->id, 'name' => $note->supervisor->name] : ['id' => 0, 'name' => 'Unknown'],
            'date' => $note->session_date?->toDateString(),
            'summary' => $note->topics_discussed ? str($note->topics_discussed)->limit(120)->toString() : '',
            'status' => $note->employee_acknowledged ? 'completed' : 'pending',
            'employee_user_id' => $note->employee_user_id,
            'session_type' => $note->session_type,
            'session_date' => $note->session_date?->toDateString(),
            'duration_minutes' => $note->duration_minutes,
            'topics_discussed' => $note->topics_discussed,
            'actions_agreed' => $note->actions_agreed ?? [],
            'next_session_date' => $note->next_session_date?->toDateString(),
            'is_visible_to_employee' => (bool) $note->is_visible_to_employee,
        ]);

        // Upcoming / overdue reviews
        $upcomingReviews = HrPerformanceReview::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->when($staffId, fn ($query) => $query->where('employee_user_id', (int) $staffId))
            ->with(['employee:id,name', 'reviewer:id,name'])
            ->orderBy('next_review_date')
            ->limit(10)
            ->get()
            ->map(fn ($review) => [
                'id' => $review->id,
                'staff_user' => $review->employee ? ['id' => $review->employee->id, 'name' => $review->employee->name] : ['id' => 0, 'name' => 'Unknown'],
                'reviewer' => $review->reviewer ? ['id' => $review->reviewer->id, 'name' => $review->reviewer->name] : ['id' => 0, 'name' => 'Unknown'],
                'scheduled_at' => $review->next_review_date?->toDateString(),
                'status' => ($review->next_review_date && $review->next_review_date->isPast()) ? 'overdue' : $review->status,
            ]);

        // Recent notes this month for summary card
        $recentNotes = HrSupervisionNote::query()
            ->forTenant($tenantId)
            ->with(['employee:id,name', 'supervisor:id,name'])
            ->where('session_date', '>=', now()->startOfMonth())
            ->when($staffId, fn ($query) => $query->where('employee_user_id', (int) $staffId))
            ->orderByDesc('session_date')
            ->limit(50)
            ->get()
            ->map(fn ($note) => [
                'id' => $note->id,
                'staff_user' => $note->employee ? ['id' => $note->employee->id, 'name' => $note->employee->name] : ['id' => 0, 'name' => 'Unknown'],
                'supervisor' => $note->supervisor ? ['id' => $note->supervisor->id, 'name' => $note->supervisor->name] : ['id' => 0, 'name' => 'Unknown'],
                'date' => $note->session_date?->toDateString(),
                'summary' => $note->topics_discussed ? str($note->topics_discussed)->limit(120)->toString() : '',
            ]);

        $oneToOneDueRows = HrSupervisionNote::query()
            ->forTenant($tenantId)
            ->whereNotNull('next_session_date')
            ->when($staffId, fn ($query) => $query->where('employee_user_id', (int) $staffId))
            ->with(['employee:id,name', 'supervisor:id,name'])
            ->orderBy('next_session_date')
            ->limit(15)
            ->get();

        $oneToOneDueSoon = $oneToOneDueRows
            ->filter(fn (HrSupervisionNote $note) => $note->next_session_date?->between(now()->startOfDay(), now()->addDays(7)->endOfDay()))
            ->values();
        $oneToOneOverdue = $oneToOneDueRows
            ->filter(fn (HrSupervisionNote $note) => $note->next_session_date?->isBefore(now()->startOfDay()))
            ->values();

        $competencyGaps = HrDevelopmentGoal::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('target_level')
            ->whereNotNull('current_level')
            ->whereRaw('target_level > current_level')
            ->when($staffId, fn ($query) => $query->where('employee_user_id', (int) $staffId))
            ->with('employee:id,name')
            ->orderByRaw('(target_level - current_level) DESC')
            ->limit(10)
            ->get()
            ->map(fn (HrDevelopmentGoal $goal) => [
                'id' => $goal->id,
                'title' => $goal->title,
                'employee_name' => $goal->employee?->name ?? 'Unknown',
                'competency_area' => $goal->competency_area,
                'current_level' => $goal->current_level,
                'target_level' => $goal->target_level,
                'gap' => (int) $goal->target_level - (int) $goal->current_level,
                'status' => $goal->status,
                'due_date' => optional($goal->due_date)->toDateString(),
            ])
            ->values();

        $engagementOpen = HrEngagementActionPlan::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['open', 'in_progress'])
            ->when($staffId, fn ($query) => $query->where('owner_user_id', (int) $staffId))
            ->get();

        $engagementActionPlanSla = [
            'open_total' => $engagementOpen->count(),
            'overdue' => $engagementOpen->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date && $plan->due_date->isBefore(now()->startOfDay()))->count(),
            'due_next_7_days' => $engagementOpen->filter(fn (HrEngagementActionPlan $plan) => $plan->due_date
                && $plan->due_date->isBetween(now()->startOfDay(), now()->addDays(7)->endOfDay()))->count(),
        ];

        // ── Chart aggregations ──────────────────────────────────────────

        $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();

        // Review completion trend (last 6 months)
        $reviewTrendRaw = HrPerformanceReview::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->when($staffId, fn ($q) => $q->where('employee_user_id', (int) $staffId))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total, SUM(CASE WHEN status = 'completed' OR status = 'signed_off' THEN 1 ELSE 0 END) as completed")
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->orderBy('month')
            ->get();

        $reviewCompletionTrend = collect();
        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i)->format('Y-m');
            $label = Carbon::now()->subMonths($i)->format('M Y');
            $row = $reviewTrendRaw->firstWhere('month', $m);
            $reviewCompletionTrend->push([
                'month' => $label,
                'completed' => (int) ($row->completed ?? 0),
                'total' => (int) ($row->total ?? 0),
            ]);
        }

        // Notes per month trend (last 6 months)
        $notesTrendRaw = HrSupervisionNote::query()
            ->forTenant($tenantId)
            ->where('session_date', '>=', $sixMonthsAgo)
            ->when($staffId, fn ($q) => $q->where('employee_user_id', (int) $staffId))
            ->selectRaw("DATE_FORMAT(session_date, '%Y-%m') as month, COUNT(*) as cnt")
            ->groupByRaw("DATE_FORMAT(session_date, '%Y-%m')")
            ->orderBy('month')
            ->get();

        $notesPerMonth = collect();
        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i)->format('Y-m');
            $label = Carbon::now()->subMonths($i)->format('M Y');
            $row = $notesTrendRaw->firstWhere('month', $m);
            $notesPerMonth->push([
                'month' => $label,
                'count' => (int) ($row->cnt ?? 0),
            ]);
        }

        // Rating distribution for completed reviews
        $ratingDistribution = HrPerformanceReview::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'signed_off'])
            ->whereNotNull('overall_rating')
            ->when($staffId, fn ($q) => $q->where('employee_user_id', (int) $staffId))
            ->selectRaw('overall_rating as rating, COUNT(*) as count')
            ->groupBy('overall_rating')
            ->orderBy('overall_rating')
            ->get()
            ->map(fn ($r) => ['rating' => (int) $r->rating, 'count' => (int) $r->count]);

        // Ensure all 5 ratings present
        $ratingMap = $ratingDistribution->keyBy('rating');
        $ratingDistribution = collect(range(1, 5))->map(fn ($r) => [
            'rating' => $r,
            'count' => (int) ($ratingMap[$r]['count'] ?? 0),
        ]);

        // PIP summary
        $pipRows = HrPerformanceImprovementPlan::query()
            ->where('tenant_id', $tenantId)
            ->when($staffId, fn ($q) => $q->where('employee_user_id', (int) $staffId))
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $pipSummary = [
            'active' => (int) (($pipRows['active'] ?? 0) + ($pipRows['in_progress'] ?? 0)),
            'completed' => (int) ($pipRows['completed'] ?? 0),
            'cancelled' => (int) ($pipRows['cancelled'] ?? 0),
            'total' => (int) $pipRows->sum(),
        ];

        // Feedback summary
        $feedbackRows = HrFeedbackRequest::query()
            ->where('tenant_id', $tenantId)
            ->when($staffId, fn ($q) => $q->where('subject_user_id', (int) $staffId))
            ->selectRaw("status, COUNT(*) as cnt, SUM(CASE WHEN status = 'pending' AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_cnt")
            ->groupBy('status')
            ->get();

        $feedbackSummary = [
            'pending' => (int) $feedbackRows->where('status', 'pending')->sum('cnt'),
            'completed' => (int) $feedbackRows->where('status', 'completed')->sum('cnt'),
            'overdue' => (int) $feedbackRows->sum('overdue_cnt'),
        ];

        // Previous month note count (for trend indicator)
        $previousMonthNoteCount = HrSupervisionNote::query()
            ->forTenant($tenantId)
            ->whereBetween('session_date', [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->subMonth()->endOfMonth(),
            ])
            ->when($staffId, fn ($q) => $q->where('employee_user_id', (int) $staffId))
            ->count();

        // ── End chart aggregations ──────────────────────────────────────

        $staffQuery = User::query()->staff();
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $staffQuery->when($staffIds !== [], fn ($query) => $query->whereIn('id', $staffIds));

        $staff = $staffQuery
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/performance/index', [
            'supervisionNotes' => $notes,
            'sessionTypes' => $this->sessionTypeOptions(),
            'upcomingReviews' => $upcomingReviews,
            'recentNotes' => $recentNotes,
            'staff' => $staff,
            'oneToOneSla' => [
                'due_soon_count' => $oneToOneDueSoon->count(),
                'overdue_count' => $oneToOneOverdue->count(),
                'due_rows' => $oneToOneDueRows->map(fn (HrSupervisionNote $note) => [
                    'id' => $note->id,
                    'employee_name' => $note->employee?->name ?? 'Unknown',
                    'supervisor_name' => $note->supervisor?->name ?? 'Unknown',
                    'next_session_date' => optional($note->next_session_date)->toDateString(),
                    'is_overdue' => $note->next_session_date?->isBefore(now()->startOfDay()) ?? false,
                ])->values(),
            ],
            'competencyGaps' => $competencyGaps,
            'engagementActionPlanSla' => $engagementActionPlanSla,
            'reviewCompletionTrend' => $reviewCompletionTrend->values(),
            'notesPerMonth' => $notesPerMonth->values(),
            'ratingDistribution' => $ratingDistribution->values(),
            'pipSummary' => $pipSummary,
            'feedbackSummary' => $feedbackSummary,
            'previousMonthNoteCount' => $previousMonthNoteCount,
            'filters' => [
                'q' => $search,
                'staff_id' => $staffId,
            ],
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /** Supervision session-type options for the dialog. */
    private function sessionTypeOptions(): array
    {
        return [
            ['value' => 'one_to_one', 'label' => 'One-to-One'],
            ['value' => 'supervision', 'label' => 'Supervision'],
            ['value' => 'review', 'label' => 'Review'],
            ['value' => 'check_in', 'label' => 'Check-in'],
            ['value' => 'probation', 'label' => 'Probation Review'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }

    /**
     * The page-based create form was replaced by the SupervisionDialog on the
     * performance hub. Preserve the route with a redirect.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return redirect()->route('hr.performance.index');
    }

    /**
     * Show a single supervision note.
     */
    public function show(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $note->tenant_id);

        $note->load(['employee:id,name', 'supervisor:id,name']);

        return Inertia::render('hr/performance/show-supervision', [
            'note' => $note,
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /**
     * Store a new supervision note.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'session_date' => ['required', 'date'],
            'session_type' => ['required', 'string', 'max:50'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            // topics_discussed is NOT NULL at the DB level — require it.
            'topics_discussed' => ['required', 'string', 'max:5000'],
            'actions_agreed' => ['nullable', 'array'],
            'actions_agreed.*' => ['string', 'max:500'],
            'next_session_date' => ['nullable', 'date', 'after:session_date'],
            'is_visible_to_employee' => ['boolean'],
        ]);

        $employeeTenantId = HrEmployeeProfile::query()
            ->where('user_id', (int) $data['employee_user_id'])
            ->value('tenant_id');
        if (is_numeric($employeeTenantId) && (int) $employeeTenantId !== $tenantId) {
            abort(404);
        }

        HrSupervisionNote::create([
            'tenant_id' => $tenantId,
            'supervisor_user_id' => $user->id,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Supervision note recorded.');
    }

    /**
     * The page-based edit form was replaced by the SupervisionDialog (edit mode)
     * on the performance hub. Preserve the route with a redirect.
     */
    public function edit(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return redirect()->route('hr.performance.index');
    }

    /**
     * Update an existing supervision note.
     */
    public function update(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $note->tenant_id);

        $data = $request->validate([
            'session_date' => ['sometimes', 'date'],
            'session_type' => ['sometimes', 'string', 'max:50'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            // NOT NULL at the DB level: if present it must be non-empty.
            'topics_discussed' => ['sometimes', 'required', 'string', 'max:5000'],
            'actions_agreed' => ['nullable', 'array'],
            'actions_agreed.*' => ['string', 'max:500'],
            'employee_comments' => ['nullable', 'string', 'max:5000'],
            'employee_acknowledged' => ['nullable', 'boolean'],
            'next_session_date' => ['nullable', 'date'],
            'is_visible_to_employee' => ['boolean'],
        ]);

        if (isset($data['employee_acknowledged']) && $data['employee_acknowledged'] && ! $note->employee_acknowledged) {
            $data['employee_acknowledged_at'] = now();
        }

        $note->update($data);

        return redirect()->back()->with('success', 'Supervision note updated.');
    }

    /**
     * Employee acknowledges a supervision note made visible to them.
     */
    public function acknowledge(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless(
            $note->employee_user_id === $user->id || $user->canDo('hr.performance.manage'),
            403,
        );

        if (! $note->employee_acknowledged) {
            $note->update([
                'employee_acknowledged' => true,
                'employee_acknowledged_at' => now(),
                'status' => 'acknowledged',
            ]);
        }

        return redirect()->back()->with('success', 'Supervision note acknowledged.');
    }
}
