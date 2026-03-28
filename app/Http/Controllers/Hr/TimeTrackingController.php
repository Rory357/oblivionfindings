<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreTimesheetRequest;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimesheet;
use App\Domain\Hr\Services\TimeTrackingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TimeTrackingController extends Controller
{
    public function __construct(
        private readonly TimeTrackingService $timeTrackingService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — time tracking dashboard                                    */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.time.viewAny'), 403);

        $tenantId = $user->tenant_id;
        $canManage = $user->canDo('hr.time.manage');
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $entries = HrTimeEntry::forTenant($tenantId)
            ->when(! $canManage, fn ($q) => $q->forUser($user->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
            ))
            ->with('user:id,name,email', 'approver:id,name')
            ->orderByDesc('entry_date')
            ->orderByDesc('clock_in')
            ->paginate(20)
            ->withQueryString();

        $entries->through(fn ($entry) => [
            'id' => $entry->id,
            'user_name' => $entry->user?->name ?? 'Unknown',
            'user_id' => $entry->user_id,
            'entry_date' => $entry->entry_date->toDateString(),
            'clock_in' => $entry->clock_in->format('H:i'),
            'clock_out' => $entry->clock_out?->format('H:i'),
            'break_minutes' => $entry->break_minutes,
            'total_hours' => $entry->total_hours,
            'entry_type' => $entry->entry_type,
            'status' => $entry->status,
            'notes' => $entry->notes,
            'project_code' => $entry->project_code,
            'approved_by' => $entry->approver?->name,
        ]);

        // Active clock-in for current user
        $activeClock = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->first();

        $weeklySummary = $this->timeTrackingService->getWeeklySummary($tenantId, $user->id);

        return Inertia::render('hr/time/index', [
            'entries' => $entries,
            'activeClock' => $activeClock ? [
                'id' => $activeClock->id,
                'clock_in' => $activeClock->clock_in->format('Y-m-d H:i'),
                'notes' => $activeClock->notes,
            ] : null,
            'weeklySummary' => $weeklySummary,
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Clock In                                                           */
    /* ------------------------------------------------------------------ */

    public function clockIn(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'project_code' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $this->timeTrackingService->clockIn(
                $user,
                $validated['notes'] ?? null,
                $validated['project_code'] ?? null,
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Clocked in successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Clock Out                                                          */
    /* ------------------------------------------------------------------ */

    public function clockOut(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->timeTrackingService->clockOut(
                $user,
                (int) ($validated['break_minutes'] ?? 0),
                $validated['notes'] ?? null,
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Clocked out successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Store — manual time entry                                          */
    /* ------------------------------------------------------------------ */

    public function store(StoreTimesheetRequest $request)
    {
        $user = $request->user();

        $validated = $request->validated();

        $this->timeTrackingService->createManualEntry($user, $validated);

        return redirect()->back()->with('success', 'Time entry created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Timesheets                                                         */
    /* ------------------------------------------------------------------ */

    public function timesheets(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.time.viewAny'), 403);

        $tenantId = $user->tenant_id;
        $canManage = $user->canDo('hr.time.manage');
        $status = $request->query('status');

        $timesheets = HrTimesheet::forTenant($tenantId)
            ->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('user:id,name,email', 'approver:id,name')
            ->orderByDesc('period_start')
            ->paginate(20)
            ->withQueryString();

        $timesheets->through(fn ($ts) => [
            'id' => $ts->id,
            'user_name' => $ts->user?->name ?? 'Unknown',
            'user_id' => $ts->user_id,
            'period_start' => $ts->period_start->toDateString(),
            'period_end' => $ts->period_end->toDateString(),
            'status' => $ts->status,
            'total_hours' => $ts->total_hours,
            'submitted_at' => $ts->submitted_at?->toDateTimeString(),
            'approved_by' => $ts->approver?->name,
            'approved_at' => $ts->approved_at?->toDateTimeString(),
            'rejection_reason' => $ts->rejection_reason,
        ]);

        return Inertia::render('hr/time/timesheets', [
            'timesheets' => $timesheets,
            'filters' => [
                'status' => $status,
            ],
            'can' => [
                'manage' => $canManage,
                'approve' => $canManage,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Submit Timesheet                                                   */
    /* ------------------------------------------------------------------ */

    public function submitTimesheet(Request $request, HrTimesheet $timesheet)
    {
        $user = $request->user();
        abort_unless($user && $user->id === $timesheet->user_id, 403);

        try {
            $this->timeTrackingService->submitTimesheet($timesheet, $user);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Timesheet submitted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve Timesheet                                                  */
    /* ------------------------------------------------------------------ */

    public function approveTimesheet(Request $request, HrTimesheet $timesheet)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.time.manage'), 403);

        try {
            $this->timeTrackingService->approveTimesheet($timesheet, $user);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Timesheet approved.');
    }

    /* ------------------------------------------------------------------ */
    /*  Reject Timesheet                                                   */
    /* ------------------------------------------------------------------ */

    public function rejectTimesheet(Request $request, HrTimesheet $timesheet)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.time.manage'), 403);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->timeTrackingService->rejectTimesheet(
                $timesheet,
                $user,
                $validated['rejection_reason'],
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Timesheet rejected.');
    }
}
