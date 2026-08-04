<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Notifications\SupervisionNoteAddedNotification;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SupervisionController extends Controller
{
    public function __construct(private readonly HrPerformanceAccessService $access) {}

    /** The page-based form was replaced by the performance-hub wizard. */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return redirect()->route('hr.performance.index');
    }

    public function show(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $note = $this->access->supervisionNote($user, $note)
            ->load(['employee:id,name', 'supervisor:id,name']);

        return Inertia::render('hr/performance/show-supervision', [
            'note' => $note,
            'can' => ['manage' => true],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'employee_user_id' => ['required', 'integer'],
            'session_date' => ['required', 'date'],
            'session_type' => ['required', 'string', Rule::in(array_column(HrSupervisionNote::sessionTypeOptions(), 'value'))],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'topics_discussed' => ['required', 'string', 'max:5000'],
            'actions_agreed' => ['nullable', 'array'],
            'actions_agreed.*' => ['string', 'max:500'],
            'next_session_date' => ['nullable', 'date', 'after:session_date'],
            'is_visible_to_employee' => ['boolean'],
        ]);
        $employee = $this->access->currentStaff($user, (int) $data['employee_user_id']);

        $note = DB::transaction(fn () => HrSupervisionNote::query()->create([
            ...$data,
            'employee_user_id' => $employee->id,
            'supervisor_user_id' => $user->id,
            'created_by' => $user->id,
        ]), attempts: 1);

        if ($note->is_visible_to_employee) {
            $employee->notify(new SupervisionNoteAddedNotification($note));
        }

        return redirect()->back()->with('success', 'Supervision note recorded.');
    }

    /** The page-based edit form was replaced by the performance-hub wizard. */
    public function edit(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->supervisionNote($user, $note);

        return redirect()->route('hr.performance.index');
    }

    public function update(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->supervisionNote($user, $note);

        $data = $request->validate([
            'session_date' => ['sometimes', 'date'],
            'session_type' => ['sometimes', 'string', Rule::in(array_column(HrSupervisionNote::sessionTypeOptions(), 'value'))],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'topics_discussed' => ['sometimes', 'required', 'string', 'max:5000'],
            'actions_agreed' => ['nullable', 'array'],
            'actions_agreed.*' => ['string', 'max:500'],
            'next_session_date' => ['nullable', 'date'],
            'is_visible_to_employee' => ['boolean'],
        ]);

        DB::transaction(function () use ($note, $user, $data): void {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrSupervisionNote::query(), $user)
                ->lockForUpdate()
                ->findOrFail($note->getKey());
            $locked->update($data);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Supervision note updated.');
    }

    /** Employees alone acknowledge notes explicitly shared with them. */
    public function acknowledge(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->access->currentStaff($user, $user);
        $data = $request->validate([
            'employee_comments' => ['nullable', 'string', 'max:5000'],
        ]);

        $changed = DB::transaction(function () use ($note, $user, $data): bool {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrSupervisionNote::query(), $user)
                ->whereKey($note->getKey())
                ->where('employee_user_id', $user->id)
                ->where('is_visible_to_employee', true)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->employee_acknowledged) {
                return false;
            }

            $locked->update([
                'employee_comments' => $data['employee_comments'] ?? null,
                'employee_acknowledged' => true,
                'employee_acknowledged_at' => now(),
                'status' => 'acknowledged',
            ]);

            return true;
        }, attempts: 1);

        if ($changed) {
            app(HrNotificationService::class)->notifySupervisionAcknowledged($note->fresh());
        }

        return redirect()->back()->with('success', 'Supervision note acknowledged.');
    }
}
