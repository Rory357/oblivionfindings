<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Enums\ComplianceExportDataset;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsComplianceHero;
use App\Http\Controllers\Hr\Concerns\ProvidesComplianceWizardData;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DriverEligibilityController extends Controller
{
    use BuildsComplianceHero;
    use ProvidesComplianceWizardData;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly PeopleMutationLockService $mutationLocks,
        private readonly ComplianceMatrixService $compliance,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — driver eligibility register */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.view'), 403);

        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $records = HrDriverEligibility::with([
            'user:id,name,email',
            'approvedBy:id,name',
        ])
            ->whereIn('user_id', $this->visibleStaffQuery($user)->select('users.id'))
            ->when($status, fn ($q) => match ($status) {
                'eligible' => $q->eligible(),
                'expiring' => $q->expiring(30),
                default => $q->where('status', $status),
            })
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Summary
        $base = HrDriverEligibility::query()
            ->whereIn('user_id', $this->visibleStaffQuery($user)->select('users.id'));
        $summary = [
            'total' => (clone $base)->count(),
            'eligible' => (clone $base)->eligible()->count(),
            'expiring' => (clone $base)->expiring(30)->count(),
            'suspended' => (clone $base)->where('status', 'suspended')->count(),
            'pending' => (clone $base)->where('status', 'pending_review')->count(),
        ];

        $employees = $this->visibleStaffQuery($user)
            ->with('hrEmployeeProfile:id,user_id,position_title')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $staff) => [
                'user_id' => $staff->id,
                'name' => $staff->name,
                'position_title' => $staff->hrEmployeeProfile?->position_title,
            ])
            ->values();

        return Inertia::render('hr/drivers/index', [
            'hero' => $this->complianceHero($user),
            'records' => $records,
            'summary' => $summary,
            'employees' => $employees,
            'wizard' => $this->complianceWizardData($user),
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'export' => ComplianceExportDataset::Drivers->allows($user),
                'manage' => $user->canDo('hr.driver.manage'),
                // The shared hub header offers cross-domain create actions;
                // gate each on the real manage perm so it isn't advertised to
                // someone who'll only hit a 403 on submit.
                'compliance_manage' => $user->canDo('hr.compliance.manage'),
                'vetting_manage' => $user->canDo('hr.vetting.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — driver licence detail page */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.view'), 403);
        $eligibility = $this->visibleEligibility($user, (int) $eligibility->getKey());

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
                    .($eligibility->approvedBy ? ' · '.$eligibility->approvedBy->name : ''),
                'tone' => 'success',
            ]);
        }
        if ($eligibility->status === 'suspended') {
            $history->push([
                'title' => 'Suspended'.($eligibility->suspension_reason ? ' — '.$eligibility->suspension_reason : ''),
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
    /*  Store — create new driver eligibility record */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'licence_number' => ['required', 'string', 'max:50'],
            'licence_class' => ['required', 'string', 'max:20'],
            'licence_endorsements' => ['nullable', 'array'],
            'licence_endorsements.*' => ['string', 'max:50'],
            'licence_expires_at' => ['required', 'date', 'after:today'],
            'licence_document_path' => ['nullable', 'string', 'max:500'],
            'incident_free_since' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $targetId = (int) $validated['user_id'];
        $this->assertVisibleStaffSelection($user, $targetId);

        DB::transaction(function () use ($user, $validated, $targetId): void {
            $locked = $this->mutationLocks->lock([$user->id, $targetId]);
            $lockedActor = $locked['users']->get($user->id);
            abort_unless($lockedActor instanceof User && $lockedActor->canDo('hr.driver.manage'), 403);
            $this->assertVisibleStaffSelection($lockedActor, $targetId, new UserSiteAccessService);

            if (HrDriverEligibility::query()->where('user_id', $targetId)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => 'A driver eligibility record already exists for this staff member.',
                ]);
            }

            HrDriverEligibility::create([
                ...$validated,
                'status' => 'pending_review',
                'can_drive_clients' => false,
                'next_review_at' => now()->addMonths(12),
                'created_by' => $lockedActor->id,
            ]);
        });

        $this->reevaluateCompliance($targetId);

        return redirect()->back()->with('success', 'Driver eligibility record created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update — update existing record */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);
        $eligibility = $this->visibleEligibility($user, (int) $eligibility->getKey());

        $validated = $request->validate([
            'licence_number' => ['sometimes', 'required', 'string', 'max:50'],
            'licence_class' => ['sometimes', 'required', 'string', 'max:20'],
            'licence_endorsements' => ['nullable', 'array'],
            'licence_endorsements.*' => ['string', 'max:50'],
            'licence_expires_at' => ['sometimes', 'required', 'date'],
            'licence_document_path' => ['nullable', 'string', 'max:500'],
            'incident_free_since' => ['nullable', 'date', 'before_or_equal:today'],
            'next_review_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $targetId = (int) $eligibility->user_id;
        DB::transaction(function () use ($user, $eligibility, $validated): void {
            [$lockedActor, $lockedEligibility] = $this->lockVisibleEligibility($user, (int) $eligibility->getKey());

            $lockedEligibility->fill($validated);
            $approvalEvidenceChanged = $lockedEligibility->isDirty([
                'licence_number',
                'licence_class',
                'licence_endorsements',
                'licence_expires_at',
                'licence_document_path',
            ]);
            if ($approvalEvidenceChanged) {
                $lockedEligibility->forceFill([
                    'status' => $lockedEligibility->status === 'suspended'
                        ? 'suspended'
                        : ($lockedEligibility->licence_expires_at?->isPast() ? 'expired' : 'pending_review'),
                    'can_drive_clients' => false,
                ]);
            }

            $lockedEligibility->forceFill([
                'updated_by' => $lockedActor->id,
                'last_reviewed_at' => now(),
            ])->save();

            if ($approvalEvidenceChanged) {
                HrEmployeeProfile::query()
                    ->where('user_id', $lockedEligibility->user_id)
                    ->update([
                        'can_drive_clients' => false,
                        'driver_eligibility_reviewed_at' => now(),
                    ]);
            }
        });

        $this->reevaluateCompliance($targetId);

        return redirect()->back()->with('success', 'Driver eligibility record updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve — approve driver to transport clients */
    /* ------------------------------------------------------------------ */

    public function approve(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);
        $eligibility = $this->visibleEligibility($user, (int) $eligibility->getKey());

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $targetId = (int) $eligibility->user_id;
        DB::transaction(function () use ($user, $eligibility, $validated): void {
            [$lockedActor, $lockedEligibility] = $this->lockVisibleEligibility($user, (int) $eligibility->getKey());
            if ($lockedEligibility->licence_expires_at && $lockedEligibility->licence_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'licence_expires_at' => 'The licence has expired and cannot be approved.',
                ]);
            }

            $lockedEligibility->update([
                'status' => 'eligible',
                'can_drive_clients' => true,
                'can_drive_clients_approved_by' => $lockedActor->id,
                'can_drive_clients_approved_at' => now(),
                'last_reviewed_at' => now(),
                'next_review_at' => now()->addMonths(12),
                'notes' => $validated['notes'] ?? $lockedEligibility->notes,
                'updated_by' => $lockedActor->id,
            ]);

            HrEmployeeProfile::query()
                ->where('user_id', $lockedEligibility->user_id)
                ->update([
                    'can_drive_clients' => true,
                    'driver_eligibility_reviewed_at' => now(),
                ]);
        });

        $this->reevaluateCompliance($targetId);

        return redirect()->back()->with('success', 'Driver approved to transport clients.');
    }

    /* ------------------------------------------------------------------ */
    /*  Suspend — suspend driving privileges */
    /* ------------------------------------------------------------------ */

    public function suspend(Request $request, HrDriverEligibility $eligibility)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.driver.manage'), 403);
        $eligibility = $this->visibleEligibility($user, (int) $eligibility->getKey());

        $validated = $request->validate([
            'suspension_reason' => ['required', 'string', 'max:2000'],
        ]);

        $targetId = (int) $eligibility->user_id;
        DB::transaction(function () use ($user, $eligibility, $validated): void {
            [$lockedActor, $lockedEligibility] = $this->lockVisibleEligibility($user, (int) $eligibility->getKey());
            $lockedEligibility->update([
                'status' => 'suspended',
                'can_drive_clients' => false,
                'suspension_reason' => $validated['suspension_reason'],
                'last_reviewed_at' => now(),
                'updated_by' => $lockedActor->id,
            ]);

            HrEmployeeProfile::query()
                ->where('user_id', $lockedEligibility->user_id)
                ->update([
                    'can_drive_clients' => false,
                    'driver_eligibility_reviewed_at' => now(),
                ]);
        });

        $this->reevaluateCompliance($targetId);

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
            $this->compliance->evaluateStaff($user);
        }
    }

    /** @return Builder<User> */
    private function visibleStaffQuery(User $viewer, ?UserSiteAccessService $siteAccess = null): Builder
    {
        $query = User::query();
        ($siteAccess ?? $this->siteAccess)->applyStaffScope($query, $viewer);

        return $query;
    }

    private function visibleEligibility(User $viewer, int $eligibilityId): HrDriverEligibility
    {
        $eligibility = HrDriverEligibility::query()
            ->whereKey($eligibilityId)
            ->whereIn('user_id', $this->visibleStaffQuery($viewer)->select('users.id'))
            ->first();
        abort_unless($eligibility, 404);

        return $eligibility;
    }

    private function assertVisibleStaffSelection(
        User $viewer,
        int $staffUserId,
        ?UserSiteAccessService $siteAccess = null,
    ): void {
        if (! $this->visibleStaffQuery($viewer, $siteAccess)->whereKey($staffUserId)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'The selected person must be current staff at an accessible Site.',
            ]);
        }
    }

    /** @return array{0: User, 1: HrDriverEligibility} */
    private function lockVisibleEligibility(User $actor, int $eligibilityId): array
    {
        $candidate = HrDriverEligibility::query()->whereKey($eligibilityId)->first();
        abort_unless($candidate, 404);

        $locked = $this->mutationLocks->lock([$actor->id, $candidate->user_id]);
        $lockedActor = $locked['users']->get($actor->id);
        abort_unless($lockedActor instanceof User && $lockedActor->canDo('hr.driver.manage'), 403);

        $freshSiteAccess = new UserSiteAccessService;
        $lockedEligibility = HrDriverEligibility::query()
            ->whereKey($eligibilityId)
            ->whereIn(
                'user_id',
                $this->visibleStaffQuery($lockedActor, $freshSiteAccess)->select('users.id'),
            )
            ->lockForUpdate()
            ->first();
        abort_unless($lockedEligibility, 404);

        return [$lockedActor, $lockedEligibility];
    }
}
