<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPipMilestone;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PipController extends Controller
{
    /**
     * List PIPs with optional status filter.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $pips = HrPerformanceImprovementPlan::with(['employee:id,name', 'manager:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Transform to provide employee from profile
        $pips->through(fn ($pip) => [
            'id' => $pip->id,
            'title' => $pip->title,
            'status' => $pip->status,
            'start_date' => $pip->start_date,
            'end_date' => $pip->end_date,
            'outcome' => $pip->outcome_notes,
            'employee' => $pip->employee
                ? ['id' => $pip->employee->id, 'name' => $pip->employee->name]
                : null,
            'manager' => $pip->manager
                ? ['id' => $pip->manager->id, 'name' => $pip->manager->name]
                : null,
        ]);

        // Stats
        $statusCounts = HrPerformanceImprovementPlan::selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $stats = [
            'active' => (int) (($statusCounts['active'] ?? 0) + ($statusCounts['in_progress'] ?? 0)),
            'completed' => (int) ($statusCounts['completed'] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            'total' => (int) $statusCounts->sum(),
        ];

        return Inertia::render('hr/performance/pips/index', [
            'pips' => $pips,
            'stats' => $stats,
            'filters' => [
                'status' => $request->query('status'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /**
     * Show form to create a new PIP.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $staff = User::orderBy('name')->get(['id', 'name', 'email']);

        return Inertia::render('hr/performance/pips/create', [
            'staff' => $staff,
        ]);
    }

    /**
     * Store a new PIP with milestones.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:5000'],
            'expectations' => ['required', 'string', 'max:5000'],
            'support_offered' => ['nullable', 'string', 'max:5000'],
            'consequences' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'review_date' => ['nullable', 'date'],
            'milestones' => ['nullable', 'array'],
            'milestones.*.title' => ['required', 'string', 'max:255'],
            'milestones.*.description' => ['nullable', 'string', 'max:2000'],
            'milestones.*.due_date' => ['required', 'date'],
        ]);

        DB::transaction(function () use ($user, $data) {
            $pip = HrPerformanceImprovementPlan::create([
                'tenant_id' => $user->tenant_id,
                'employee_user_id' => $data['employee_user_id'],
                'manager_user_id' => $user->id,
                'title' => $data['title'],
                'reason' => $data['reason'],
                'expectations' => $data['expectations'],
                'support_offered' => $data['support_offered'] ?? null,
                'consequences' => $data['consequences'] ?? null,
                'status' => 'active',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'review_date' => $data['review_date'] ?? null,
                'created_by' => $user->id,
            ]);

            if (! empty($data['milestones'])) {
                foreach ($data['milestones'] as $milestone) {
                    HrPipMilestone::create([
                        'pip_id' => $pip->id,
                        'title' => $milestone['title'],
                        'description' => $milestone['description'] ?? null,
                        'due_date' => $milestone['due_date'],
                        'status' => 'pending',
                    ]);
                }
            }
        });

        return redirect()->route('hr.performance.pips.index')->with('success', 'Performance Improvement Plan created.');
    }

    /**
     * Show a single PIP with milestones.
     */
    public function show(Request $request, HrPerformanceImprovementPlan $pip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $pip->load(['employee:id,name', 'manager:id,name', 'creator:id,name', 'milestones.reviewer:id,name']);

        return Inertia::render('hr/performance/pips/show', [
            'pip' => $pip,
            'can' => [
                'manage' => $user->canDo('hr.performance.manage'),
            ],
        ]);
    }

    /**
     * Update a milestone status (met / not_met).
     */
    public function updateMilestone(Request $request, HrPipMilestone $milestone)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:met,not_met,pending'],
            'reviewer_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $milestone->update([
            'status' => $data['status'],
            'reviewer_notes' => $data['reviewer_notes'] ?? null,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Milestone updated.');
    }

    /**
     * Complete a PIP with outcome.
     */
    public function complete(Request $request, HrPerformanceImprovementPlan $pip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'in:successful,unsuccessful,extended'],
            'outcome_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $pip->update([
            'status' => 'completed',
            'outcome' => $data['outcome'],
            'outcome_notes' => $data['outcome_notes'] ?? null,
            'completed_at' => now(),
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'PIP completed.');
    }
}
