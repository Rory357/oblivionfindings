<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsComplianceHero;
use App\Http\Controllers\Hr\Concerns\ProvidesComplianceWizardData;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DriverEligibilityController extends Controller
{
    use BuildsComplianceHero;
    use ProvidesComplianceWizardData;
    use ResolvesHrTenant;

    /* ------------------------------------------------------------------ */
    /*  Index — driver eligibility register                                */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $records = HrDriverEligibility::with([
                'user:id,name,email',
                'approvedBy:id,name',
            ])
            ->where('tenant_id', $tenantId)
            ->when($status, fn ($q) => match ($status) {
                'eligible'  => $q->eligible(),
                'expiring'  => $q->expiring(30),
                default     => $q->where('status', $status),
            })
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Summary
        $base = HrDriverEligibility::query()->where('tenant_id', $tenantId);
        $summary = [
            'total'     => (clone $base)->count(),
            'eligible'  => (clone $base)->eligible()->count(),
            'expiring'  => (clone $base)->expiring(30)->count(),
            'suspended' => (clone $base)->where('status', 'suspended')->count(),
            'pending'   => (clone $base)->where('status', 'pending_review')->count(),
        ];

        $employees = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'position_title'])
            ->map(fn ($p) => [
                'user_id' => $p->user_id,
                'name' => $p->user?->name ?? ('Profile #'.$p->id),
                'position_title' => $p->position_title,
            ])
            ->values();

        return Inertia::render('hr/drivers/index', [
            'hero' => $this->complianceHero($user, $tenantId),
            'records' => $records,
            'summary' => $summary,
            'employees' => $employees,
            'wizard' => $this->complianceWizardData($tenantId),
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'manage' => $user->canDo('hr.driver.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — driver licence detail page                                  */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $eligibility->tenant_id);

        $eligibility->load(['user:id,name,email', 'approvedBy:id,name']);

        $isExpired = $eligibility->licence_expires_at && $eligibility->licence_expires_at->isPast();

        // Build a lightweight history timeline from the record's own stamps.
        $history = collect();
        if ($eligibility->created_at) {
            $history->push(['title' => 'Record created', 'date' => $eligibility->created_at->toDayDateTimeString(), 'tone' => 'neutral']);
        }
        if ($eligibility->can_drive_clients_approved_at) {
            $history->push([
                'title' => 'Approved for driving shifts',
                'date' => $eligibility->can_drive_clients_approved_at->toDayDateTimeString()
                    . ($eligibility->approvedBy ? ' · ' . $eligibility->approvedBy->name : ''),
                'tone' => 'success',
            ]);
        }
        if ($eligibility->status === 'suspended') {
            $history->push([
                'title' => 'Suspended' . ($eligibility->suspension_reason ? ' — ' . $eligibility->suspension_reason : ''),
                'date' => optional($eligibility->last_reviewed_at ?? $eligibility->updated_at)->toDayDateTimeString(),
                'tone' => 'critical',
            ]);
        }
        if ($isExpired) {
            $history->push(['title' => 'Licence expired', 'date' => $eligibility->licence_expires_at->toFormattedDateString(), 'tone' => 'critical']);
        }

        return Inertia::render('hr/drivers/show', [
            'driver' => [
                'id' => $eligibility->id,
                'user_id' => $eligibility->user_id,
                'name' => $eligibility->user?->name ?? 'Unknown',
                'email' => $eligibility->user?->email,
                'licence_number' => $eligibility->licence_number,
                'licence_class' => $eligibility->licence_class,
                'licence_endorsements' => $eligibility->licence_endorsements ?? [],
                'licence_expires_at' => optional($eligibility->licence_expires_at)->toDateString(),
                'incident_free_since' => optional($eligibility->incident_free_since)->toDateString(),
                'can_drive_clients' => (bool) $eligibility->can_drive_clients,
                'status' => $isExpired ? 'expired' : $eligibility->status,
                'raw_status' => $eligibility->status,
                'suspension_reason' => $eligibility->suspension_reason,
                'notes' => $eligibility->notes,
                'last_reviewed_at' => optional($eligibility->last_reviewed_at)->toDateString(),
                'next_review_at' => optional($eligibility->next_review_at)->toDateString(),
            ],
            'history' => $history->sortBy('date')->values(),
            'can' => [
                'manage' => $user->canDo('hr.driver.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — create new driver eligibility record                       */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'user_id'              => ['required', 'integer', 'exists:users,id'],
            'licence_number'       => ['required', 'string', 'max:50'],
            'licence_class'        => ['required', 'string', 'max:20'],
            'licence_endorsements' => ['nullable', 'array'],
            'licence_endorsements.*' => ['string', 'max:50'],
            'licence_expires_at'   => ['required', 'date', 'after:today'],
            'licence_document_path' => ['nullable', 'string', 'max:500'],
            'incident_free_since'  => ['nullable', 'date', 'before_or_equal:today'],
            'notes'                => ['nullable', 'string', 'max:5000'],
        ]);

        $belongsToTenant = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $validated['user_id'])
            ->exists();

        if (! $belongsToTenant) {
            return redirect()->back()->with('error', 'Selected staff member is not in your HR tenant scope.');
        }

        // Check for existing record
        $existing = HrDriverEligibility::where('user_id', $validated['user_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if ($existing) {
            return redirect()->back()->with('error', 'A driver eligibility record already exists for this staff member.');
        }

        HrDriverEligibility::create([
            ...$validated,
            'tenant_id'       => $tenantId,
            'status'          => 'pending_review',
            'can_drive_clients' => false,
            'next_review_at'  => now()->addMonths(12),
            'created_by'      => $user->id,
        ]);

        $this->reevaluateCompliance($validated['user_id']);

        return redirect()->back()->with('success', 'Driver eligibility record created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update — update existing record                                    */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $eligibility->tenant_id);

        $validated = $request->validate([
            'licence_number'       => ['sometimes', 'required', 'string', 'max:50'],
            'licence_class'        => ['sometimes', 'required', 'string', 'max:20'],
            'licence_endorsements' => ['nullable', 'array'],
            'licence_endorsements.*' => ['string', 'max:50'],
            'licence_expires_at'   => ['sometimes', 'required', 'date'],
            'licence_document_path' => ['nullable', 'string', 'max:500'],
            'incident_free_since'  => ['nullable', 'date', 'before_or_equal:today'],
            'next_review_at'       => ['nullable', 'date'],
            'notes'                => ['nullable', 'string', 'max:5000'],
        ]);

        $validated['updated_by'] = $user->id;
        $validated['last_reviewed_at'] = now();
        $eligibility->update($validated);

        $this->reevaluateCompliance($eligibility->user_id);

        return redirect()->back()->with('success', 'Driver eligibility record updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve — approve driver to transport clients                      */
    /* ------------------------------------------------------------------ */

    public function approve(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $eligibility->tenant_id);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($eligibility->licence_expires_at && $eligibility->licence_expires_at->isPast()) {
            return redirect()->back()->with('error', 'Cannot approve: licence has expired.');
        }

        $eligibility->update([
            'status'                     => 'eligible',
            'can_drive_clients'          => true,
            'can_drive_clients_approved_by' => $user->id,
            'can_drive_clients_approved_at' => now(),
            'last_reviewed_at'           => now(),
            'next_review_at'             => now()->addMonths(12),
            'notes'                      => $validated['notes'] ?? $eligibility->notes,
            'updated_by'                 => $user->id,
        ]);

        // Also update the employee profile flag
        $employeeProfile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $eligibility->user_id)
            ->first();

        if ($employeeProfile) {
            $employeeProfile->update([
                'can_drive_clients' => true,
                'driver_eligibility_reviewed_at' => now(),
            ]);
        }

        $this->reevaluateCompliance($eligibility->user_id);

        return redirect()->back()->with('success', 'Driver approved to transport clients.');
    }

    /* ------------------------------------------------------------------ */
    /*  Suspend — suspend driving privileges                               */
    /* ------------------------------------------------------------------ */

    public function suspend(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $eligibility->tenant_id);

        $validated = $request->validate([
            'suspension_reason' => ['required', 'string', 'max:2000'],
        ]);

        $eligibility->update([
            'status'            => 'suspended',
            'can_drive_clients' => false,
            'suspension_reason' => $validated['suspension_reason'],
            'last_reviewed_at'  => now(),
            'updated_by'        => $user->id,
        ]);

        // Also update the employee profile flag
        $employeeProfile = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $eligibility->user_id)
            ->first();

        if ($employeeProfile) {
            $employeeProfile->update([
                'can_drive_clients' => false,
                'driver_eligibility_reviewed_at' => now(),
            ]);
        }

        $this->reevaluateCompliance($eligibility->user_id);

        return redirect()->back()->with('success', 'Driving privileges suspended.');
    }

    /**
     * Refresh the driver's cached compliance so any driver_licence hard-stop
     * reflects the licence change immediately (not just at the nightly sweep).
     */
    private function reevaluateCompliance(int $userId): void
    {
        $user = User::find($userId);
        if ($user) {
            app(ComplianceMatrixService::class)->evaluateStaff($user);
        }
    }
}
