<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class VettingController extends Controller
{
    use ResolvesHrTenant;

    /* ------------------------------------------------------------------ */
    /*  Index — vetting register                                           */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $staffUserIds = $this->hrStaffUserIdsForTenant($tenantId);

        $status = $request->query('status'); // 'clear', 'pending', 'flagged', 'expired', etc.
        $search = trim((string) $request->query('q', ''));

        // StaffBackgroundCheck is not tenant-aware; scope by tenant staff user IDs.
        $checks = StaffBackgroundCheck::with([
                'user:id,name,email',
                'verifiedBy:id,name',
            ])
            ->whereIn('user_id', $staffUserIds)
            ->when($status, fn ($q) => match ($status) {
                'expired'  => $q->expired(),
                'expiring' => $q->expiringSoon(60),
                'action'   => $q->requiringAction(),
                default    => $q->where('status', $status),
            })
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Summary counts
        $baseQuery = StaffBackgroundCheck::query()->whereIn('user_id', $staffUserIds);
        $summary = [
            'total'     => (clone $baseQuery)->count(),
            'clear'     => (clone $baseQuery)->where('status', 'clear')->count(),
            'pending'   => (clone $baseQuery)->where('status', 'pending')->count(),
            'flagged'   => (clone $baseQuery)->where('status', 'flagged')->count(),
            'expired'   => (clone $baseQuery)->expired()->count(),
            'expiring'  => (clone $baseQuery)->expiringSoon(60)->count(),
        ];

        return Inertia::render('hr/vetting/index', [
            'checks' => $checks,
            'summary' => $summary,
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'manage' => $user->canDo('hr.vetting.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — check detail                                                */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertStaffCheckTenantAccess($tenantId, $check);
        $check->load([
            'user:id,name,email',
            'riskAssessor:id,name',
            'verifiedBy:id,name',
            'creator:id,name',
            'updater:id,name',
        ]);

        return Inertia::render('hr/vetting/show', [
            'check' => $check,
            'can' => [
                'manage' => $user->canDo('hr.vetting.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create — show form to create background check                      */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);

        $staff = User::staff()
            ->whereIn('id', $staffIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/vetting/create', [
            'staff' => $staff,
            'checkTypes' => [
                ['value' => 'police_check', 'label' => 'Police Check'],
                ['value' => 'ministry_of_justice', 'label' => 'Ministry of Justice'],
                ['value' => 'vulnerable_children_act', 'label' => 'Vulnerable Children Act'],
                ['value' => 'identity_verification', 'label' => 'Identity Verification'],
                ['value' => 'qualification_verification', 'label' => 'Qualification Verification'],
                ['value' => 'right_to_work', 'label' => 'Right to Work'],
                ['value' => 'credit_check', 'label' => 'Credit Check'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Edit — show form to edit background check                          */
    /* ------------------------------------------------------------------ */

    public function edit(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertStaffCheckTenantAccess($tenantId, $check);

        $check->load(['user:id,name,email']);
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);

        $staff = User::staff()
            ->whereIn('id', $staffIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/vetting/edit', [
            'check' => $check,
            'staff' => $staff,
            'checkTypes' => [
                ['value' => 'police_check', 'label' => 'Police Check'],
                ['value' => 'ministry_of_justice', 'label' => 'Ministry of Justice'],
                ['value' => 'vulnerable_children_act', 'label' => 'Vulnerable Children Act'],
                ['value' => 'identity_verification', 'label' => 'Identity Verification'],
                ['value' => 'qualification_verification', 'label' => 'Qualification Verification'],
                ['value' => 'right_to_work', 'label' => 'Right to Work'],
                ['value' => 'credit_check', 'label' => 'Credit Check'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'statuses' => [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'requested', 'label' => 'Requested'],
                ['value' => 'clear', 'label' => 'Clear'],
                ['value' => 'flagged', 'label' => 'Flagged'],
                ['value' => 'adverse', 'label' => 'Adverse'],
                ['value' => 'renewal_due', 'label' => 'Renewal Due'],
            ],
            'riskDecisions' => [
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'conditional', 'label' => 'Conditional'],
                ['value' => 'declined', 'label' => 'Declined'],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — create new background check                                */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'user_id'          => ['required', 'integer', 'exists:users,id'],
            'check_type'       => ['required', 'string', Rule::in([
                'police_check', 'ministry_of_justice', 'vulnerable_children_act',
                'identity_verification', 'qualification_verification',
                'right_to_work', 'credit_check', 'other',
            ])],
            'provider'         => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'check_date'       => ['nullable', 'date'],
            'notes'            => ['nullable', 'string', 'max:5000'],
        ]);

        $belongsToTenant = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $validated['user_id'])
            ->exists();
        if (! $belongsToTenant) {
            return redirect()->back()->with('error', 'Selected staff member is not in your HR tenant scope.');
        }

        StaffBackgroundCheck::create([
            ...$validated,
            'status'     => 'pending',
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Background check initiated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update — update check details, results, risk assessment            */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertStaffCheckTenantAccess($tenantId, $check);

        $validated = $request->validate([
            'status'                => ['sometimes', 'required', 'string', Rule::in([
                'pending', 'requested', 'clear', 'flagged', 'adverse', 'renewal_due',
            ])],
            'reference_number'      => ['nullable', 'string', 'max:100'],
            'provider'              => ['nullable', 'string', 'max:255'],
            'check_date'            => ['nullable', 'date'],
            'issue_date'            => ['nullable', 'date'],
            'expires_at'            => ['nullable', 'date', 'after_or_equal:issue_date'],
            'disclosures_present'   => ['sometimes', 'boolean'],
            'disclosure_details'    => ['nullable', 'string', 'max:5000'],
            'conditions'            => ['nullable', 'string', 'max:2000'],
            'risk_assessed'         => ['sometimes', 'boolean'],
            'risk_assessment'       => ['nullable', 'string', 'max:5000'],
            'risk_decision'         => ['nullable', 'string', Rule::in(['approved', 'conditional', 'declined'])],
            'certificate_path'      => ['nullable', 'string', 'max:500'],
            'enrolled_in_update_service' => ['sometimes', 'boolean'],
            'update_service_reference'   => ['nullable', 'string', 'max:100'],
            'notes'                 => ['nullable', 'string', 'max:5000'],
        ]);

        // If risk assessed, record assessor and timestamp
        if (! empty($validated['risk_assessed']) && ! $check->risk_assessed) {
            $validated['risk_assessor_id'] = $user->id;
            $validated['risk_assessed_at'] = now();
        }

        $validated['updated_by'] = $user->id;

        // If status set to clear and no verified_at, auto-verify
        if (($validated['status'] ?? null) === 'clear' && ! $check->verified_at) {
            $validated['verified_by_user_id'] = $user->id;
            $validated['verified_at'] = now();
        }

        $check->update($validated);

        return redirect()->back()->with('success', 'Background check updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy — delete a background check                                */
    /* ------------------------------------------------------------------ */

    public function destroy(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertStaffCheckTenantAccess($tenantId, $check);

        $check->delete();

        return redirect()->route('hr.vetting.index')->with('success', 'Background check deleted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Clear — mark a background check as clear                           */
    /* ------------------------------------------------------------------ */

    public function clear(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertStaffCheckTenantAccess($tenantId, $check);

        $check->update([
            'status' => 'clear',
            'verified_by_user_id' => $user->id,
            'verified_at' => now(),
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Background check marked as clear.');
    }

    /* ------------------------------------------------------------------ */
    /*  Renew — mark a background check for renewal                        */
    /* ------------------------------------------------------------------ */

    public function renew(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertStaffCheckTenantAccess($tenantId, $check);

        $check->update([
            'status' => 'renewal_due',
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Background check marked for renewal.');
    }

    /* ------------------------------------------------------------------ */
    /*  Capture Consent — record privacy/consent for NZ vetting            */
    /* ------------------------------------------------------------------ */

    public function captureConsent(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertStaffCheckTenantAccess($tenantId, $check);

        $validated = $request->validate([
            'consent_given' => ['required', 'boolean', 'accepted'],
            'consent_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $check->update([
            'notes'      => trim(($check->notes ?? '') . "\n\n[Consent recorded " . now()->toDateTimeString() . '] '
                . ($validated['consent_notes'] ?? 'Staff member gave consent for background check.')),
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Consent recorded successfully.');
    }

    private function assertStaffCheckTenantAccess(int $tenantId, StaffBackgroundCheck $check): void
    {
        $belongsToTenant = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $check->user_id)
            ->exists();

        if (! $belongsToTenant) {
            abort(404);
        }
    }
}
