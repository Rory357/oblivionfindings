<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPipMilestone;
use App\Domain\Hr\Notifications\PipCreatedNotification;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class PipController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(private readonly HrPerformanceAccessService $access) {}

    /**
     * List the plans whose subjects retain provenance at a visible Site.
     */
    public function index(Request $request)
    {
        $user = $this->manager($request);

        $pips = $this->access
            ->applyHistoricalSubjectScope(
                HrPerformanceImprovementPlan::query()
                    ->with(['employee:id,name', 'manager:id,name']),
                $user,
            )
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $pips->through(fn (HrPerformanceImprovementPlan $pip) => [
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

        $statusCounts = $this->access
            ->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $user)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return Inertia::render('hr/performance/pips/index', [
            'pips' => $pips,
            'stats' => [
                'active' => (int) (($statusCounts['active'] ?? 0) + ($statusCounts['in_progress'] ?? 0)),
                'completed' => (int) ($statusCounts['completed'] ?? 0),
                'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
                'total' => (int) $statusCounts->sum(),
            ],
            'filters' => ['status' => $request->query('status')],
            'can' => ['manage' => true],
        ]);
    }

    /**
     * Show only current, approved staff at Sites visible to the manager.
     */
    public function create(Request $request)
    {
        $user = $this->manager($request);
        $staff = $this->access
            ->applyCurrentSubjectScope(User::query(), $user, 'id')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/performance/pips/create', ['staff' => $staff]);
    }

    /**
     * Store a new plan and its initial milestones atomically.
     */
    public function store(Request $request)
    {
        $user = $this->manager($request);
        $data = $request->validate([
            'employee_user_id' => ['required', 'integer'],
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
            'source_review_id' => ['nullable', 'integer'],
            'stay' => ['nullable', 'boolean'],
        ]);

        $employee = $this->access->currentStaff($user, (int) $data['employee_user_id']);
        $reason = $data['reason'];

        if (! empty($data['source_review_id'])) {
            $sourceReview = $this->access->performanceReview($user, (int) $data['source_review_id']);
            abort_unless(
                $sourceReview->status === 'signed_off'
                    && (int) $sourceReview->employee_user_id === (int) $employee->id,
                422,
                'The source review must be a signed-off review for this employee.',
            );
            $reason .= "\n\nCreated from performance review #{$sourceReview->id}.";
        }

        foreach ($data['milestones'] ?? [] as $index => $milestone) {
            $this->assertMilestoneWithinPlan(
                $milestone['due_date'],
                $data['start_date'],
                $data['end_date'],
                "milestones.{$index}.due_date",
            );
        }

        $pip = DB::transaction(function () use ($user, $employee, $data, $reason) {
            $pip = HrPerformanceImprovementPlan::query()->create([
                'employee_user_id' => $employee->id,
                'manager_user_id' => $user->id,
                'title' => $data['title'],
                'reason' => $reason,
                'expectations' => $data['expectations'],
                'support_offered' => $data['support_offered'] ?? null,
                'consequences' => $data['consequences'] ?? null,
                'status' => 'active',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'review_date' => $data['review_date'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['milestones'] ?? [] as $index => $milestone) {
                $pip->milestones()->create([
                    'title' => $milestone['title'],
                    'description' => $milestone['description'] ?? null,
                    'due_date' => $milestone['due_date'],
                    'status' => 'pending',
                    'sort_order' => $index + 1,
                ]);
            }

            return $pip;
        }, attempts: 1);

        try {
            $managerName = $user->name;
            $employee->notify(new PipCreatedNotification($pip, $managerName, forSubject: true));
            if ((int) $employee->id !== (int) $user->id) {
                $user->notify(new PipCreatedNotification($pip, $managerName, forSubject: false));
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        if ((bool) ($data['stay'] ?? false)) {
            return redirect()->back()->with('success', 'Performance Improvement Plan created.');
        }

        return redirect()->route('hr.pips.index')->with('success', 'Performance Improvement Plan created.');
    }

    /**
     * Show a plan to its current subject or a Site-authorised manager.
     */
    public function show(Request $request, HrPerformanceImprovementPlan $pip)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $isSubject = (int) $pip->employee_user_id === (int) $user->id;
        if ($isSubject) {
            $this->access->currentStaff($user, $user);
            $pip = HrPerformanceImprovementPlan::query()
                ->where('employee_user_id', $user->id)
                ->findOrFail($pip->getKey());
        } else {
            abort_unless($user->canDo('hr.performance.manage'), 404);
            $this->access->currentStaff($user, $user);
            $pip = $this->access->performanceImprovementPlan($user, $pip);
        }

        $pip->load(['employee:id,name', 'manager:id,name', 'creator:id,name', 'milestones.reviewer:id,name']);

        return Inertia::render('hr/performance/pips/show', [
            'pip' => $pip,
            'viewer_is_subject' => $isSubject,
            'can' => ['manage' => $user->canDo('hr.performance.manage')],
        ]);
    }

    public function update(Request $request, HrPerformanceImprovementPlan $pip)
    {
        $user = $this->manager($request);
        $pip = $this->access->performanceImprovementPlan($user, $pip);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'reason' => ['sometimes', 'string', 'max:5000'],
            'expectations' => ['sometimes', 'string', 'max:5000'],
            'support_offered' => ['nullable', 'string', 'max:5000'],
            'consequences' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'review_date' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($user, $pip, $data): void {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $user)
                ->lockForUpdate()
                ->findOrFail($pip->getKey());
            $this->assertOpen($locked);
            $startDate = $data['start_date'] ?? $locked->start_date;
            $endDate = $data['end_date'] ?? $locked->end_date;
            if (CarbonImmutable::parse($endDate)->lessThanOrEqualTo(CarbonImmutable::parse($startDate))) {
                throw ValidationException::withMessages(['end_date' => 'The end date must be after the start date.']);
            }
            $locked->update([...$data, 'updated_by' => $user->id]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Plan updated.');
    }

    public function cancel(Request $request, HrPerformanceImprovementPlan $pip)
    {
        $user = $this->manager($request);
        $pip = $this->access->performanceImprovementPlan($user, $pip);
        $data = $request->validate(['outcome_notes' => ['nullable', 'string', 'max:5000']]);

        DB::transaction(function () use ($user, $pip, $data): void {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $user)
                ->lockForUpdate()
                ->findOrFail($pip->getKey());
            $this->assertOpen($locked);
            $locked->update([
                'status' => 'cancelled',
                'outcome_notes' => $data['outcome_notes'] ?? $locked->outcome_notes,
                'completed_at' => now(),
                'updated_by' => $user->id,
            ]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Plan cancelled.');
    }

    public function storeMilestone(Request $request, HrPerformanceImprovementPlan $pip)
    {
        $user = $this->manager($request);
        $pip = $this->access->performanceImprovementPlan($user, $pip);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['required', 'date'],
        ]);

        DB::transaction(function () use ($user, $pip, $data): void {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $user)
                ->lockForUpdate()
                ->findOrFail($pip->getKey());
            $this->assertOpen($locked);
            $this->assertMilestoneWithinPlan($data['due_date'], $locked->start_date, $locked->end_date, 'due_date');

            $locked->milestones()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'due_date' => $data['due_date'],
                'status' => 'pending',
                'sort_order' => (int) $locked->milestones()->max('sort_order') + 1,
            ]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Milestone added.');
    }

    public function destroyMilestone(Request $request, HrPipMilestone $milestone)
    {
        $user = $this->manager($request);
        $milestone = $this->access->pipMilestone($user, $milestone);

        DB::transaction(function () use ($user, $milestone): void {
            $pip = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $user)
                ->lockForUpdate()
                ->findOrFail($milestone->pip_id);
            $this->assertOpen($pip);
            $locked = $this->access
                ->applyPipMilestoneScope(HrPipMilestone::query(), $user)
                ->where('pip_id', $pip->id)
                ->lockForUpdate()
                ->findOrFail($milestone->getKey());
            abort_unless($locked->status === 'pending', 422, 'Only pending milestones can be removed.');
            $evidencePath = $locked->evidence_path;
            $locked->delete();
            DB::afterCommit(fn () => $evidencePath && Storage::disk('private')->delete($evidencePath));
        }, attempts: 1);

        return redirect()->back()->with('success', 'Milestone removed.');
    }

    /**
     * Only the exact current employee may acknowledge their open plan.
     */
    public function acknowledge(Request $request, HrPerformanceImprovementPlan $pip)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->access->currentStaff($user, $user);

        $pip = HrPerformanceImprovementPlan::query()
            ->where('employee_user_id', $user->id)
            ->findOrFail($pip->getKey());

        DB::transaction(function () use ($user, $pip): void {
            $locked = HrPerformanceImprovementPlan::query()
                ->where('employee_user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($pip->getKey());
            $this->assertOpen($locked);
            if (! $locked->employee_acknowledged) {
                $locked->update([
                    'employee_acknowledged' => true,
                    'employee_acknowledged_at' => now(),
                    'updated_by' => $user->id,
                ]);
            }
        }, attempts: 1);

        return redirect()->back()->with('success', 'Plan acknowledged.');
    }

    public function uploadMilestoneEvidence(Request $request, HrPipMilestone $milestone)
    {
        $user = $this->manager($request);
        $milestone = $this->access->pipMilestone($user, $milestone);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240']]);

        $path = $request->file('file')->store('hr/pip-milestones/'.$milestone->id, 'private');
        try {
            DB::transaction(function () use ($user, $milestone, $path): void {
                $pip = $this->access
                    ->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $user)
                    ->lockForUpdate()
                    ->findOrFail($milestone->pip_id);
                $this->assertOpen($pip);
                $locked = $this->access
                    ->applyPipMilestoneScope(HrPipMilestone::query(), $user)
                    ->where('pip_id', $pip->id)
                    ->lockForUpdate()
                    ->findOrFail($milestone->getKey());
                $oldPath = $locked->evidence_path;
                $locked->update(['evidence_path' => $path]);
                DB::afterCommit(function () use ($oldPath, $path): void {
                    if ($oldPath && $oldPath !== $path) {
                        Storage::disk('private')->delete($oldPath);
                    }
                });
            }, attempts: 1);
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        return redirect()->back()->with('success', 'Evidence uploaded.');
    }

    /**
     * Evidence is available to the current subject and authorised Site managers.
     */
    public function downloadMilestoneEvidence(Request $request, HrPipMilestone $milestone)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ((int) $milestone->pip?->employee_user_id === (int) $user->id) {
            $this->access->currentStaff($user, $user);
            $milestone = HrPipMilestone::query()
                ->whereHas('pip', fn ($query) => $query->where('employee_user_id', $user->id))
                ->findOrFail($milestone->getKey());
        } else {
            abort_unless($user->canDo('hr.performance.view'), 404);
            $this->access->currentStaff($user, $user);
            $milestone = $this->access->pipMilestone($user, $milestone);
            abort_unless(
                $user->canDo('hr.performance.manage') || (int) $milestone->pip->manager_user_id === (int) $user->id,
                404,
            );
        }

        abort_unless($milestone->evidence_path, 404);

        return $this->streamPrivateAttachment(
            'private',
            $milestone->evidence_path,
            basename($milestone->evidence_path),
            Storage::disk('private')->mimeType($milestone->evidence_path) ?: null,
            'inline',
        );
    }

    public function updateMilestone(Request $request, HrPipMilestone $milestone)
    {
        $user = $this->manager($request);
        $milestone = $this->access->pipMilestone($user, $milestone);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:met,not_met,pending'],
            'reviewer_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($user, $milestone, $data): void {
            $pip = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $user)
                ->lockForUpdate()
                ->findOrFail($milestone->pip_id);
            $this->assertOpen($pip);
            $locked = $this->access
                ->applyPipMilestoneScope(HrPipMilestone::query(), $user)
                ->where('pip_id', $pip->id)
                ->lockForUpdate()
                ->findOrFail($milestone->getKey());
            $pending = $data['status'] === 'pending';
            $locked->update([
                'status' => $data['status'],
                'reviewer_notes' => $data['reviewer_notes'] ?? null,
                'reviewed_by' => $pending ? null : $user->id,
                'reviewed_at' => $pending ? null : now(),
            ]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Milestone updated.');
    }

    /**
     * Complete a reviewed plan or extend it to a new explicit end date.
     */
    public function complete(Request $request, HrPerformanceImprovementPlan $pip)
    {
        $user = $this->manager($request);
        $pip = $this->access->performanceImprovementPlan($user, $pip);
        $data = $request->validate([
            'outcome' => ['required', 'string', 'in:successful,unsuccessful,extended'],
            'outcome_notes' => ['nullable', 'string', 'max:5000'],
            'new_end_date' => ['required_if:outcome,extended', 'nullable', 'date'],
        ]);

        DB::transaction(function () use ($user, $pip, $data): void {
            $locked = $this->access
                ->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $user)
                ->lockForUpdate()
                ->findOrFail($pip->getKey());
            $this->assertOpen($locked);

            if ($data['outcome'] === 'extended') {
                if (CarbonImmutable::parse($data['new_end_date'])->lessThanOrEqualTo(CarbonImmutable::parse($locked->end_date))) {
                    throw ValidationException::withMessages([
                        'new_end_date' => 'The new end date must be after the current end date.',
                    ]);
                }
                $locked->update([
                    'end_date' => $data['new_end_date'],
                    'outcome' => 'extended',
                    'outcome_notes' => $data['outcome_notes'] ?? null,
                    'completed_at' => null,
                    'end_reminder_sent_at' => null,
                    'updated_by' => $user->id,
                ]);

                return;
            }

            abort_if(
                $locked->milestones()->where('status', 'pending')->exists(),
                422,
                'Review every milestone before completing this plan.',
            );
            $locked->update([
                'status' => 'completed',
                'outcome' => $data['outcome'],
                'outcome_notes' => $data['outcome_notes'] ?? null,
                'completed_at' => now(),
                'updated_by' => $user->id,
            ]);
        }, attempts: 1);

        if ($data['outcome'] === 'extended') {
            return redirect()->back()->with('success', 'PIP extended to the new end date.');
        }

        if ($data['outcome'] === 'unsuccessful') {
            return redirect()
                ->back()
                ->with('success', 'PIP completed with an unsuccessful outcome. If formal action is the next step, open a disciplinary case so it is handled through the proper process.')
                ->with('next', [
                    'action' => 'open_disciplinary_case',
                    'url' => "/hr/cases?new=1&employee={$pip->employee_user_id}&source_pip={$pip->id}",
                ]);
        }

        return redirect()->back()->with('success', 'PIP completed.');
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->currentStaff($user, $user);

        return $user;
    }

    private function assertOpen(HrPerformanceImprovementPlan $pip): void
    {
        abort_unless(
            in_array($pip->status, ['active', 'in_progress'], true),
            422,
            'This plan is closed and can no longer be changed.',
        );
    }

    private function assertMilestoneWithinPlan(
        mixed $dueDate,
        mixed $startDate,
        mixed $endDate,
        string $field,
    ): void {
        $due = CarbonImmutable::parse($dueDate);
        if ($due->lessThan(CarbonImmutable::parse($startDate)) || $due->greaterThan(CarbonImmutable::parse($endDate))) {
            throw ValidationException::withMessages([
                $field => 'The milestone due date must fall within the plan dates.',
            ]);
        }
    }
}
