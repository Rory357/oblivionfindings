<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsComplianceHero;
use App\Http\Controllers\Hr\Concerns\ProvidesComplianceWizardData;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class VettingController extends Controller
{
    use BuildsComplianceHero;
    use ProvidesComplianceWizardData;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly PeopleMutationLockService $mutationLocks,
        private readonly ComplianceMatrixService $compliance,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — vetting register */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.view'), 403);
        $status = $request->query('status'); // 'clear', 'pending', 'flagged', 'expired', etc.
        $search = trim((string) $request->query('q', ''));

        // Checks are owned through their canonical staff user.
        $checks = StaffBackgroundCheck::with([
            'user:id,name,email',
            'verifiedBy:id,name',
        ])
            ->whereIn('user_id', $this->visibleStaffQuery($user)->select('users.id'))
            ->when($status, fn ($q) => match ($status) {
                'expired' => $q->expired(),
                'expiring' => $q->expiringSoon(60),
                'action' => $q->requiringAction(),
                default => $q->where('status', $status),
            })
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Summary counts
        $baseQuery = StaffBackgroundCheck::query()
            ->whereIn('user_id', $this->visibleStaffQuery($user)->select('users.id'));
        $summary = [
            'total' => (clone $baseQuery)->count(),
            'clear' => (clone $baseQuery)->where('status', 'clear')->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'flagged' => (clone $baseQuery)->where('status', 'flagged')->count(),
            'expired' => (clone $baseQuery)->expired()->count(),
            'expiring' => (clone $baseQuery)->expiringSoon(60)->count(),
        ];

        return Inertia::render('hr/vetting/index', [
            'hero' => $this->complianceHero($user),
            'checks' => $checks,
            'summary' => $summary,
            'wizard' => $this->complianceWizardData($user),
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'manage' => $user->canDo('hr.vetting.manage'),
                // The shared hub header offers cross-domain create actions;
                // gate each on the real manage perm so it isn't advertised to
                // someone who'll only hit a 403 on submit.
                'compliance_manage' => $user->canDo('hr.compliance.manage'),
                'driver_manage' => $user->canDo('hr.driver.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — check detail */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.view'), 403);
        $check = $this->visibleCheck($user, (int) $check->getKey());
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
    /*  Create — show form to create background check */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $staff = $this->visibleStaffQuery($user)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/vetting/create', [
            'staff' => $staff,
            'checkTypes' => [
                ['value' => 'police_check', 'label' => 'Police Check'],
                ['value' => 'ministry_of_justice', 'label' => 'Ministry of Justice'],
                ['value' => 'vulnerable_children_act', 'label' => "Children's Act Safety Check"],
                ['value' => 'identity_verification', 'label' => 'Identity Verification'],
                ['value' => 'qualification_verification', 'label' => 'Qualification Verification'],
                ['value' => 'right_to_work', 'label' => 'Right to Work'],
                ['value' => 'credit_check', 'label' => 'Credit Check'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Edit — show form to edit background check */
    /* ------------------------------------------------------------------ */

    public function edit(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $check = $this->visibleCheck($user, (int) $check->getKey());

        $check->load(['user:id,name,email']);
        $staff = $this->visibleStaffQuery($user)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/vetting/edit', [
            'check' => $check,
            'staff' => $staff,
            'checkTypes' => [
                ['value' => 'police_check', 'label' => 'Police Check'],
                ['value' => 'ministry_of_justice', 'label' => 'Ministry of Justice'],
                ['value' => 'vulnerable_children_act', 'label' => "Children's Act Safety Check"],
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
    /*  Store — create new background check */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'check_type' => ['required', 'string', Rule::in([
                'police_check', 'ministry_of_justice', 'vulnerable_children_act',
                'identity_verification', 'qualification_verification',
                'right_to_work', 'credit_check', 'other',
            ])],
            'provider' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'check_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $targetId = (int) $validated['user_id'];
        $this->assertVisibleStaffSelection($user, $targetId);

        DB::transaction(function () use ($user, $targetId, $validated): void {
            $locked = $this->mutationLocks->lock([$user->id, $targetId]);
            $lockedActor = $locked['users']->get($user->id);
            abort_unless($lockedActor instanceof User && $lockedActor->canDo('hr.vetting.manage'), 403);
            $this->assertVisibleStaffSelection($lockedActor, $targetId, new UserSiteAccessService);

            StaffBackgroundCheck::create([
                ...$validated,
                'status' => 'pending',
                'created_by' => $lockedActor->id,
            ]);
        });

        $this->reevaluateCompliance($targetId);

        return redirect()->back()->with('success', 'Background check initiated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update — update check details, results, risk assessment */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $check = $this->visibleCheck($user, (int) $check->getKey());

        $validated = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::in([
                'pending', 'requested', 'clear', 'flagged', 'adverse', 'renewal_due',
            ])],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'provider' => ['nullable', 'string', 'max:255'],
            'check_date' => ['nullable', 'date'],
            'issue_date' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'disclosures_present' => ['sometimes', 'boolean'],
            'disclosure_details' => ['nullable', 'string', 'max:5000'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'risk_assessed' => ['sometimes', 'boolean'],
            'risk_assessment' => ['nullable', 'string', 'max:5000'],
            'risk_decision' => ['nullable', 'string', Rule::in(['approved', 'conditional', 'declined'])],
            'certificate_path' => ['nullable', 'string', 'max:500'],
            'enrolled_in_update_service' => ['sometimes', 'boolean'],
            'update_service_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $targetId = (int) $check->user_id;
        DB::transaction(function () use ($user, $check, $validated): void {
            [$lockedActor, $lockedCheck] = $this->lockVisibleCheck($user, (int) $check->getKey());

            if (! empty($validated['risk_assessed']) && ! $lockedCheck->risk_assessed) {
                $validated['risk_assessor_id'] = $lockedActor->id;
                $validated['risk_assessed_at'] = now();
            }

            $validated['updated_by'] = $lockedActor->id;
            if (($validated['status'] ?? null) === 'clear' && ! $lockedCheck->verified_at) {
                $validated['verified_by_user_id'] = $lockedActor->id;
                $validated['verified_at'] = now();
            }

            $lockedCheck->update($validated);
        });

        $this->reevaluateCompliance($targetId);

        return redirect()->back()->with('success', 'Background check updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy — delete a background check */
    /* ------------------------------------------------------------------ */

    public function destroy(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $check = $this->visibleCheck($user, (int) $check->getKey());
        $targetId = (int) $check->user_id;
        DB::transaction(function () use ($user, $check): void {
            [, $lockedCheck] = $this->lockVisibleCheck($user, (int) $check->getKey());
            $lockedCheck->delete();
        });

        $this->reevaluateCompliance($targetId);

        return redirect()->route('hr.vetting.index')->with('success', 'Background check deleted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Clear — mark a background check as clear */
    /* ------------------------------------------------------------------ */

    public function clear(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $check = $this->visibleCheck($user, (int) $check->getKey());
        $targetId = (int) $check->user_id;
        DB::transaction(function () use ($user, $check): void {
            [$lockedActor, $lockedCheck] = $this->lockVisibleCheck($user, (int) $check->getKey());
            $lockedCheck->update([
                'status' => 'clear',
                'verified_by_user_id' => $lockedActor->id,
                'verified_at' => now(),
                'updated_by' => $lockedActor->id,
            ]);
        });

        $this->reevaluateCompliance($targetId);

        return redirect()->back()->with('success', 'Background check marked as clear.');
    }

    /* ------------------------------------------------------------------ */
    /*  Renew — mark a background check for renewal */
    /* ------------------------------------------------------------------ */

    public function renew(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $check = $this->visibleCheck($user, (int) $check->getKey());
        $targetId = (int) $check->user_id;
        DB::transaction(function () use ($user, $check): void {
            [$lockedActor, $lockedCheck] = $this->lockVisibleCheck($user, (int) $check->getKey());
            $lockedCheck->update([
                'status' => 'renewal_due',
                'updated_by' => $lockedActor->id,
            ]);
        });

        $this->reevaluateCompliance($targetId);

        return redirect()->back()->with('success', 'Background check marked for renewal.');
    }

    /* ------------------------------------------------------------------ */
    /*  Capture Consent — record privacy/consent for NZ vetting */
    /* ------------------------------------------------------------------ */

    public function captureConsent(Request $request, StaffBackgroundCheck $check)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.vetting.manage'), 403);
        $check = $this->visibleCheck($user, (int) $check->getKey());

        $validated = $request->validate([
            'consent_given' => ['required', 'boolean', 'accepted'],
            'consent_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($user, $check, $validated): void {
            [$lockedActor, $lockedCheck] = $this->lockVisibleCheck($user, (int) $check->getKey());
            $lockedCheck->update([
                'notes' => trim(($lockedCheck->notes ?? '')."\n\n[Consent recorded ".now()->toDateTimeString().'] '
                    .($validated['consent_notes'] ?? 'Staff member gave consent for background check.')),
                'updated_by' => $lockedActor->id,
            ]);
        });

        return redirect()->back()->with('success', 'Consent recorded successfully.');
    }

    /** @return Builder<User> */
    private function visibleStaffQuery(User $viewer, ?UserSiteAccessService $siteAccess = null): Builder
    {
        $query = User::query();
        ($siteAccess ?? $this->siteAccess)->applyStaffScope($query, $viewer);

        return $query;
    }

    private function visibleCheck(User $viewer, int $checkId): StaffBackgroundCheck
    {
        $check = StaffBackgroundCheck::query()
            ->whereKey($checkId)
            ->whereIn('user_id', $this->visibleStaffQuery($viewer)->select('users.id'))
            ->first();
        abort_unless($check, 404);

        return $check;
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

    /** @return array{0: User, 1: StaffBackgroundCheck} */
    private function lockVisibleCheck(User $actor, int $checkId): array
    {
        $candidate = StaffBackgroundCheck::query()->whereKey($checkId)->first();
        abort_unless($candidate, 404);

        $locked = $this->mutationLocks->lock([$actor->id, $candidate->user_id]);
        $lockedActor = $locked['users']->get($actor->id);
        abort_unless($lockedActor instanceof User && $lockedActor->canDo('hr.vetting.manage'), 403);

        $freshSiteAccess = new UserSiteAccessService;
        $lockedCheck = StaffBackgroundCheck::query()
            ->whereKey($checkId)
            ->whereIn(
                'user_id',
                $this->visibleStaffQuery($lockedActor, $freshSiteAccess)->select('users.id'),
            )
            ->lockForUpdate()
            ->first();
        abort_unless($lockedCheck, 404);

        return [$lockedActor, $lockedCheck];
    }

    private function reevaluateCompliance(int $userId): void
    {
        $staff = User::query()->find($userId);
        if ($staff) {
            $this->compliance->evaluateStaff($staff);
        }
    }
}
