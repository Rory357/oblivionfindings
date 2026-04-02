<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Hr\Services\EngagementService;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MyHrController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly LeaveService $leaveService,
        private readonly EngagementService $engagementService,
        private readonly TimeTrackingService $timeTrackingService,
        private readonly AttendanceService $attendanceService,
    ) {}

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

        // Time tracking
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

        // Announcements (unacknowledged, active)
        $announcements = HrAnnouncement::forTenant($tenantId)
            ->active()
            ->whereDoesntHave('acknowledgements', fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('published_at')
            ->limit(5)
            ->get(['id', 'title', 'priority', 'published_at']);

        return Inertia::render('hr/my/index', [
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
            'requests' => $requests,
            'balances' => $balances,
            'leaveTypes' => LeaveService::LEAVE_TYPES,
        ]);
    }

    public function submitLeave(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'leave_type' => ['required', 'string', Rule::in(LeaveService::LEAVE_TYPES)],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'hours_requested' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'supporting_doc' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ]);

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
            'complianceStatuses' => $complianceStatuses,
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
    /*  Performance Reviews                                                */
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
    /*  Development Goals                                                  */
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
    /*  Engagement Surveys                                                 */
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
                $respondentHash = hash_hmac('sha256', $survey->id . ':' . $user->id, (string) config('app.key'));

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
    /*  Time Tracking (Self-Service)                                       */
    /* ------------------------------------------------------------------ */

    public function time(Request $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $activeClock = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->first(['id', 'clock_in', 'notes', 'shift_id']);

        $todayEntries = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->where('entry_date', now()->toDateString())
            ->with('shift:id,starts_at,ends_at', 'shift.client:id,first_name,last_name', 'client:id,first_name,last_name')
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
                    'client_name' => trim(($entry->shift->client?->first_name ?? '') . ' ' . ($entry->shift->client?->last_name ?? '')),
                ] : null,
                'client_name' => $entry->client
                    ? trim(($entry->client->first_name ?? '') . ' ' . ($entry->client->last_name ?? ''))
                    : null,
            ]);

        $weeklySummary = $this->timeTrackingService->getWeeklySummary($tenantId, $user->id);

        // Upcoming shifts for the next 3 days
        $upcomingShifts = Shift::where('user_id', $user->id)
            ->whereIn('status', ['scheduled', 'draft'])
            ->where('starts_at', '>=', now())
            ->where('starts_at', '<=', now()->addDays(3))
            ->with('client:id,first_name,last_name')
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(fn ($shift) => [
                'id' => $shift->id,
                'starts_at' => $shift->starts_at?->format('Y-m-d H:i'),
                'ends_at' => $shift->ends_at?->format('Y-m-d H:i'),
                'shift_type' => $shift->shift_type ?? 'standard',
                'client_name' => trim(($shift->client?->first_name ?? '') . ' ' . ($shift->client?->last_name ?? '')),
                'location' => $shift->location,
                'status' => $shift->status,
            ]);

        return Inertia::render('hr/my/time', [
            'activeClock' => $activeClock ? [
                'id' => $activeClock->id,
                'clock_in' => $activeClock->clock_in->format('Y-m-d H:i'),
                'notes' => $activeClock->notes,
            ] : null,
            'todayEntries' => $todayEntries,
            'weeklySummary' => $weeklySummary,
            'upcomingShifts' => $upcomingShifts,
        ]);
    }

    public function clockIn(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $session = $this->attendanceService->clockIn($user, [
                'shift_id' => $validated['shift_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'source' => 'self_service',
            ]);

            // Create corresponding HrTimeEntry
            HrTimeEntry::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'shift_id' => $session->shift_id,
                'attendance_session_id' => $session->id,
                'site_id' => $session->site_id,
                'client_id' => $session->shift?->client_id,
                'entry_date' => $session->clock_in_at->toDateString(),
                'clock_in' => $session->clock_in_at,
                'entry_type' => 'clock',
                'status' => 'active',
                'source_type' => 'attendance',
                'source_id' => $session->id,
                'pay_type' => $session->shift?->is_sleepover ? 'sleepover' : ($session->shift?->is_on_call ? 'on_call' : 'standard'),
                'is_sleepover' => (bool) $session->shift?->is_sleepover,
                'is_on_call' => (bool) $session->shift?->is_on_call,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Clocked in successfully.');
    }

    public function clockOut(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $session = $this->attendanceService->clockOut($user, null, [
                'break_minutes' => $validated['break_minutes'] ?? 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update corresponding HrTimeEntry
            $entry = HrTimeEntry::where('attendance_session_id', $session->id)
                ->where('user_id', $user->id)
                ->first();

            if ($entry) {
                $totalMinutes = $session->clock_in_at->diffInMinutes($session->clock_out_at) - ($session->break_minutes ?? 0);
                $totalHours = max(0, round($totalMinutes / 60, 2));

                // Check break compliance (NZ: 10min rest per 2h, 30min meal per 4h)
                $workedHours = $totalMinutes / 60;
                $breakMinutes = $session->break_minutes ?? 0;
                $requiredBreak = 0;
                if ($workedHours >= 4) {
                    $requiredBreak = 30;
                } elseif ($workedHours >= 2) {
                    $requiredBreak = 10;
                }
                $breakCompliant = $breakMinutes >= $requiredBreak;

                $entry->update([
                    'clock_out' => $session->clock_out_at,
                    'break_minutes' => $session->break_minutes ?? 0,
                    'total_hours' => $totalHours,
                    'mileage_km' => $validated['mileage_km'] ?? null,
                    'break_compliance_met' => $breakCompliant,
                    'notes' => $validated['notes'] ?? $entry->notes,
                    'status' => 'submitted',
                ]);
            }
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Clocked out successfully.');
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

        return Inertia::render('hr/my/documents', [
            'documents' => $documents,
            'categories' => ['contract', 'letter', 'policy', 'certificate', 'offer', 'other'],
        ]);
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
        abort_unless(!$document->is_restricted, 403, 'This document is restricted.');

        abort_unless(
            Storage::disk($document->storage_disk)->exists($document->storage_path),
            404,
            'Document file is missing from storage.',
        );

        $filename = $document->original_name ?: basename($document->storage_path);

        return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);
    }
}
