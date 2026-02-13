<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrDriverEligibility;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DriverEligibilityController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Index — driver eligibility register                                */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.view'), 403);

        $tenantId = null;
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $records = HrDriverEligibility::with([
                'user:id,name,email',
                'approvedBy:id,name',
            ])
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
        $base = HrDriverEligibility::query();
        $summary = [
            'total'     => (clone $base)->count(),
            'eligible'  => (clone $base)->eligible()->count(),
            'expiring'  => (clone $base)->expiring(30)->count(),
            'suspended' => (clone $base)->where('status', 'suspended')->count(),
            'pending'   => (clone $base)->where('status', 'pending_review')->count(),
        ];

        return Inertia::render('hr/drivers/index', [
            'records' => $records,
            'summary' => $summary,
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
    /*  Store — create new driver eligibility record                       */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);

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

        // Check for existing record
        $existing = HrDriverEligibility::where('user_id', $validated['user_id'])
            ->first();

        if ($existing) {
            return redirect()->back()->with('error', 'A driver eligibility record already exists for this staff member.');
        }

        HrDriverEligibility::create([
            ...$validated,
            'tenant_id'       => $user->tenant_id,
            'status'          => 'pending_review',
            'can_drive_clients' => false,
            'next_review_at'  => now()->addMonths(12),
            'created_by'      => $user->id,
        ]);

        return redirect()->back()->with('success', 'Driver eligibility record created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update — update existing record                                    */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);

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

        return redirect()->back()->with('success', 'Driver eligibility record updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve — approve driver to transport clients                      */
    /* ------------------------------------------------------------------ */

    public function approve(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);

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
        $employeeProfile = \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $eligibility->user_id)
            ->first();

        if ($employeeProfile) {
            $employeeProfile->update([
                'can_drive_clients' => true,
                'driver_eligibility_reviewed_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Driver approved to transport clients.');
    }

    /* ------------------------------------------------------------------ */
    /*  Suspend — suspend driving privileges                               */
    /* ------------------------------------------------------------------ */

    public function suspend(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);

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
        $employeeProfile = \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $eligibility->user_id)
            ->first();

        if ($employeeProfile) {
            $employeeProfile->update([
                'can_drive_clients' => false,
                'driver_eligibility_reviewed_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Driving privileges suspended.');
    }
}
