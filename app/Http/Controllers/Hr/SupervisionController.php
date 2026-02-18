<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SupervisionController extends Controller
{
    /**
     * Supervision & performance overview.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $tenantId = $user->tenant_id ?? null;
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

        // Transform to match frontend SupervisionNote type
        $notes->getCollection()->transform(fn ($note) => [
            'id' => $note->id,
            'staff_user' => $note->employee ? ['id' => $note->employee->id, 'name' => $note->employee->name] : ['id' => 0, 'name' => 'Unknown'],
            'supervisor' => $note->supervisor ? ['id' => $note->supervisor->id, 'name' => $note->supervisor->name] : ['id' => 0, 'name' => 'Unknown'],
            'date' => $note->session_date?->toDateString(),
            'summary' => $note->topics_discussed ? str($note->topics_discussed)->limit(120)->toString() : '',
            'status' => $note->employee_acknowledged ? 'completed' : 'pending',
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

        $staffQuery = User::query()->staff();
        if ($tenantId !== null && Schema::hasColumn('users', 'tenant_id')) {
            $staffQuery->where('tenant_id', $tenantId);
        }

        $staff = $staffQuery
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('hr/performance/index', [
            'supervisionNotes' => $notes,
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
            'filters' => [
                'q' => $search,
                'staff_id' => $staffId,
            ],
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /**
     * Show form to create a new supervision note.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $staff = User::staff()
            ->when(
                $user->tenant_id !== null && Schema::hasColumn('users', 'tenant_id'),
                fn ($query) => $query->where('tenant_id', $user->tenant_id)
            )
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/performance/create-supervision', [
            'staff' => $staff,
            'sessionTypes' => [
                ['value' => 'one_to_one', 'label' => 'One-to-One'],
                ['value' => 'supervision', 'label' => 'Supervision'],
                ['value' => 'review', 'label' => 'Review'],
                ['value' => 'check_in', 'label' => 'Check-in'],
                ['value' => 'probation', 'label' => 'Probation Review'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    /**
     * Show a single supervision note.
     */
    public function show(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

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

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'session_date' => ['required', 'date'],
            'session_type' => ['required', 'string', 'max:50'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'topics_discussed' => ['nullable', 'string', 'max:5000'],
            'actions_agreed' => ['nullable', 'array'],
            'actions_agreed.*' => ['string', 'max:500'],
            'next_session_date' => ['nullable', 'date', 'after:session_date'],
            'is_visible_to_employee' => ['boolean'],
        ]);

        HrSupervisionNote::create([
            'tenant_id' => $user->tenant_id,
            'supervisor_user_id' => $user->id,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Supervision note recorded.');
    }

    /**
     * Show form to edit a supervision note.
     */
    public function edit(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $note->load(['employee:id,name', 'supervisor:id,name']);

        $staff = User::staff()
            ->when(
                $user->tenant_id !== null && Schema::hasColumn('users', 'tenant_id'),
                fn ($query) => $query->where('tenant_id', $user->tenant_id)
            )
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/performance/edit-supervision', [
            'note' => $note,
            'staff' => $staff,
            'sessionTypes' => [
                ['value' => 'one_to_one', 'label' => 'One-to-One'],
                ['value' => 'supervision', 'label' => 'Supervision'],
                ['value' => 'review', 'label' => 'Review'],
                ['value' => 'check_in', 'label' => 'Check-in'],
                ['value' => 'probation', 'label' => 'Probation Review'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    /**
     * Update an existing supervision note.
     */
    public function update(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'session_date' => ['sometimes', 'date'],
            'session_type' => ['sometimes', 'string', 'max:50'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'topics_discussed' => ['nullable', 'string', 'max:5000'],
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
}
