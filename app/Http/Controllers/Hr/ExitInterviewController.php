<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExitInterview;
use App\Domain\Hr\Services\ExitInterviewService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExitInterviewController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        protected ExitInterviewService $exitInterviewService,
    ) {}

    /**
     * List exit interviews.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $interviews = HrExitInterview::forTenant($tenantId)
            ->with([
                'employeeProfile.user:id,name',
                'interviewer:id,name',
            ])
            ->when($request->query('reason'), fn ($q, $reason) => $q->where('departure_reason', $reason))
            ->orderByDesc('interview_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/exit-interviews/index', [
            'interviews' => $interviews,
            'filters' => [
                'reason' => $request->query('reason'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.exit-interviews.manage'),
            ],
        ]);
    }

    /**
     * Show form to create an exit interview.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $employees = HrEmployeeProfile::forTenant($tenantId)
            ->with('user:id,name')
            ->get(['id', 'user_id', 'position_title']);

        $interviewerIds = HrEmployeeProfile::forTenant($tenantId)
            ->pluck('user_id')
            ->push($user->id)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $interviewers = User::whereIn('id', $interviewerIds)
            ->get(['id', 'name']);

        return Inertia::render('hr/exit-interviews/create', [
            'employees' => $employees,
            'interviewers' => $interviewers,
            'departureReasons' => [
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
            ],
        ]);
    }

    /**
     * Store a new exit interview.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'interviewer_user_id' => ['required', 'integer', 'exists:users,id'],
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
        ]);

        $this->exitInterviewService->createExitInterview([
            'tenant_id' => $tenantId,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->route('hr.exit-interviews.index')->with('success', 'Exit interview recorded.');
    }

    /**
     * Show a single exit interview.
     */
    public function show(Request $request, HrExitInterview $exitInterview)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $exitInterview->load([
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
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $fromDate = $request->query('from', now()->subYear()->toDateString());
        $toDate = $request->query('to', now()->toDateString());

        $trends = $this->exitInterviewService->getExitTrends(
            $tenantId,
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

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.exit-interviews.view')
            || $user->canDo('hr.exit-interviews.manage')
            || $user->canDo('hr.onboarding.view')
            || $user->canDo('hr.onboarding.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.exit-interviews.manage')
            || $user->canDo('hr.onboarding.manage')
        );
    }
}
