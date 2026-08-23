<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Enums\ComplianceExportDataset;
use App\Domain\Hr\Models\HrComplianceRenewalSnooze;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\HrComplianceReminderDeliveryService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsComplianceHero;
use App\Http\Controllers\Hr\Concerns\ProvidesComplianceWizardData;
use App\Models\Shift;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class ComplianceController extends Controller
{
    use BuildsComplianceHero;
    use ProvidesComplianceWizardData;
    use ServesPrivateAttachments;

    /** Manual / recorded compliance status values a manager may set. */
    private const STATUS_VALUES = ['compliant', 'expiring_soon', 'expired', 'not_started'];

    public function __construct(
        private readonly ComplianceMatrixService $complianceMatrixService,
        private readonly UserSiteAccessService $siteAccess,
        private readonly PeopleMutationLockService $mutationLocks,
        private readonly HrComplianceReminderDeliveryService $reminderDeliveries,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — staff compliance table with per-user breakdown */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);
        $search = trim((string) $request->query('q', ''));
        $statusFilter = $request->query('status');
        $requirementId = $request->query('requirement_id');

        // Requirements list for filter dropdown
        $requirements = HrComplianceRequirement::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'check_type as type']);

        // Build paginated per-user compliance data
        $staffQuery = $this->visibleCurrentStaffQuery($user)
            ->with([
                'roles:id,name',
                'hrEmployeeProfile:id,user_id,work_email,primary_site_id,secondary_site_ids',
                'hrEmployeeProfile.primarySite:id,type',
            ])
            ->when($search !== '', fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('name', 'like', "%{$search}%")
                    ->orWhereHas('hrEmployeeProfile', fn (Builder $profile) => $profile
                        ->where('work_email', 'like', "%{$search}%"));
            }));

        // Apply requirement and status filters against exact matrix snapshots,
        // not only materialised status rows. Newly applicable requirements are
        // therefore immediately discoverable as not started.
        if ($requirementId || $statusFilter) {
            $population = (clone $staffQuery)->get();
            $snapshots = $this->complianceMatrixService->snapshotsForUsers($population);
            $requirementFilterId = is_numeric($requirementId) ? (int) $requirementId : 0;
            $matchingUserIds = $snapshots
                ->filter(function (Collection $rows) use ($requirementId, $requirementFilterId, $statusFilter): bool {
                    if ($requirementId && ! $rows->contains(
                        fn (array $row): bool => (int) $row['requirement']->id === $requirementFilterId,
                    )) {
                        return false;
                    }

                    return match ($statusFilter) {
                        'fully_compliant' => $rows->isNotEmpty()
                            && $rows->every(fn (array $row): bool => $row['status'] === 'compliant'),
                        'has_expired' => $rows->contains(fn (array $row): bool => $row['status'] === 'expired'),
                        'has_expiring' => $rows->contains(fn (array $row): bool => $row['status'] === 'expiring_soon'),
                        'incomplete' => $rows->contains(fn (array $row): bool => $row['status'] === 'not_started'),
                        'hard_stop' => $rows->contains(fn (array $row): bool => (bool) $row['requirement']->hard_stop
                            && in_array($row['status'], ['expired', 'not_started'], true)),
                        default => true,
                    };
                })
                ->keys()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $staffQuery->whereIn('users.id', $matchingUserIds ?: [-1]);
        }

        $staffPaginated = $staffQuery
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Pre-load future shift counts for staff on this page (lightweight aggregate)
        $pageUserIds = $staffPaginated->getCollection()->pluck('id');
        $futureShiftCounts = collect();
        if ($pageUserIds->isNotEmpty()) {
            $futureShiftQuery = Shift::query()
                ->whereIn('user_id', $pageUserIds)
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->where('starts_at', '<', now()->addDays(14));
            $this->siteAccess->applyShiftScope($futureShiftQuery, $user);
            $futureShiftCounts = $futureShiftQuery
                ->selectRaw('user_id, COUNT(*) as shift_count')
                ->groupBy('user_id')
                ->pluck('shift_count', 'user_id');
        }

        // Vetting + driver rollups for the unified Overview chips (one query each, no N+1).
        [$vettingRollup, $driverRollup] = $this->statusRollups($pageUserIds);

        // Transform paginated data to match frontend StaffStatus interface
        $pageSummaries = $this->complianceMatrixService
            ->summariesForUsers($staffPaginated->getCollection());
        $staffPaginated->getCollection()->transform(function ($staffUser) use ($futureShiftCounts, $vettingRollup, $driverRollup, $pageSummaries) {
            $summary = $pageSummaries->get((int) $staffUser->id, [
                'total' => 0,
                'compliant' => 0,
                'expired' => 0,
                'expiring_soon' => 0,
                'not_started' => 0,
            ]);
            $total = $summary['total'];
            $hasIssues = $summary['expired'] > 0
                || $summary['expiring_soon'] > 0
                || $summary['not_started'] > 0;

            return [
                'user_id' => $staffUser->id,
                'user_name' => $staffUser->name,
                'user_email' => $staffUser->hrEmployeeProfile?->work_email,
                'total_requirements' => $total,
                'compliant_count' => $summary['compliant'],
                'expired_count' => $summary['expired'],
                'expiring_soon_count' => $summary['expiring_soon'],
                'not_started_count' => $summary['not_started'],
                'compliance_percent' => $total > 0
                    ? (int) round(($summary['compliant'] / $total) * 100)
                    : 0,
                'future_shifts_affected' => $hasIssues ? (int) ($futureShiftCounts->get($staffUser->id, 0)) : 0,
                'vetting_status' => $vettingRollup->get($staffUser->id, 'none'),
                'driver_status' => $driverRollup->get($staffUser->id, 'none'),
            ];
        });

        // Hero band (golden) + the aggregate summary feeding the KPI tiles.
        $hero = $this->complianceHero($user);

        return Inertia::render('hr/compliance/index', [
            'hero' => $hero,
            'staffStatuses' => $staffPaginated,
            'summary' => $hero['summary'],
            'requirements' => $requirements,
            'wizard' => $this->complianceWizardData($user),
            'filters' => [
                'q' => $search,
                'status' => $statusFilter,
                'requirement_id' => $requirementId,
            ],
            'can' => [
                'export' => ComplianceExportDataset::Staff->allows($user),
                'manage' => $user->canDo('hr.compliance.manage'),
                // .view gates the "View vetting/drivers" nav links in the row menu.
                'vetting' => $user->canDo('hr.vetting.view'),
                'driver' => $user->canDo('hr.driver.view'),
                // .manage gates the header's "Add vetting check"/"Add driver"
                // create actions — advertising them to a view-only user just
                // yields a 403 on submit.
                'vetting_manage' => $user->canDo('hr.vetting.manage'),
                'driver_manage' => $user->canDo('hr.driver.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Vetting + driver status rollups (shared by Overview + staff hub) */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0:Collection,1:Collection}
     *                                          [ user_id => vetting_status, user_id => driver_status ]
     */
    private function statusRollups($userIds): array
    {
        if ($userIds->isEmpty()) {
            return [collect(), collect()];
        }

        // Worst vetting status per user across all their background checks.
        $vettingRollup = StaffBackgroundCheck::whereIn('user_id', $userIds)
            ->get(['user_id', 'status', 'expires_at'])
            ->groupBy('user_id')
            ->map(function ($checks) {
                $rank = ['expired' => 0, 'flagged' => 1, 'pending' => 2, 'cleared' => 3];
                $worst = 'cleared';
                $worstRank = 99;
                foreach ($checks as $check) {
                    $mapped = $this->mapVettingStatus($check->status, $check->expires_at);
                    if (($rank[$mapped] ?? 99) < $worstRank) {
                        $worst = $mapped;
                        $worstRank = $rank[$mapped] ?? 99;
                    }
                }

                return $worst;
            });

        // Driver eligibility status per user (derive expired from licence date).
        $driverRollup = HrDriverEligibility::query()
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'status', 'licence_expires_at'])
            ->keyBy('user_id')
            ->map(function ($record) {
                if ($record->licence_expires_at && $record->licence_expires_at->isPast()) {
                    return 'expired';
                }

                return $record->status; // eligible | pending_review | suspended
            });

        return [$vettingRollup, $driverRollup];
    }

    private function mapVettingStatus(?string $status, $expiresAt): string
    {
        if (in_array($status, ['clear', 'cleared'], true)) {
            if ($expiresAt && Carbon::parse($expiresAt)->isPast()) {
                return 'expired';
            }

            return 'cleared';
        }

        return match ($status) {
            'expired' => 'expired',
            'flagged', 'failed', 'conditional' => 'flagged',
            'renewal_due' => 'expired',
            'pending', 'requested', 'in_progress' => 'pending',
            default => 'pending',
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Staff Detail — per-staff compliance view */
    /* ------------------------------------------------------------------ */

    public function staffDetail(Request $request, string $staff)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);
        $staff = $this->currentVisibleStaff($staff, $user);

        $applicableRequirements = $this->complianceMatrixService
            ->getApplicableRequirements($staff)
            ->keyBy('id');
        $statuses = HrStaffComplianceStatus::where('user_id', $staff->id)
            ->whereIn('requirement_id', $applicableRequirements->keys())
            ->with('requirement:id,code,name,description,category,check_type,hard_stop,validity_months')
            ->orderBy('status')
            ->get()
            ->keyBy('requirement_id');

        $complianceStatuses = $applicableRequirements
            ->map(function (HrComplianceRequirement $requirement) use ($statuses): array {
                $status = $statuses->get($requirement->id);

                return [
                    'id' => $status?->id,
                    'requirement_id' => $requirement->id,
                    'requirement_name' => $requirement->name,
                    'requirement_type' => $requirement->check_type,
                    'renewal_period_months' => $requirement->validity_months,
                    'status' => $status?->status ?? 'not_started',
                    'expiry_date' => optional($status?->expires_at)->toDateString(),
                    'completed_date' => optional($status?->valid_from)->toDateString(),
                    'evidence_url' => $status?->evidence_url,
                    'evidence_notes' => $status?->notes,
                    'is_mandatory' => (bool) $requirement->hard_stop,
                ];
            })
            ->values();

        $summary = $this->complianceMatrixService->summaryForUser($staff);

        $hardStopFailures = collect(
            $this->complianceMatrixService->canAssignToShift($staff)['failures'],
        );
        $softWarnings = $this->complianceMatrixService->getSoftWarnings($staff);

        // Latest vetting check + driver record for the right-hand unification panels.
        $canViewVetting = $user->canDo('hr.vetting.view');
        $canViewDriver = $user->canDo('hr.driver.view');
        $latestVetting = $canViewVetting
            ? StaffBackgroundCheck::where('user_id', $staff->id)
                ->orderByDesc('created_at')
                ->first()
            : null;
        $driverRecord = $canViewDriver
            ? HrDriverEligibility::query()->where('user_id', $staff->id)->first()
            : null;

        $futureShiftQuery = Shift::where('user_id', $staff->id)
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addDays(14));
        $this->siteAccess->applyShiftScope($futureShiftQuery, $user);
        $futureShifts = $futureShiftQuery->count();

        // Expiring licence vs already-rostered future shifts (audit fix round
        // 2, item 6): shifts this driver is booked on that START AFTER the
        // licence lapses. The roster gate blocks NEW assignments, but shifts
        // rostered before the expiry was recorded stay silently on the books —
        // surface them so HR can re-roster proactively.
        $atRiskShifts = [];
        if ($driverRecord && $driverRecord->licence_expires_at) {
            $atRiskShiftQuery = Shift::where('user_id', $staff->id)
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->where('starts_at', '>', $driverRecord->licence_expires_at->copy()->endOfDay())
                ->orderBy('starts_at')
                ->limit(10)
                ->with('site:id,name');
            $this->siteAccess->applyShiftScope($atRiskShiftQuery, $user);
            $atRiskShifts = $atRiskShiftQuery->get()
                ->map(fn (Shift $s) => [
                    'id' => $s->id,
                    'date' => $s->starts_at->toDateString(),
                    'site' => $s->site?->name,
                ])
                ->all();
        }

        // Active requirements offered in the Record / Waive wizards launched here.
        $requirements = HrComplianceRequirement::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category', 'check_type', 'validity_months', 'hard_stop'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'category' => $r->category,
                'check_type' => $r->check_type,
                'validity_months' => $r->validity_months,
                'hard_stop' => (bool) $r->hard_stop,
            ]);

        return Inertia::render('hr/compliance/staff-detail', [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->hrEmployeeProfile?->work_email,
            ],
            'complianceStatuses' => $complianceStatuses,
            'summary' => $summary,
            'hardStopFailures' => $hardStopFailures,
            'softWarnings' => $softWarnings,
            'futureShiftsAffected' => $hardStopFailures->isNotEmpty() ? $futureShifts : 0,
            'requirements' => $requirements,
            'wizard' => $this->complianceWizardData($user),
            'vetting' => $latestVetting ? [
                'id' => $latestVetting->id,
                'status' => $this->mapVettingStatus($latestVetting->status, $latestVetting->expires_at),
                'check_type' => $latestVetting->check_type,
                'provider' => $latestVetting->provider,
                'reference_number' => $latestVetting->reference_number,
                'expires_at' => optional($latestVetting->expires_at)->toDateString(),
            ] : null,
            'driver' => $driverRecord ? [
                'id' => $driverRecord->id,
                'status' => ($driverRecord->licence_expires_at && $driverRecord->licence_expires_at->isPast()) ? 'expired' : $driverRecord->status,
                'licence_class' => $driverRecord->licence_class,
                'licence_number' => $driverRecord->licence_number,
                'expires_at' => optional($driverRecord->licence_expires_at)->toDateString(),
                'at_risk_shifts' => $atRiskShifts,
            ] : null,
            'can' => [
                'manage' => $user->canDo('hr.compliance.manage'),
                'vetting' => $canViewVetting,
                'driver' => $canViewDriver,
            ],
        ]);
    }

    public function concealInvalidStaff(): never
    {
        abort(404);
    }

    public function concealInvalidStatus(): never
    {
        abort(404);
    }

    /* ================================================================== */
    /*  Record / update a staff compliance status — THE write path */
    /* ================================================================== */

    public function storeStatus(Request $request, string $staff)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $staff = $this->currentVisibleStaff($staff, $user);
        $validated = $this->validateStatusPayload($request);

        $newEvidencePath = null;
        $committed = false;
        if ($request->hasFile('evidence_file') && DB::transactionLevel() > 0) {
            // The controller may be called inside a wider transaction. Register
            // against that outer transaction before opening our savepoint so a
            // later outer rollback cannot strand the newly stored private file.
            DB::afterRollBack(function () use (&$newEvidencePath): void {
                $this->deleteEvidencePath('private', $newEvidencePath);
            });
        }
        try {
            DB::transaction(function () use (
                $request,
                $user,
                $staff,
                $validated,
                &$newEvidencePath,
                &$committed,
            ): void {
                [$lockedActor, $lockedStaff] = $this->lockAndReauthoriseStaff($user, $staff);
                $requirement = HrComplianceRequirement::query()
                    ->whereKey($validated['requirement_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
                $status = HrStaffComplianceStatus::query()
                    ->where('user_id', $lockedStaff->id)
                    ->where('requirement_id', $requirement->id)
                    ->lockForUpdate()
                    ->first() ?? new HrStaffComplianceStatus([
                        'user_id' => $lockedStaff->id,
                        'requirement_id' => $requirement->id,
                    ]);
                $oldEvidenceDisk = $status->evidence_disk;
                $oldEvidencePath = $status->evidence_path;

                $newEvidencePath = $this->applyStatusPayload(
                    $status,
                    $validated,
                    $request,
                    $lockedActor->id,
                );
                $this->persistComplianceStatus($status);
                DB::afterCommit(function () use (
                    &$committed,
                    $oldEvidenceDisk,
                    $oldEvidencePath,
                    $newEvidencePath,
                ): void {
                    $committed = true;
                    try {
                        if ($newEvidencePath && $oldEvidencePath !== $newEvidencePath) {
                            $this->deleteEvidencePath($oldEvidenceDisk, $oldEvidencePath);
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
            }, 1);
        } catch (Throwable $exception) {
            if (! $committed) {
                $this->deleteEvidencePath('private', $newEvidencePath);
            }

            throw $exception;
        }

        return redirect()->back()->with('success', "Compliance recorded for {$staff->name}.");
    }

    public function updateStatus(Request $request, string $status)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $status = $this->currentVisibleStatus($status, $user);
        $validated = $this->validateStatusPayload($request, requireRequirement: false);

        $newEvidencePath = null;
        $committed = false;
        if ($request->hasFile('evidence_file') && DB::transactionLevel() > 0) {
            DB::afterRollBack(function () use (&$newEvidencePath): void {
                $this->deleteEvidencePath('private', $newEvidencePath);
            });
        }
        try {
            DB::transaction(function () use (
                $request,
                $user,
                $status,
                $validated,
                &$newEvidencePath,
                &$committed,
            ): void {
                [$lockedActor, $lockedStatus] = $this->lockAndReauthoriseStatus($user, $status);
                HrComplianceRequirement::query()
                    ->whereKey($lockedStatus->requirement_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
                $oldEvidenceDisk = $lockedStatus->evidence_disk;
                $oldEvidencePath = $lockedStatus->evidence_path;
                $newEvidencePath = $this->applyStatusPayload(
                    $lockedStatus,
                    $validated,
                    $request,
                    $lockedActor->id,
                );
                $this->persistComplianceStatus($lockedStatus);
                DB::afterCommit(function () use (
                    &$committed,
                    $oldEvidenceDisk,
                    $oldEvidencePath,
                    $newEvidencePath,
                ): void {
                    $committed = true;
                    try {
                        if ($newEvidencePath && $oldEvidencePath !== $newEvidencePath) {
                            $this->deleteEvidencePath($oldEvidenceDisk, $oldEvidencePath);
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
            }, 1);
        } catch (Throwable $exception) {
            if (! $committed) {
                $this->deleteEvidencePath('private', $newEvidencePath);
            }

            throw $exception;
        }

        return redirect()->back()->with('success', 'Compliance status updated.');
    }

    private function validateStatusPayload(Request $request, bool $requireRequirement = true): array
    {
        return $this->validateStatusSemantics($request->validate([
            'requirement_id' => [
                $requireRequirement ? 'required' : 'nullable',
                'integer',
                Rule::exists('hr_compliance_requirements', 'id')->where('is_active', true),
            ],
            'status' => ['required', 'string', Rule::in(self::STATUS_VALUES)],
            'valid_from' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'evidence_category' => ['nullable', 'string', 'max:100'],
            'evidence_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:10240'],
        ]));
    }

    private function validateStatusSemantics(array $validated): array
    {
        $status = (string) $validated['status'];
        $validFrom = filled($validated['valid_from'] ?? null)
            ? Carbon::parse($validated['valid_from'])->startOfDay()
            : null;
        $expiresAt = filled($validated['expires_at'] ?? null)
            ? Carbon::parse($validated['expires_at'])->startOfDay()
            : null;
        $errors = [];

        if ($validFrom && $expiresAt && $expiresAt->lt($validFrom)) {
            $errors['expires_at'] = 'The expiry date must be on or after the valid-from date.';
        }
        if (in_array($status, ['compliant', 'expiring_soon'], true)
            && $validFrom?->isAfter(today())) {
            $errors['valid_from'] = 'A current status cannot begin in the future.';
        }
        if (in_array($status, ['compliant', 'expiring_soon'], true)
            && $expiresAt?->isBefore(today())) {
            $errors['expires_at'] = 'A current status cannot use an expiry date in the past.';
        }
        if ($status === 'expiring_soon' && ! $expiresAt) {
            $errors['expires_at'] = 'An expiring-soon status requires an expiry date.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    private function applyStatusPayload(
        HrStaffComplianceStatus $status,
        array $validated,
        Request $request,
        int $actorId,
    ): ?string {
        $status->fill([
            'status' => $validated['status'],
            'evidence_type' => 'manual',
            'evidence_category' => $validated['evidence_category'] ?? $status->evidence_category,
            'valid_from' => $validated['valid_from'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'recorded_by' => $actorId,
            'exemption_reason' => null,
            'exempted_by' => null,
            'exempted_until' => null,
            'exempted_at' => null,
            'last_checked_at' => now(),
            'next_check_at' => now()->addDay(),
        ]);

        $newEvidencePath = null;
        if ($request->hasFile('evidence_file')) {
            $file = $request->file('evidence_file');
            $ext = $file->getClientOriginalExtension() ?: $file->extension();
            $dir = 'hr-compliance/evidence';
            $name = Str::uuid()->toString().($ext ? ".{$ext}" : '');
            $candidatePath = "{$dir}/{$name}";
            try {
                $storedPath = Storage::disk('private')->putFileAs($dir, $file, $name);
                $stored = is_string($storedPath)
                    && $storedPath === $candidatePath
                    && Storage::disk('private')->exists($storedPath);
            } catch (Throwable $exception) {
                $this->deleteEvidencePath('private', $candidatePath);

                throw $exception;
            }
            if (! $stored) {
                $this->deleteEvidencePath('private', $candidatePath);
                throw ValidationException::withMessages([
                    'evidence_file' => 'The evidence file could not be stored. Please try again.',
                ]);
            }
            $newEvidencePath = $storedPath;

            $status->evidence_disk = 'private';
            $status->evidence_path = $storedPath;
            $status->evidence_filename = $file->getClientOriginalName();
            $status->evidence_mime = $file->getClientMimeType();
        }

        return $newEvidencePath;
    }

    /* ================================================================== */
    /*  Waive / exempt — lift a hard-stop with reason + approver */
    /* ================================================================== */

    public function exempt(Request $request, string $status)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $status = $this->currentVisibleStatus($status, $user);

        $validated = $request->validate([
            'exemption_reason' => ['required', 'string', 'max:5000'],
            'exempted_until' => ['nullable', 'date', 'after:today'],
            'approver' => ['nullable', 'string', 'max:255'],
            'acknowledge' => ['accepted'],
        ]);

        DB::transaction(function () use ($user, $status, $validated): void {
            [$lockedActor, $lockedStatus] = $this->lockAndReauthoriseStatus($user, $status);
            HrComplianceRequirement::query()
                ->whereKey($lockedStatus->requirement_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedStatus->update([
                'exemption_reason' => $validated['exemption_reason'],
                'exempted_by' => $lockedActor->id,
                'exempted_until' => $validated['exempted_until'] ?? null,
                'exempted_at' => now(),
                'status' => 'compliant', // lifts the hard-stop
                'notes' => trim(($lockedStatus->notes ? $lockedStatus->notes."\n" : '')
                    .'Exemption granted'
                    .(! empty($validated['approver']) ? " (approved by {$validated['approver']})" : '')
                    .'.'),
                'last_checked_at' => now(),
            ]);
        }, 3);

        return redirect()->back()->with('success', 'Exemption recorded and hard-stop lifted.');
    }

    /* ================================================================== */
    /*  Evidence download (private disk, hardened headers) */
    /* ================================================================== */

    public function evidence(Request $request, string $status)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);
        $status = $this->currentVisibleStatus($status, $user);
        abort_unless($status->evidence_path, 404);
        $evidenceDisk = blank($status->evidence_disk) ? 'private' : $status->evidence_disk;
        abort_unless(
            $evidenceDisk === 'private' && $this->isEvidencePath($status->evidence_path),
            404,
        );

        return $this->streamPrivateAttachment(
            $evidenceDisk,
            $status->evidence_path,
            $status->evidence_filename ?: 'evidence',
            $status->evidence_mime,
            'inline',
        );
    }

    protected function persistComplianceStatus(HrStaffComplianceStatus $status): void
    {
        $status->save();
    }

    private function deleteEvidencePath(mixed $disk, mixed $path): void
    {
        $disk = blank($disk) ? 'private' : $disk;
        if ($disk !== 'private' || ! $this->isEvidencePath($path)) {
            return;
        }

        try {
            $storage = Storage::disk('private');
            if ($storage->exists($path)) {
                $deleted = $storage->delete($path);
                if (! $deleted || $storage->exists($path)) {
                    report(new \RuntimeException('A superseded HR compliance evidence file could not be removed.'));
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function isEvidencePath(mixed $path): bool
    {
        return is_string($path)
            && preg_match(
                '~\Ahr-compliance/evidence/(?:[1-9][0-9]*/)?[A-Za-z0-9][A-Za-z0-9._-]*\z~D',
                $path,
            ) === 1;
    }

    /* ================================================================== */
    /*  Bulk actions */
    /* ================================================================== */

    public function bulkRemind(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);
        $recipients = $this->visibleStaffSelection($user, $validated['user_ids']);
        $deliveries = DB::transaction(function () use ($user, $recipients): Collection {
            [$lockedActor, $lockedRecipients] = $this->lockAndReauthoriseSelection($user, $recipients);

            return $lockedRecipients->map(fn (User $recipient) => $this->reminderDeliveries->stageManual(
                recipient: $recipient,
                sourceType: 'bulk_outstanding',
                sourceId: null,
                requirementName: 'outstanding compliance requirements',
                expiryDate: null,
                initiatedBy: $lockedActor,
            ));
        }, 3);
        $deliveries->each(fn ($delivery) => $this->reminderDeliveries->queue($delivery));

        $count = $recipients->count();

        return redirect()->back()->with('success', "Reminders queued for {$count} staff.");
    }

    public function bulkRecord(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $validated = $this->validateStatusSemantics($request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'distinct'],
            'requirement_id' => [
                'required',
                'integer',
                Rule::exists('hr_compliance_requirements', 'id')->where('is_active', true),
            ],
            'status' => ['required', 'string', Rule::in(self::STATUS_VALUES)],
            'valid_from' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]));

        $selection = $this->visibleStaffSelection($user, $validated['user_ids']);
        $affected = DB::transaction(function () use ($user, $selection, $validated): int {
            [$lockedActor, $lockedSelection] = $this->lockAndReauthoriseSelection($user, $selection);
            HrComplianceRequirement::query()
                ->whereKey($validated['requirement_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $affected = 0;
            foreach ($lockedSelection as $recipient) {
                $status = HrStaffComplianceStatus::query()
                    ->where('user_id', $recipient->id)
                    ->where('requirement_id', $validated['requirement_id'])
                    ->lockForUpdate()
                    ->first() ?? new HrStaffComplianceStatus([
                        'user_id' => $recipient->id,
                        'requirement_id' => $validated['requirement_id'],
                    ]);
                $status->fill([
                    'status' => $validated['status'],
                    'evidence_type' => 'manual',
                    'valid_from' => $validated['valid_from'] ?? null,
                    'expires_at' => $validated['expires_at'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'recorded_by' => $lockedActor->id,
                    'exemption_reason' => null,
                    'exempted_by' => null,
                    'exempted_until' => null,
                    'exempted_at' => null,
                    'last_checked_at' => now(),
                    'next_check_at' => now()->addDay(),
                ]);
                $status->save();
                $affected++;
            }

            return $affected;
        }, 3);

        return redirect()->back()->with('success', "Compliance recorded for {$affected} staff.");
    }

    public function bulkExempt(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'distinct'],
            'requirement_id' => [
                'required',
                'integer',
                Rule::exists('hr_compliance_requirements', 'id')->where('is_active', true),
            ],
            'exemption_reason' => ['required', 'string', 'max:5000'],
            'exempted_until' => ['nullable', 'date', 'after:today'],
            'acknowledge' => ['accepted'],
        ]);

        $selection = $this->visibleStaffSelection($user, $validated['user_ids']);
        $affected = DB::transaction(function () use ($user, $selection, $validated): int {
            [$lockedActor, $lockedSelection] = $this->lockAndReauthoriseSelection($user, $selection);
            HrComplianceRequirement::query()
                ->whereKey($validated['requirement_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $affected = 0;
            foreach ($lockedSelection as $recipient) {
                $status = HrStaffComplianceStatus::query()
                    ->where('user_id', $recipient->id)
                    ->where('requirement_id', $validated['requirement_id'])
                    ->lockForUpdate()
                    ->first() ?? new HrStaffComplianceStatus([
                        'user_id' => $recipient->id,
                        'requirement_id' => $validated['requirement_id'],
                    ]);
                $status->fill([
                    'status' => 'compliant',
                    'exemption_reason' => $validated['exemption_reason'],
                    'exempted_by' => $lockedActor->id,
                    'exempted_until' => $validated['exempted_until'] ?? null,
                    'exempted_at' => now(),
                    'last_checked_at' => now(),
                ]);
                $status->save();
                $affected++;
            }

            return $affected;
        }, 3);

        return redirect()->back()->with('success', "Waiver applied to {$affected} staff.");
    }

    /* ================================================================== */
    /*  Renewals actions (remind / snooze) — record renewal uses storeStatus */
    /* ================================================================== */

    public function renewalRemind(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);

        $locator = $request->validate([
            'type' => ['required', Rule::in(['compliance', 'vetting', 'driver'])],
            'id' => ['required', 'integer'],
        ]);
        $renewable = $this->visibleRenewable($locator['type'], $locator['id'], $user);
        abort_unless($renewable, 404);

        $delivery = DB::transaction(function () use ($user, $renewable) {
            [$lockedActor, $lockedRenewable] = $this->lockAndReauthoriseRenewable(
                $user,
                $renewable,
                'hr.compliance.view',
            );

            return $this->reminderDeliveries->stageManual(
                recipient: $lockedRenewable['recipient'],
                sourceType: 'renewal_'.$lockedRenewable['type'],
                sourceId: $lockedRenewable['entity_id'],
                requirementName: $lockedRenewable['label'],
                expiryDate: $lockedRenewable['date'],
                initiatedBy: $lockedActor,
            );
        }, 3);
        $this->reminderDeliveries->queue($delivery);

        return redirect()->back()->with('success', "Reminder queued for {$renewable['recipient']->name}.");
    }

    public function renewalSnooze(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);

        $locator = $request->validate([
            'type' => ['required', Rule::in(['compliance', 'vetting', 'driver'])],
            'id' => ['required', 'integer'],
        ]);
        $renewable = $this->visibleRenewable($locator['type'], $locator['id'], $user);
        abort_unless($renewable, 404);
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days = $validated['days'] ?? 7;
        DB::transaction(function () use ($user, $renewable, $days): void {
            [$lockedActor, $lockedRenewable] = $this->lockAndReauthoriseRenewable(
                $user,
                $renewable,
                'hr.compliance.manage',
            );
            $snooze = HrComplianceRenewalSnooze::query()
                ->where('entity_type', $lockedRenewable['type'])
                ->where('entity_id', $lockedRenewable['entity_id'])
                ->lockForUpdate()
                ->first() ?? new HrComplianceRenewalSnooze([
                    'entity_type' => $lockedRenewable['type'],
                    'entity_id' => $lockedRenewable['entity_id'],
                ]);
            $snooze->fill([
                'snoozed_until' => now()->addDays($days),
                'snoozed_by' => $lockedActor->id,
            ])->save();
        }, 3);

        return redirect()->back()->with('success', "Snoozed for {$days} days.");
    }

    /**
     * @return array{type:string,entity_id:int,user_id:int,recipient:User,label:string,date:?string}|null
     */
    private function visibleRenewable(string $type, int $id, User $viewer, bool $forUpdate = false): ?array
    {
        $query = match ($type) {
            'compliance' => HrStaffComplianceStatus::query(),
            'vetting' => StaffBackgroundCheck::query(),
            'driver' => HrDriverEligibility::query(),
            default => null,
        };
        if (! $query) {
            return null;
        }
        $query->whereKey($id)
            ->whereIn('user_id', $this->visibleCurrentStaffQuery($viewer)->select('users.id'));
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $record = $type === 'compliance'
            ? $query->with(['user', 'requirement:id,name'])->first()
            : $query->with('user')->first();
        if (! $record || ! $record->user) {
            return null;
        }

        return [
            'type' => $type,
            'entity_id' => (int) $record->id,
            'user_id' => (int) $record->user_id,
            'recipient' => $record->user,
            'label' => match ($type) {
                'compliance' => $record->requirement?->name ?? 'Compliance requirement',
                'vetting' => ucfirst(str_replace('_', ' ', (string) $record->check_type)),
                'driver' => 'Driver licence',
            },
            'date' => optional($type === 'driver' ? $record->licence_expires_at : $record->expires_at)
                ->toDateString(),
        ];
    }

    /**
     * @param  array{type:string,entity_id:int,user_id:int,recipient:User,label:string,date:?string}  $renewable
     * @return array{0:User,1:array{type:string,entity_id:int,user_id:int,recipient:User,label:string,date:?string}}
     */
    private function lockAndReauthoriseRenewable(
        User $actor,
        array $renewable,
        string $permission,
    ): array {
        $locked = $this->mutationLocks->lock([$actor->id, $renewable['user_id']]);
        $lockedActor = $locked['users']->get($actor->id);
        abort_unless($lockedActor instanceof User && $lockedActor->canDo($permission), 403);

        $lockedRenewable = $this->visibleRenewable(
            $renewable['type'],
            $renewable['entity_id'],
            $lockedActor,
            true,
        );
        abort_unless($lockedRenewable && $lockedRenewable['user_id'] === $renewable['user_id'], 404);

        return [$lockedActor, $lockedRenewable];
    }

    /** @return Collection<int, User> */
    private function visibleStaffSelection(User $viewer, array $userIds): Collection
    {
        $ids = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $query = $this->visibleCurrentStaffQuery($viewer)
            ->whereIn('id', $ids)
            ->orderBy('id');
        $selection = $query->get();
        if ($ids->isEmpty() || $selection->pluck('id')->values()->all() !== $ids->all()) {
            throw ValidationException::withMessages([
                'user_ids' => 'Every selected person must be current staff at an accessible Site.',
            ]);
        }

        return $selection;
    }

    /**
     * @param  Collection<int, User>  $selection
     * @return array{0:User,1:Collection<int, User>}
     */
    private function lockAndReauthoriseSelection(User $actor, Collection $selection): array
    {
        $ids = $selection->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        $locked = $this->mutationLocks->lock([$actor->id, ...$ids->all()]);
        $lockedActor = $locked['users']->get($actor->id);
        abort_unless(
            $lockedActor instanceof User && $lockedActor->canDo('hr.compliance.manage'),
            403,
        );

        $freshAccess = new UserSiteAccessService;
        $visible = User::query()->whereIn('id', $ids)->orderBy('id');
        $freshAccess->applyStaffScope($visible, $lockedActor);
        $lockedSelection = $visible->get();
        if ($lockedSelection->pluck('id')->values()->all() !== $ids->all()) {
            throw ValidationException::withMessages([
                'user_ids' => 'Every selected person must be current staff at an accessible Site.',
            ]);
        }

        return [$lockedActor, $lockedSelection];
    }

    /** @return Builder<User> */
    private function visibleCurrentStaffQuery(User $viewer): Builder
    {
        $query = User::query();
        $this->siteAccess->applyStaffScope($query, $viewer);

        return $query;
    }

    private function currentVisibleStaff(string $routeStaffId, User $viewer): User
    {
        $staff = $this->visibleCurrentStaffQuery($viewer)
            ->with([
                'hrEmployeeProfile:id,user_id,work_email,primary_site_id',
                'hrEmployeeProfile.primarySite:id,type',
            ])
            ->whereKey($this->boundedRouteId($routeStaffId))
            ->first();
        abort_unless($staff, 404);

        return $staff;
    }

    private function currentVisibleStatus(string $routeStatusId, User $viewer): HrStaffComplianceStatus
    {
        $status = HrStaffComplianceStatus::query()
            ->whereKey($this->boundedRouteId($routeStatusId))
            ->whereIn('user_id', $this->visibleCurrentStaffQuery($viewer)->select('users.id'))
            ->first();
        abort_unless($status, 404);

        return $status;
    }

    /** @return array{0:User,1:User} */
    private function lockAndReauthoriseStaff(User $actor, User $staff): array
    {
        $locked = $this->mutationLocks->lock([$actor->id, $staff->id]);
        $lockedActor = $locked['users']->get($actor->id);
        $lockedStaff = $locked['users']->get($staff->id);
        abort_unless(
            $lockedActor instanceof User
                && $lockedStaff instanceof User
                && $lockedActor->canDo('hr.compliance.manage'),
            404,
        );

        $freshAccess = new UserSiteAccessService;
        $visible = User::query()->whereKey($lockedStaff->id);
        $freshAccess->applyStaffScope($visible, $lockedActor);
        abort_unless($visible->exists(), 404);

        return [$lockedActor, $lockedStaff];
    }

    /** @return array{0:User,1:HrStaffComplianceStatus} */
    private function lockAndReauthoriseStatus(
        User $actor,
        HrStaffComplianceStatus $status,
    ): array {
        $target = User::query()->whereKey($status->user_id)->first();
        abort_unless($target, 404);
        [$lockedActor, $lockedStaff] = $this->lockAndReauthoriseStaff($actor, $target);
        $lockedStatus = HrStaffComplianceStatus::query()
            ->whereKey($status->id)
            ->lockForUpdate()
            ->first();
        abort_unless($lockedStatus && $lockedStatus->user_id === $lockedStaff->id, 404);

        return [$lockedActor, $lockedStatus];
    }

    private function boundedRouteId(string $value): int
    {
        $normalized = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        abort_unless(
            ctype_digit($value)
                && $normalized !== ''
                && (strlen($normalized) < strlen($maximum)
                    || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) <= 0)),
            404,
        );

        return (int) $normalized;
    }
}
