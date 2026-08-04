<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrExitInterview;
use App\Domain\Hr\Services\ExitInterviewService;
use App\Domain\Hr\Services\HrLifecycleAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ExitInterviewController extends Controller
{
    /**
     * Departure-reason taxonomy, shared with the offboarding wizard.
     */
    private const DEPARTURE_REASONS = [
        ['value' => 'career_growth', 'label' => 'Career Growth'],
        ['value' => 'compensation', 'label' => 'Compensation'],
        ['value' => 'work_life_balance', 'label' => 'Work-Life Balance'],
        ['value' => 'management', 'label' => 'Management Issues'],
        ['value' => 'culture', 'label' => 'Company Culture'],
        ['value' => 'relocation', 'label' => 'Relocation'],
        ['value' => 'retirement', 'label' => 'Retirement'],
        ['value' => 'personal', 'label' => 'Personal Reasons'],
        ['value' => 'redundancy', 'label' => 'Redundancy'],
        ['value' => 'contract_end', 'label' => 'Contract End'],
        ['value' => 'other', 'label' => 'Other'],
    ];

    public function __construct(
        protected ExitInterviewService $exitInterviewService,
        protected HrLifecycleAccessService $lifecycleAccess,
    ) {}

    /**
     * List exit interviews.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $interviews = $this->lifecycleAccess->visibleInterviews($user)
            ->select([
                'id',
                'employee_profile_id',
                'interviewer_user_id',
                'interview_date',
                'departure_reason',
                'would_recommend',
                'overall_satisfaction',
                'is_confidential',
            ])
            ->with([
                'employeeProfile:id,user_id',
                'employeeProfile.user:id,name',
                'interviewer:id,name',
            ])
            ->when($request->query('reason'), fn ($q, $reason) => $q->where('departure_reason', $reason))
            ->orderByDesc('interview_date')
            ->paginate(20)
            ->withQueryString();

        $canManage = $this->canManage($user);

        $statsBase = $this->lifecycleAccess->visibleInterviews($user);
        $avgSatisfaction = (clone $statsBase)->whereNotNull('overall_satisfaction')->avg('overall_satisfaction');
        $recommendTotal = (clone $statsBase)->whereNotNull('would_recommend')->count();
        $recommendYes = (clone $statsBase)->where('would_recommend', true)->count();

        return Inertia::render('hr/exit-interviews/index', [
            'interviews' => $interviews,
            'stats' => [
                'total' => (clone $statsBase)->count(),
                'avg_satisfaction' => $avgSatisfaction !== null ? round((float) $avgSatisfaction, 1) : null,
                'recommend_pct' => $recommendTotal > 0 ? (int) round($recommendYes / $recommendTotal * 100) : null,
                'last_90_days' => (clone $statsBase)->where('interview_date', '>=', now()->subDays(90)->toDateString())->count(),
            ],
            'employees' => $canManage
                ? $this->lifecycleAccess->historicalProfiles($user)
                    ->with('user:id,name')
                    ->get(['id', 'user_id', 'position_title'])
                : [],
            'interviewers' => $canManage ? $this->interviewerOptions($user) : [],
            'departureReasons' => self::DEPARTURE_REASONS,
            'filters' => [
                'reason' => $request->query('reason'),
            ],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * The record form is now a wizard modal on the index page — keep the old
     * GET route working by bouncing to the index with the wizard open.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        return redirect()->route('hr.exit-interviews.index', ['new' => 1]);
    }

    /**
     * Store a new exit interview.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer'],
            'interviewer_user_id' => ['required', 'integer'],
            'interview_date' => ['required', 'date'],
            'departure_reason' => ['required', 'string', 'max:255'],
            'would_recommend' => ['nullable', 'boolean'],
            'overall_satisfaction' => ['nullable', 'integer', 'min:1', 'max:5'],
            'what_went_well' => ['nullable', 'string', 'max:5000'],
            'what_could_improve' => ['nullable', 'string', 'max:5000'],
            'management_feedback' => ['nullable', 'string', 'max:5000'],
            'culture_feedback' => ['nullable', 'string', 'max:5000'],
            'additional_comments' => ['nullable', 'string', 'max:5000'],
            'is_confidential' => ['sometimes', 'boolean'],
            'offboarding_task_id' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($user, $data): void {
            $profile = $this->lifecycleAccess->historicalProfile(
                $user,
                (int) $data['employee_profile_id'],
                true,
            );
            $this->lifecycleAccess->currentUser(
                $user,
                (int) $data['interviewer_user_id'],
                true,
            );

            if (! empty($data['offboarding_task_id'])) {
                $task = $this->lifecycleAccess->visibleOffboardingTask(
                    $user,
                    (int) $data['offboarding_task_id'],
                    true,
                );
                $checklist = $task->checklist()->firstOrFail();
                abort_unless($checklist->employee_profile_id === $profile->id, 404);
            }

            $this->exitInterviewService->createExitInterview([
                'created_by' => $user->id,
                ...$data,
            ]);
        });

        // When recorded from an offboarding checklist, stay on that page.
        if ($request->boolean('from_offboarding')) {
            return redirect()->back()->with('success', 'Exit interview recorded.');
        }

        return redirect()->route('hr.exit-interviews.index')->with('success', 'Exit interview recorded.');
    }

    /**
     * Persisted exit interviews are submitted records, not drafts. Keep the
     * endpoint explicit so stale or future edit clients fail safely.
     */
    public function update(Request $request, HrExitInterview $exitInterview)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $exitInterview = $this->lifecycleAccess->visibleInterview($user, $exitInterview);

        $answerFields = [
            'would_recommend',
            'overall_satisfaction',
            'what_went_well',
            'what_could_improve',
            'management_feedback',
            'culture_feedback',
            'additional_comments',
        ];
        $hasSubmittedAnswers = collect($answerFields)
            ->contains(fn (string $field) => $exitInterview->{$field} !== null);
        $unexpectedFields = collect($request->except('_token', '_method'))
            ->keys()
            ->diff(['interviewer_user_id', 'interview_date'])
            ->isNotEmpty();

        if ($hasSubmittedAnswers || $unexpectedFields) {
            return redirect()->back()->with(
                'error',
                'Submitted exit interviews are locked. Add an addendum instead.',
            );
        }

        $data = $request->validate([
            'interviewer_user_id' => ['required', 'integer'],
            'interview_date' => ['required', 'date'],
        ]);

        try {
            DB::transaction(function () use ($user, $exitInterview, $data, $answerFields, $unexpectedFields): void {
                $lockedInterview = $this->lifecycleAccess->visibleInterview($user, $exitInterview, true);
                $hasLockedAnswers = collect($answerFields)
                    ->contains(fn (string $field) => $lockedInterview->{$field} !== null);
                if ($hasLockedAnswers || $unexpectedFields) {
                    throw new \LogicException('Submitted exit interviews are locked. Add an addendum instead.');
                }

                $this->lifecycleAccess->currentUser(
                    $user,
                    (int) $data['interviewer_user_id'],
                    true,
                );
                $this->exitInterviewService->rescheduleInterview($lockedInterview, $data);
            });
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Exit interview schedule updated.');
    }

    /**
     * Append an addendum while preserving every submitted answer.
     */
    public function storeAddendum(Request $request, HrExitInterview $exitInterview)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $exitInterview = $this->lifecycleAccess->visibleInterview($user, $exitInterview);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($user, $exitInterview, $data): void {
            $lockedInterview = $this->lifecycleAccess->visibleInterview($user, $exitInterview, true);
            $this->exitInterviewService->appendAddendum($lockedInterview, $data['note'], $user);
        });

        return redirect()->back()->with(
            'success',
            'Addendum appended. The submitted interview remains unchanged.',
        );
    }

    /**
     * Show a single exit interview.
     */
    public function show(Request $request, HrExitInterview $exitInterview)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $exitInterview = $this->lifecycleAccess->visibleInterview($user, $exitInterview);

        $exitInterview->load([
            'employeeProfile:id,user_id,position_title',
            'employeeProfile.user:id,name',
            'interviewer:id,name',
            'creator:id,name',
        ]);

        return Inertia::render('hr/exit-interviews/show', [
            'interview' => $exitInterview,
            'can' => [
                'manage' => $this->canManage($user),
            ],
        ]);
    }

    /**
     * Aggregate trends view for exit interviews.
     */
    public function trends(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $fromDate = $request->query('from', now()->subYear()->toDateString());
        $toDate = $request->query('to', now()->toDateString());

        $trends = $this->exitInterviewService->getExitTrends(
            $this->lifecycleAccess->visibleInterviews($user),
            $fromDate,
            $toDate,
        );

        return Inertia::render('hr/exit-interviews/trends', [
            'trends' => $trends,
            'filters' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
        ]);
    }

    /**
     * Users who can be recorded as interviewers for the wizard.
     */
    private function interviewerOptions(User $viewer): Collection
    {
        return $this->lifecycleAccess->currentUsers($viewer)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.exit-interviews.view')
            || $user->canDo('hr.exit-interviews.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && $user->canDo('hr.exit-interviews.manage');
    }
}
