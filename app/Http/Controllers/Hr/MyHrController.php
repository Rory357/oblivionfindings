<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MyHrController extends Controller
{
    public function __construct(
        private readonly LeaveService $leaveService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = null;

        $profile = HrEmployeeProfile::where('user_id', $user->id)
            ->first();

        $pendingLeave = HrLeaveRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $leaveBalances = HrLeaveBalance::where('user_id', $user->id)
            ->where('year', now()->year)
            ->get();

        $complianceStatuses = HrStaffComplianceStatus::where('user_id', $user->id)
            ->with('requirement:id,code,name,category')
            ->get();

        $complianceSummary = [
            'compliant' => $complianceStatuses->where('status', 'compliant')->count(),
            'expiring_soon' => $complianceStatuses->where('status', 'expiring_soon')->count(),
            'expired' => $complianceStatuses->where('status', 'expired')->count(),
            'not_started' => $complianceStatuses->where('status', 'not_started')->count(),
        ];

        $policiesDue = HrPolicy::active()
            ->where('requires_attestation', true)
            ->whereDoesntHave('attestations', fn ($q) => $q->where('user_id', $user->id))
            ->count();

        return Inertia::render('hr/my/index', [
            'profile' => $profile,
            'pendingLeave' => $pendingLeave,
            'leaveBalances' => $leaveBalances,
            'complianceSummary' => $complianceSummary,
            'complianceStatuses' => $complianceStatuses,
            'policiesDue' => $policiesDue,
        ]);
    }

    public function leave(Request $request)
    {
        $user = $request->user();
        $tenantId = null;

        $requests = HrLeaveRequest::where('user_id', $user->id)
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

        $balances = HrLeaveBalance::where('user_id', $user->id)
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
            'hours_requested' => ['required', 'numeric', 'min:0.5', 'max:999'],
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
        abort_unless($leaveRequest->status === 'pending', 422);

        $leaveRequest->update([
            'status' => 'cancelled',
        ]);

        return redirect()->back()->with('success', 'Leave request cancelled.');
    }

    public function training(Request $request)
    {
        $user = $request->user();
        $tenantId = null;

        $complianceStatuses = HrStaffComplianceStatus::where('user_id', $user->id)
            ->with('requirement:id,code,name,category,validity_months')
            ->orderBy('status')
            ->get();

        return Inertia::render('hr/my/training', [
            'complianceStatuses' => $complianceStatuses,
        ]);
    }

    public function policies(Request $request)
    {
        $user = $request->user();
        $tenantId = null;

        $policies = HrPolicy::active()
            ->where('requires_attestation', true)
            ->with(['versions' => fn ($q) => $q->where('is_current', true)])
            ->orderBy('title')
            ->get()
            ->map(function ($policy) use ($user) {
                $attestation = HrPolicyAttestation::where('policy_id', $policy->id)
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

        HrPolicyAttestation::create([
            'tenant_id' => $user->tenant_id ?? $user->organization_id ?? 1,
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
        $tenantId = null;

        $profile = HrEmployeeProfile::where('user_id', $user->id)
            ->first();

        return Inertia::render('hr/my/profile', [
            'profile' => $profile,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $tenantId = null;

        $profile = HrEmployeeProfile::where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'personal_email' => ['nullable', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:50'],
            'home_address' => ['nullable', 'string', 'max:1000'],
            'emergency_contacts' => ['nullable', 'array'],
        ]);

        $validated['updated_by'] = $user->id;
        $profile->update($validated);

        return redirect()->back()->with('success', 'Profile updated.');
    }
}
