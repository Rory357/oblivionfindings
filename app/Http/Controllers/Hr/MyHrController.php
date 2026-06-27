<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Hr\Services\EngagementService;
use App\Domain\Hr\Services\ESignatureService;
use App\Domain\Hr\Services\ExpenseService;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsMyHrOverview;
use App\Http\Controllers\Hr\Concerns\BuildsMyHrShell;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StoreExpenseClaimRequest;
use App\Models\ProcedureAcknowledgement;
use App\Models\SafeWorkProcedure;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MyHrController extends Controller
{
    use BuildsMyHrOverview, BuildsMyHrShell, ResolvesHrTenant;

    public function __construct(
        private readonly LeaveService $leaveService,
        private readonly EngagementService $engagementService,
        private readonly TimeTrackingService $timeTrackingService,
        private readonly AttendanceService $attendanceService,
        private readonly ExpenseService $expenseService,
        private readonly FeedService $feedService,
    ) {}

    public function sendKudos(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $notForeignTenant = $this->rejectForeignTenantRecipient($tenantId);

        $validated = $request->validate([
            'to_user_id' => ['required_without:to_user_ids', 'integer', 'exists:users,id', $notForeignTenant],
            'to_user_ids' => ['required_without:to_user_id', 'array', 'min:1'],
            'to_user_ids.*' => ['integer', 'exists:users,id', $notForeignTenant],
            'category' => ['required', 'string', Rule::in(array_keys(FeedService::KUDOS_CATEGORIES))],
            'impact' => ['nullable', 'string', Rule::in(array_keys(FeedService::KUDOS_IMPACTS))],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $recipientIds = $validated['to_user_ids'] ?? [$validated['to_user_id']];

        try {
            $this->feedService->sendKudosToMany(
                $user,
                $recipientIds,
                $validated['category'],
                $validated['message'],
                $tenantId,
                $validated['impact'] ?? null,
            );
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $count = count($recipientIds);

        return redirect()->back()->with('success', $count > 1 ? "Kudos sent to {$count} colleagues! 🎉" : 'Kudos sent! 🎉');
    }

    /**
     * The "Shout-outs" tab — the full received recognition spotlight (carousel +
     * reactions + reply thread) plus the shout-outs this employee has given (so
     * they can reply back and close the loop). Body reuses the same spotlight
     * components as the Overview.
     */
    public function shoutouts(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        return Inertia::render('hr/my/shoutouts', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'received' => $this->myHrShoutouts($user, $tenantId, 'received'),
            'given' => $this->myHrShoutouts($user, $tenantId, 'given'),
        ]);
    }

    /**
     * Toggle an emoji reaction on a kudos for the current user (one of each
     * emoji per person — click again to remove). Open to any teammate in the
     * tenant since kudos live on the shared recognition feed.
     */
    public function reactKudos(Request $request, HrKudos $kudos)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $kudos->tenant_id);

        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in(FeedService::REACTION_EMOJIS)],
        ]);

        $this->feedService->toggleReaction($kudos, $user->id, $validated['emoji']);

        return redirect()->back()->with('success', 'Reaction updated.');
    }

    /**
     * Post a reply on a kudos thread. Restricted to the two parties (giver +
     * receiver) so the conversation stays between them — "Say thanks" posts the
     * receiver's reply; the giver can write back from their given shout-outs.
     */
    public function replyKudos(Request $request, HrKudos $kudos)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $kudos->tenant_id);
        abort_unless(in_array($user->id, [$kudos->from_user_id, $kudos->to_user_id], true), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $this->feedService->addReply($kudos, $user->id, $validated['body']);

        return redirect()->back()->with('success', 'Reply posted.');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $profile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->with('user:id,name,email,profile_photo_path')
            ->first();

        $pendingLeave = HrLeaveRequest::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $leaveBalances = HrLeaveBalance::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('year', now()->year)
            ->get();

        $complianceStatuses = HrStaffComplianceStatus::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->with('requirement:id,code,name,category')
            ->get();

        $complianceSummary = [
            'compliant' => $complianceStatuses->where('status', 'compliant')->count(),
            'expiring_soon' => $complianceStatuses->where('status', 'expiring_soon')->count(),
            'expired' => $complianceStatuses->where('status', 'expired')->count(),
            'not_started' => $complianceStatuses->where('status', 'not_started')->count(),
        ];

        $policiesDue = HrPolicy::active()
            ->where('tenant_id', $tenantId)
            ->where('requires_attestation', true)
            ->whereDoesntHave('attestations', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        $pendingReviews = HrPerformanceReview::where('tenant_id', $tenantId)
            ->where('employee_user_id', $user->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->where(fn ($q) => $q->whereNull('employee_signed_off')->orWhere('employee_signed_off', false))
            ->count();

        $activeGoals = HrDevelopmentGoal::where('tenant_id', $tenantId)
            ->where('employee_user_id', $user->id)
            ->whereIn('status', ['not_started', 'in_progress', 'blocked'])
            ->count();

        $availableSurveys = HrEngagementSurvey::where('tenant_id', $tenantId)
            ->where('status', 'published')
            ->count();

        // Timekeeping
        $activeClock = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->first(['id', 'clock_in', 'notes']);

        $weeklySummary = $this->timeTrackingService->getWeeklySummary($tenantId, $user->id);

        $todayTotal = (float) HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->where('entry_date', now()->toDateString())
            ->whereNotNull('clock_out')
            ->sum('total_hours');

        // Latest payslip
        $latestPayslip = HrPayslip::where('user_id', $user->id)
            ->where('status', 'paid')
            ->orderByDesc('payment_date')
            ->first(['net_pay', 'payment_date']);

        // Expenses
        $pendingExpenses = HrExpenseClaim::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'submitted')
            ->selectRaw('count(*) as count, coalesce(sum(total_amount), 0) as total')
            ->first();

        // Kudos received (last 30 days)
        $kudosReceived = HrKudos::where('tenant_id', $tenantId)
            ->where('to_user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // Announcements (active) for the "Around your team" card + "See all" modal.
        // The card surfaces unacknowledged ones first; the modal lists every active
        // notice with its body + an Acknowledge affordance (Seen ✓ once done).
        $announcements = HrAnnouncement::forTenant($tenantId)
            ->active()
            ->with('creator:id,name')
            ->withExists(['acknowledgements as acknowledged' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(12)
            ->get()
            ->map(fn (HrAnnouncement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'priority' => $a->priority,
                'content' => $a->content,
                'published_at' => $a->published_at?->toIso8601String(),
                'byline' => trim(implode(' · ', array_filter([
                    $a->creator?->name,
                    $a->published_at?->isoFormat('D MMM'),
                ]))),
                'acknowledged' => (bool) $a->acknowledged,
            ])
            ->values();

        // Safe Work Procedures applicable to my role(s) — deep-link to the register's
        // detail modal, with a version-stamped "Acknowledge" affordance. Role-matched
        // + org-wide (approved).
        $roleKeys = $user->roles()->pluck('name')->all();
        $ackedVersions = ProcedureAcknowledgement::query()
            ->where('user_id', $user->id)
            ->pluck('version_acknowledged', 'safe_work_procedure_id');
        $safeWorkProcedures = $user->canDo('procedures.view')
            ? SafeWorkProcedure::query()->applicableToRoles($roleKeys)
                ->orderBy('title')
                ->limit(25)
                ->get(['id', 'reference_number', 'title', 'category', 'status', 'review_date', 'current_version'])
                ->map(fn (SafeWorkProcedure $p) => [
                    'id' => $p->id,
                    'reference_number' => $p->reference_number,
                    'title' => $p->title,
                    'category' => $p->category,
                    'status' => $p->status,
                    'review_date' => $p->review_date?->toDateString(),
                    'acknowledged' => (int) ($ackedVersions[$p->id] ?? 0) === (int) $p->current_version,
                ])->values()
            : collect();

        return Inertia::render('hr/my/index', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'overview' => $this->myHrOverviewProps($user, $tenantId),
            'safeWorkProcedures' => $safeWorkProcedures,
            // Shaped for the Overview's hosted "Request leave" wizard (mirrors
            // the Leave tab's `balances` contract).
            'balances' => $leaveBalances->map(fn (HrLeaveBalance $b) => [
                'leave_type' => $b->leave_type,
                'entitlement_hours' => (float) $b->accrued_hours,
                'taken_hours' => (float) $b->used_hours,
                'remaining_hours' => (float) $b->balance_hours,
            ])->values(),
            'profile' => $profile,
            'pendingLeave' => $pendingLeave,
            'leaveBalances' => $leaveBalances,
            'complianceSummary' => $complianceSummary,
            'complianceStatuses' => $complianceStatuses,
            'policiesDue' => $policiesDue,
            'pendingReviews' => $pendingReviews,
            'activeGoals' => $activeGoals,
            'availableSurveys' => $availableSurveys,
            'activeClock' => $activeClock,
            'weeklySummary' => $weeklySummary,
            'todayTotal' => $todayTotal,
            'latestPayslip' => $latestPayslip,
            'pendingExpenses' => $pendingExpenses ? [
                'count' => (int) $pendingExpenses->count,
                'total' => (float) $pendingExpenses->total,
            ] : ['count' => 0, 'total' => 0],
            'kudosReceived' => $kudosReceived,
            'announcements' => $announcements,
            'canViewFeed' => $user->canDo('hr.announcements.view'),
            // Feeds the Overview's hosted "Request leave" wizard (tile picker +
            // calendar holiday highlight).
            'leaveTypes' => LeaveService::LEAVE_TYPES,
            'publicHolidays' => $this->leaveService->publicHolidayMap($tenantId),
        ]);
    }

    public function leave(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $requests = HrLeaveRequest::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->with('reviewer:id,name')
            ->orderByDesc('submitted_at')
            ->paginate(20)
            ->withQueryString();

        $requests->getCollection()->transform(fn ($r) => [
            'id' => $r->id,
            'leave_type' => $r->leave_type,
            'start_date' => $r->starts_at?->toDateString(),
            'end_date' => $r->ends_at?->toDateString(),
            'hours' => (float) $r->hours_requested,
            'status' => $r->status,
            'reason' => $r->reason,
            'created_at' => $r->submitted_at?->toDateString() ?? $r->created_at?->toDateString(),
        ]);

        $balances = HrLeaveBalance::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('year', now()->year)
            ->get()
            ->map(fn ($b) => [
                'leave_type' => $b->leave_type,
                'entitlement_hours' => (float) $b->accrued_hours,
                'taken_hours' => (float) $b->used_hours,
                'remaining_hours' => (float) $b->balance_hours,
            ]);

        return Inertia::render('hr/my/leave', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'whosOutWeek' => $this->myHrWhosOutByDay($user, $tenantId),
            'requests' => $requests,
            'balances' => $balances,
            'leaveTypes' => LeaveService::LEAVE_TYPES,
            'publicHolidays' => $this->leaveService->publicHolidayMap($tenantId),
        ]);
    }

    public function submitLeave(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'leave_type' => ['required', 'string', Rule::in(LeaveService::LEAVE_TYPES)],
            'period' => ['nullable', Rule::in(['full_day', 'half_day_am', 'half_day_pm'])],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'hours_requested' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'supporting_doc' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ]);

        if (in_array($validated['period'] ?? null, ['half_day_am', 'half_day_pm'], true)
            && $validated['starts_at'] !== $validated['ends_at']) {
            return redirect()->back()->with('error', 'A half-day can only be requested for a single day.');
        }

        $data = $validated;

        if ($request->hasFile('supporting_doc')) {
            $data['supporting_doc_path'] = $request->file('supporting_doc')
                ->store("leave/{$user->id}", 'private');
        }

        try {
            $this->leaveService->submitRequest($user, $data);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Leave request submitted.');
    }

    /**
     * Read-only preview for the self-service request modal review step (handover §5.3).
     */
    public function previewLeave(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'leave_type' => ['required', 'string', Rule::in(LeaveService::LEAVE_TYPES)],
            'period' => ['nullable', Rule::in(['full_day', 'half_day_am', 'half_day_pm'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);

        try {
            $preview = $this->leaveService->previewRequest($user, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    public function expenses(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $claims = HrExpenseClaim::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $claims->through(fn (HrExpenseClaim $claim) => [
            'id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'title' => $claim->title,
            'status' => $claim->status,
            'total_amount' => (float) $claim->total_amount,
            'currency' => $claim->currency,
            'items_count' => $claim->items_count,
            'submitted_at' => $claim->submitted_at?->toDateString(),
            'created_at' => $claim->created_at?->toDateString(),
        ]);

        return Inertia::render('hr/my/expenses', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'claims' => $claims,
            'categories' => ExpenseService::CATEGORIES,
        ]);
    }

    public function submitExpense(StoreExpenseClaimRequest $request)
    {
        try {
            $this->expenseService->createClaim($request->user(), $request->validated());
        } catch (\Throwable $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('hr.my.expenses')->with('success', 'Expense claim created.');
    }

    public function cancelLeave(Request $request, HrLeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_unless($leaveRequest->user_id === $user->id, 403);
        abort_unless(in_array($leaveRequest->status, ['pending', 'approved'], true), 422);

        try {
            $this->leaveService->cancelRequest($leaveRequest, $user->id);
        } catch (\LogicException $exception) {
            return redirect()->back()->withErrors(['leave_request' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Leave request cancelled.');
    }

    public function training(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $complianceStatuses = HrStaffComplianceStatus::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->with('requirement:id,code,name,category,description,validity_months,renewal_reminder_days,check_type')
            ->orderByRaw("FIELD(status, 'expired', 'non_compliant', 'expiring_soon', 'not_started', 'compliant')")
            ->get()
            ->map(function (HrStaffComplianceStatus $status) {
                $normalizedStatus = match ($status->status) {
                    'compliant' => 'compliant',
                    'expiring_soon' => 'expiring_soon',
                    'expired', 'non_compliant' => 'expired',
                    default => 'not_started',
                };

                $daysUntilExpiry = $status->expires_at
                    ? now()->startOfDay()->diffInDays($status->expires_at->startOfDay(), false)
                    : null;

                return [
                    'id' => $status->id,
                    'status' => $normalizedStatus,
                    'expiry_date' => optional($status->expires_at)->toDateString(),
                    'completed_at' => optional($status->valid_from)->toDateString(),
                    'days_until_expiry' => $daysUntilExpiry,
                    'evidence_type' => $status->evidence_type,
                    'requirement' => [
                        'id' => $status->requirement?->id,
                        'name' => $status->requirement?->name ?? 'Untitled requirement',
                        'category' => $status->requirement?->category ?? 'general',
                        'description' => $status->requirement?->description,
                        'validity_months' => $status->requirement?->validity_months,
                        'check_type' => $status->requirement?->check_type,
                    ],
                ];
            })
            ->values();

        return Inertia::render('hr/my/training', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'complianceStatuses' => $complianceStatuses,
            'can' => [
                // Only surface the LMS catalog link to users who can open it
                // (the catalog route is gated hr.training.view|training.viewAny).
                'viewCatalog' => $user->canDo('hr.training.view') || $user->canDo('training.viewAny'),
            ],
        ]);
    }

    public function policies(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $policies = HrPolicy::active()
            ->where('tenant_id', $tenantId)
            ->where('requires_attestation', true)
            ->with(['versions' => fn ($q) => $q->where('is_current', true)])
            ->orderBy('title')
            ->get()
            ->map(function ($policy) use ($user, $tenantId) {
                $attestation = HrPolicyAttestation::where('policy_id', $policy->id)
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $user->id)
                    ->orderByDesc('attested_at')
                    ->first();

                $policy->my_attestation = $attestation;
                $policy->is_attested = $attestation !== null;

                return $policy;
            });

        return Inertia::render('hr/my/policies', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'policies' => $policies,
        ]);
    }

    public function attestPolicy(Request $request, HrPolicy $policy)
    {
        $user = $request->user();
        abort_unless($user->canDo('hr.policies.attest'), 403);
        abort_unless($policy->requires_attestation, 422);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $policy->tenant_id);

        HrPolicyAttestation::create([
            'tenant_id' => $tenantId,
            'policy_id' => $policy->id,
            'policy_version_id' => $policy->currentVersion?->id,
            'user_id' => $user->id,
            'attested_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->back()->with('success', 'Policy attestation recorded.');
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $profile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->with(['user:id,name,email', 'primarySite:id,name'])
            ->first();

        $primaryEmergencyContact = collect($profile?->emergency_contacts ?? [])->first();

        $profileData = $profile ? [
            'id' => $profile->id,
            'employee_number' => $profile->employee_number,
            'position_title' => $profile->position_title,
            'employment_type' => $profile->employment_type,
            'start_date' => optional($profile->start_date)->toDateString(),
            'end_date' => optional($profile->end_date)->toDateString(),
            'is_active' => (bool) $profile->is_active,
            'personal_email' => $profile->personal_email,
            'phone' => $profile->personal_phone,
            'home_address' => $profile->home_address,
            'emergency_contact_name' => $primaryEmergencyContact['name'] ?? null,
            'emergency_contact_phone' => $primaryEmergencyContact['phone'] ?? null,
            'emergency_contact_relationship' => $primaryEmergencyContact['relationship'] ?? null,
            'user' => $profile->user ? [
                'id' => $profile->user->id,
                'name' => $profile->user->name,
                'email' => $profile->user->email,
                'avatar' => $profile->user->profile_photo_path,
            ] : null,
            'primary_site' => $profile->primarySite ? [
                'id' => $profile->primarySite->id,
                'name' => $profile->primarySite->name,
            ] : null,
        ] : null;

        return Inertia::render('hr/my/profile', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'profile' => $profileData,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $profile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'personal_phone' => ['nullable', 'string', 'max:50'],
            'home_address' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contacts' => ['nullable', 'array'],
        ]);

        $phone = trim((string) ($validated['personal_phone'] ?? $validated['phone'] ?? ''));
        $phone = $phone !== '' ? $phone : null;

        $emergencyContacts = [];
        if (is_array($validated['emergency_contacts'] ?? null)) {
            $emergencyContacts = $validated['emergency_contacts'];
        }

        $name = trim((string) ($validated['emergency_contact_name'] ?? ''));
        $contactPhone = trim((string) ($validated['emergency_contact_phone'] ?? ''));
        $relationship = trim((string) ($validated['emergency_contact_relationship'] ?? ''));
        if ($name !== '' || $contactPhone !== '' || $relationship !== '') {
            $emergencyContacts = [[
                'name' => $name !== '' ? $name : null,
                'phone' => $contactPhone !== '' ? $contactPhone : null,
                'relationship' => $relationship !== '' ? $relationship : null,
            ]];
        }

        $profile->update([
            'personal_email' => $validated['personal_email'] ?? null,
            'personal_phone' => $phone,
            'home_address' => $validated['home_address'] ?? null,
            'emergency_contacts' => $emergencyContacts !== [] ? $emergencyContacts : null,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Profile updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Staff Directory (phonebook) */
    /* ------------------------------------------------------------------ */

    /**
     * The all-staff "who is who" directory, surfaced as a My HR tab so every
     * staff member can look up a colleague's role, site and work contact.
     *
     * Work contact only — personal phone/email never appear here (those stay on
     * the person's own Profile tab and the manager-only People hub). Gated to
     * staff: the viewer must have an HR employee profile, so portal/family users
     * (who have none) get a 403 rather than the full staff roster.
     */
    public function directory(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $viewerProfile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first(['id']);
        abort_unless($viewerProfile, 403);

        $people = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->with([
                'user:id,name,email',
                'primarySite:id,name',
                'departmentRelation:id,name',
            ])
            ->get()
            ->map(function (HrEmployeeProfile $p) use ($user) {
                $name = $p->preferred_name ?: $p->user?->name;
                if (! $name) {
                    return null;
                }

                return [
                    'id' => $p->id,
                    'name' => $name,
                    'initials' => $this->myHrInitials($name),
                    'role' => $p->position_title,
                    'department' => $p->departmentRelation?->name ?? $p->department,
                    'site' => $p->primarySite?->name,
                    'email' => $p->work_email ?: $p->user?->email,
                    'phone' => $p->work_phone,
                    'avatar' => $p->profile_photo_path,
                    'is_first_aider' => (bool) $p->is_first_aider,
                    'is_fire_warden' => (bool) $p->is_fire_warden,
                    'is_self' => $p->user_id === $user->id,
                ];
            })
            ->filter()
            ->sortBy(fn ($p) => mb_strtolower($p['name']), SORT_NATURAL)
            ->values()
            ->all();

        return Inertia::render('hr/my/directory', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'people' => $people,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Performance Reviews */
    /* ------------------------------------------------------------------ */

    public function reviews(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $reviews = HrPerformanceReview::where('tenant_id', $tenantId)
            ->where('employee_user_id', $user->id)
            ->with('reviewer:id,name')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $reviews->through(fn (HrPerformanceReview $review) => [
            'id' => $review->id,
            'review_type' => $review->review_type,
            'review_period_start' => $review->review_period_start?->toDateString(),
            'review_period_end' => $review->review_period_end?->toDateString(),
            'status' => $review->status,
            'overall_rating' => $review->overall_rating,
            'strengths' => $review->strengths,
            'development_areas' => $review->development_areas,
            'goals' => $review->goals,
            'training_recommendations' => $review->training_recommendations,
            'employee_comments' => $review->employee_comments,
            'employee_signed_off' => (bool) $review->employee_signed_off,
            'employee_signed_off_at' => $review->employee_signed_off_at?->toDateTimeString(),
            'manager_signed_off' => (bool) $review->manager_signed_off,
            'next_review_date' => $review->next_review_date?->toDateString(),
            'reviewer' => $review->reviewer ? [
                'id' => $review->reviewer->id,
                'name' => $review->reviewer->name,
            ] : null,
        ]);

        return Inertia::render('hr/my/reviews', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'reviews' => $reviews,
        ]);
    }

    public function updateReview(Request $request, HrPerformanceReview $review)
    {
        $user = $request->user();
        abort_unless($review->employee_user_id === $user->id, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $review->tenant_id);

        $validated = $request->validate([
            'employee_comments' => ['nullable', 'string', 'max:5000'],
            'employee_signed_off' => ['nullable', 'boolean'],
        ]);

        $data = ['employee_comments' => $validated['employee_comments'] ?? $review->employee_comments];

        if (! empty($validated['employee_signed_off']) && ! $review->employee_signed_off) {
            $data['employee_signed_off'] = true;
            $data['employee_signed_off_at'] = now();
        }

        $review->update($data);

        return redirect()->back()->with('success', 'Review updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Development Goals */
    /* ------------------------------------------------------------------ */

    public function goals(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $goals = HrDevelopmentGoal::where('tenant_id', $tenantId)
            ->where('employee_user_id', $user->id)
            ->with('manager:id,name')
            ->orderByRaw("CASE WHEN status IN ('in_progress','not_started','blocked') THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->paginate(20)
            ->withQueryString();

        $goals->through(fn (HrDevelopmentGoal $goal) => [
            'id' => $goal->id,
            'title' => $goal->title,
            'description' => $goal->description,
            'category' => $goal->category,
            'competency_area' => $goal->competency_area,
            'target_level' => $goal->target_level,
            'current_level' => $goal->current_level,
            'status' => $goal->status,
            'progress_percent' => (int) $goal->progress_percent,
            'start_date' => $goal->start_date?->toDateString(),
            'due_date' => $goal->due_date?->toDateString(),
            'completed_at' => $goal->completed_at?->toDateString(),
            'review_notes' => $goal->review_notes,
            'manager' => $goal->manager ? [
                'id' => $goal->manager->id,
                'name' => $goal->manager->name,
            ] : null,
        ]);

        return Inertia::render('hr/my/goals', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'goals' => $goals,
        ]);
    }

    public function updateGoal(Request $request, HrDevelopmentGoal $goal)
    {
        $user = $request->user();
        abort_unless($goal->employee_user_id === $user->id, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $goal->tenant_id);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['not_started', 'in_progress', 'blocked', 'completed'])],
            'progress_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'review_notes' => ['nullable', 'string', 'max:5000'],
            'current_level' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        if (($validated['status'] ?? null) === 'completed') {
            $validated['completed_at'] = now();
            $validated['progress_percent'] = 100;
        }

        $goal->update($validated);

        return redirect()->back()->with('success', 'Goal updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Engagement Surveys */
    /* ------------------------------------------------------------------ */

    public function surveys(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $surveys = HrEngagementSurvey::where('tenant_id', $tenantId)
            ->where('status', 'published')
            ->with('questions')
            ->get()
            ->map(function (HrEngagementSurvey $survey) use ($user) {
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

                return [
                    'id' => $survey->id,
                    'title' => $survey->title,
                    'description' => $survey->description,
                    'is_anonymous' => $survey->is_anonymous,
                    'starts_at' => $survey->starts_at?->toDateString(),
                    'ends_at' => $survey->ends_at?->toDateString(),
                    'has_responded' => $hasResponded,
                    'questions' => $survey->questions->sortBy('sort_order')->values()->map(fn ($q) => [
                        'id' => $q->id,
                        'question_type' => $q->question_type,
                        'question_text' => $q->question_text,
                        'options' => $q->options,
                        'is_required' => $q->is_required,
                        'sort_order' => $q->sort_order,
                    ])->all(),
                ];
            })
            ->values();

        return Inertia::render('hr/my/surveys', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'surveys' => $surveys,
        ]);
    }

    public function submitSurvey(Request $request, HrEngagementSurvey $survey)
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $survey->tenant_id);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        try {
            $this->engagementService->submitResponse($survey, $user, $validated['answers']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Survey response submitted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Timekeeping (Self-Service) */
    /* ------------------------------------------------------------------ */

    public function time(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $activeClock = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->with([
                'shift:id,client_id,service_context_id,starts_at,ends_at,shift_type,is_sleepover,is_on_call,expected_break_minutes,location',
                'shift.client:id,first_name,last_name',
                'shift.serviceContext:id,name',
            ])
            ->first(['id', 'clock_in', 'notes', 'shift_id']);

        $todayEntries = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->where('entry_date', now()->toDateString())
            ->with(
                'shift:id,client_id,service_context_id,starts_at,ends_at,shift_type,is_sleepover,is_on_call,expected_break_minutes,location',
                'shift.client:id,first_name,last_name',
                'shift.serviceContext:id,name',
                'client:id,first_name,last_name'
            )
            ->orderBy('clock_in')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'entry_date' => $entry->entry_date->toDateString(),
                'clock_in' => $entry->clock_in->format('H:i'),
                'clock_out' => $entry->clock_out?->format('H:i'),
                'break_minutes' => $entry->break_minutes,
                'total_hours' => $entry->total_hours,
                'entry_type' => $entry->entry_type,
                'status' => $entry->status,
                'pay_type' => $entry->pay_type ?? 'standard',
                'notes' => $entry->notes,
                'shift' => $entry->shift ? [
                    'id' => $entry->shift->id,
                    'starts_at' => $entry->shift->starts_at?->format('H:i'),
                    'ends_at' => $entry->shift->ends_at?->format('H:i'),
                    'shift_type' => $entry->shift->shift_type ?? 'standard',
                    'is_sleepover' => (bool) $entry->shift->is_sleepover,
                    'is_on_call' => (bool) $entry->shift->is_on_call,
                    'expected_break_minutes' => $entry->shift->expected_break_minutes,
                    'location' => $entry->shift->location,
                    'service_context_name' => $entry->shift->serviceContext?->name,
                    'client_name' => trim(($entry->shift->client?->first_name ?? '').' '.($entry->shift->client?->last_name ?? '')),
                ] : null,
                'client_name' => $entry->client
                    ? trim(($entry->client->first_name ?? '').' '.($entry->client->last_name ?? ''))
                    : null,
            ]);

        $weeklySummary = $this->timeTrackingService->getWeeklySummary($tenantId, $user->id);

        // This week's roster (read-only from Operations) — 7 day columns.
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();
        $weekShifts = Shift::where('user_id', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->whereBetween('starts_at', [$weekStart, $weekEnd])
            ->with('client:id,first_name,last_name', 'serviceContext:id,name')
            ->orderBy('starts_at')
            ->get();

        $weekRoster = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $dayShifts = $weekShifts
                ->filter(fn (Shift $s) => $s->starts_at && $s->starts_at->isSameDay($day))
                ->map(fn (Shift $s) => [
                    'id' => $s->id,
                    'service_context_id' => $s->service_context_id,
                    'site' => $s->serviceContext?->name ?? $s->location ?? 'Shift',
                    'client_name' => trim(($s->client?->first_name ?? '').' '.($s->client?->last_name ?? '')) ?: null,
                    'shift_type' => $s->shift_type ?? 'standard',
                    'time' => $s->starts_at->format('H:i').'–'.($s->ends_at?->format('H:i') ?? '—'),
                    'starts_at' => $s->starts_at->toIso8601String(),
                    'ends_at' => $s->ends_at?->toIso8601String(),
                ])
                ->values()
                ->all();

            $weekRoster[] = [
                'day' => $day->isoFormat('ddd'),
                'date' => $day->format('j'),
                'today' => $day->isSameDay(now()),
                'shifts' => $dayShifts,
            ];
        }

        // Upcoming shifts for the next 3 days
        $upcomingShifts = Shift::where('user_id', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->where('starts_at', '>=', now())
            ->where('starts_at', '<=', now()->addDays(3))
            ->with('client:id,first_name,last_name', 'serviceContext:id,name')
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(fn ($shift) => [
                'id' => $shift->id,
                'starts_at' => $shift->starts_at?->format('Y-m-d H:i'),
                'ends_at' => $shift->ends_at?->format('Y-m-d H:i'),
                'shift_type' => $shift->shift_type ?? 'standard',
                'is_sleepover' => (bool) $shift->is_sleepover,
                'is_on_call' => (bool) $shift->is_on_call,
                'expected_break_minutes' => $shift->expected_break_minutes,
                'client_name' => trim(($shift->client?->first_name ?? '').' '.($shift->client?->last_name ?? '')),
                'location' => $shift->location,
                'service_context_name' => $shift->serviceContext?->name,
                'status' => $shift->status,
            ]);

        return Inertia::render('hr/my/time', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'weekRoster' => $weekRoster,
            'activeClock' => $activeClock ? [
                'id' => $activeClock->id,
                'clock_in' => $activeClock->clock_in->format('Y-m-d H:i'),
                'notes' => $activeClock->notes,
                'shift' => $activeClock->shift ? [
                    'id' => $activeClock->shift->id,
                    'starts_at' => $activeClock->shift->starts_at?->format('H:i'),
                    'ends_at' => $activeClock->shift->ends_at?->format('H:i'),
                    'shift_type' => $activeClock->shift->shift_type ?? 'standard',
                    'is_sleepover' => (bool) $activeClock->shift->is_sleepover,
                    'is_on_call' => (bool) $activeClock->shift->is_on_call,
                    'expected_break_minutes' => $activeClock->shift->expected_break_minutes,
                    'location' => $activeClock->shift->location,
                    'service_context_name' => $activeClock->shift->serviceContext?->name,
                    'client_name' => trim(($activeClock->shift->client?->first_name ?? '').' '.($activeClock->shift->client?->last_name ?? '')),
                ] : null,
            ] : null,
            'todayEntries' => $todayEntries,
            'weeklySummary' => $weeklySummary,
            'upcomingShifts' => $upcomingShifts,
        ]);
    }

    public function clockIn(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Guard against a second concurrent clock-in (which would leave two open
        // entries and a stuck "on shift" banner). One active clock at a time.
        $alreadyClockedIn = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->exists();

        if ($alreadyClockedIn) {
            return redirect()->back()->with('error', 'You are already clocked in.');
        }

        try {
            $session = $this->attendanceService->clockIn($user, [
                'tenant_id' => $tenantId,
                'shift_id' => $validated['shift_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'source' => 'self_service',
            ]);

            // Single owner of the HrTimeEntry payload + NZ break formula lives in
            // TimeTrackingService (de-forked from this controller, handoff §6).
            $this->timeTrackingService->syncEntryFromSession($session, $user, [
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Clocked in successfully.');
    }

    public function clockOut(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $requestedBreak = (int) ($validated['break_minutes'] ?? 0);

        // Close the live attendance session if there is one (this also creates
        // the Operations timesheet). A stale/seeded time entry can be open with
        // no matching session — that's fine: we still close the time entry below
        // so the hero's live clock always clears (never a phantom "on shift").
        $session = null;
        try {
            $session = $this->attendanceService->clockOut($user, null, [
                'break_minutes' => $requestedBreak,
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (\LogicException) {
            // No open attendance session — fall through and close the entry(ies).
        }

        $clockOutAt = $session?->clock_out_at ?? now();
        $breakMinutes = (int) ($session?->break_minutes ?? $requestedBreak);

        // Sync the session-linked entry (creating it for any legacy in-flight
        // session that predates the unified clock paths), then self-heal any
        // remaining orphaned actives — all through the one shared close path so
        // the NZ break formula is de-forked (handoff §6).
        if ($session) {
            $this->timeTrackingService->syncEntryFromSession($session, $user, [
                'mileage_km' => $validated['mileage_km'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
        }

        $closed = $this->timeTrackingService->closeOpenEntries(
            $user,
            $tenantId,
            $clockOutAt,
            $breakMinutes,
            $validated['mileage_km'] ?? null,
            $validated['notes'] ?? null,
        );

        if (! $session && $closed === 0) {
            return redirect()->back()->with('error', 'You are not currently clocked in.');
        }

        return redirect()->back()->with('success', 'Clocked out successfully.');
    }

    /** Download a single roster shift as a calendar (.ics) event. */
    public function shiftCalendar(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($shift->user_id === $user->id, 403);

        $start = $shift->starts_at?->copy()->utc();
        abort_unless($start, 404);
        $end = $shift->ends_at?->copy()->utc() ?? $start->copy()->addHours(8);

        $shift->loadMissing('serviceContext:id,name', 'client:id,first_name,last_name');
        $summary = $shift->serviceContext?->name ?? $shift->location ?? 'Shift';
        $client = trim(($shift->client?->first_name ?? '').' '.($shift->client?->last_name ?? ''));
        if ($client !== '') {
            $summary .= ' · '.$client;
        }
        $uid = 'shift-'.$shift->id.'@'.($request->getHost() ?: 'kauricare');
        $stamp = now()->utc()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Kauri Care//My HR//EN',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$stamp,
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->icsEscape($summary),
        ];
        if ($shift->location) {
            $lines[] = 'LOCATION:'.$this->icsEscape($shift->location);
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="shift-'.$shift->id.'.ics"',
        ]);
    }

    private function icsEscape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n'],
            $value,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  1:1s (Supervision — employee self-service, Phase 1) */
    /* ------------------------------------------------------------------ */

    public function one(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        // Phase 1 surfaces the existing HrSupervisionNote (employee-visible only).
        // Phase 2 (first-class shared agenda / action-item tables w/ carry-forward
        // + shared-vs-private notes) is deferred pending confirmation.
        $notes = HrSupervisionNote::forTenant($tenantId)
            ->forEmployee($user->id)
            ->where('is_visible_to_employee', true)
            ->with('supervisor:id,name')
            ->orderByDesc('session_date')
            ->get();

        $sessions = $notes->map(fn (HrSupervisionNote $n) => [
            'id' => $n->id,
            'session_date' => $n->session_date?->toDateString(),
            'session_type' => $n->session_type,
            'duration_minutes' => $n->duration_minutes,
            'supervisor' => $n->supervisor ? [
                'id' => $n->supervisor->id,
                'name' => $n->supervisor->name,
            ] : null,
            'topics_discussed' => $n->topics_discussed,
            'actions_agreed' => array_values($n->actions_agreed ?? []),
            'employee_comments' => $n->employee_comments,
            'employee_acknowledged' => (bool) $n->employee_acknowledged,
            'employee_acknowledged_at' => $n->employee_acknowledged_at?->toIso8601String(),
            'next_session_date' => $n->next_session_date?->toDateString(),
        ])->values();

        // Open actions = unchecked action strings from notes not yet acknowledged.
        $openActions = $notes
            ->reject(fn (HrSupervisionNote $n) => $n->employee_acknowledged)
            ->flatMap(fn (HrSupervisionNote $n) => collect($n->actions_agreed ?? [])->map(fn ($a) => [
                'note_id' => $n->id,
                'label' => $a,
                'from' => $n->supervisor?->name,
                'session_date' => $n->session_date?->toDateString(),
            ]))
            ->values();

        // Next 1:1 = soonest future next_session_date, with the most recent supervisor.
        $nextDate = $notes
            ->pluck('next_session_date')
            ->filter(fn ($d) => $d && $d->isFuture())
            ->sort()
            ->first();

        $next = $nextDate ? [
            'date' => $nextDate->toDateString(),
            'who' => $notes->firstWhere('next_session_date', $nextDate)?->supervisor?->name
                ?? $notes->first()?->supervisor?->name,
            'days_until' => (int) ceil(now()->startOfDay()->diffInDays($nextDate->startOfDay(), false)),
        ] : null;

        return Inertia::render('hr/my/one', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'sessions' => $sessions,
            'openActions' => $openActions,
            'next' => $next,
        ]);
    }

    public function acknowledgeOne(Request $request, HrSupervisionNote $note)
    {
        $user = $request->user();
        abort_unless($note->employee_user_id === $user->id, 403);
        abort_unless($note->is_visible_to_employee, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $note->tenant_id);

        $validated = $request->validate([
            'employee_comments' => ['nullable', 'string', 'max:5000'],
        ]);

        $note->update([
            'employee_acknowledged' => true,
            'employee_acknowledged_at' => now(),
            'employee_comments' => $validated['employee_comments'] ?? $note->employee_comments,
        ]);

        return redirect()->back()->with('success', 'Marked as reviewed.');
    }

    public function documents(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $profile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first();

        abort_unless($profile, 404, 'Employee profile not found.');

        $documents = HrDocument::where('employee_profile_id', $profile->id)
            ->where('is_restricted', false)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'category' => $d->category,
                'folder' => $d->folder ?? null,
                'original_name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size_bytes' => $d->size_bytes,
                'expires_at' => $d->expires_at?->toDateString(),
                'signed_by_employee' => (bool) $d->signed_by_employee,
                'created_at' => $d->created_at?->toIso8601String(),
            ]);

        // Documents awaiting this employee's e-signature (reuses ESignature flow).
        $pendingSignatures = HrDocumentSignature::forSigner($user->id)
            ->pending()
            ->with(['document:id,title,category', 'requestedBy:id,name'])
            ->orderBy('requested_at')
            ->get()
            ->map(fn (HrDocumentSignature $s) => [
                'id' => $s->id,
                'document_title' => $s->document?->title ?? 'Document',
                'document_category' => $s->document?->category,
                'requested_by' => $s->requestedBy?->name,
                'requested_at' => $s->requested_at?->toIso8601String(),
                'download_url' => route('hr.signatures.document', $s),
            ])
            ->values();

        return Inertia::render('hr/my/documents', [
            'myHr' => $this->myHrShellProps($user, $tenantId),
            'pendingSignatures' => $pendingSignatures,
            'documents' => $documents,
            'categories' => ['contract', 'letter', 'policy', 'certificate', 'offer', 'other'],
        ]);
    }

    /** Sign a document awaiting this employee's signature (self-service path that
     *  reuses the shared ESignatureService and stays on the My HR documents page). */
    public function signDocument(Request $request, HrDocumentSignature $signature)
    {
        $user = $request->user();
        abort_unless($signature->signer_user_id === $user->id, 403);

        $validated = $request->validate([
            'signature_data' => ['required', 'string', 'max:255'],
        ]);

        try {
            app(ESignatureService::class)->sign($signature, $validated['signature_data'], $request);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Document signed & filed.');
    }

    public function downloadDocument(Request $request, HrDocument $document)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $profile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first();

        abort_unless($profile, 404);
        abort_unless($document->employee_profile_id === $profile->id, 403);
        abort_unless(! $document->is_restricted, 403, 'This document is restricted.');

        abort_unless(
            Storage::disk($document->storage_disk)->exists($document->storage_path),
            404,
            'Document file is missing from storage.',
        );

        $filename = $document->original_name ?: basename($document->storage_path);

        return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);
    }

    /**
     * Month feed for the hero footer calendar. The shell seeds the current month
     * on first paint; this lets the popover page to other months on demand. Same
     * shape ({ month, events }) the shell uses, built by the shared trait.
     */
    public function calendar(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $monthParam = (string) $request->query('month', now()->format('Y-m'));

        try {
            $anchor = Carbon::createFromFormat('Y-m', $monthParam);
        } catch (\Throwable) {
            $anchor = null;
        }

        return response()->json(
            $this->myHrCalendarFeed($user, $tenantId, ($anchor ?: now())->startOfMonth()),
        );
    }
}
