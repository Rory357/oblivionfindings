<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Hr\Concerns\BuildsComplianceHero;
use App\Http\Controllers\Hr\Concerns\ProvidesComplianceWizardData;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Models\Shift;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    use BuildsComplianceHero;
    use ProvidesComplianceWizardData;
    use ResolvesHrTenant;
    use ServesPrivateAttachments;

    /** Manual / recorded compliance status values a manager may set. */
    private const STATUS_VALUES = ['compliant', 'expiring_soon', 'expired', 'not_started'];

    public function __construct(
        private readonly ComplianceMatrixService $complianceMatrixService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — staff compliance table with per-user breakdown             */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $search = trim((string) $request->query('q', ''));
        $statusFilter = $request->query('status');
        $requirementId = $request->query('requirement_id');

        // Requirements list for filter dropdown
        $requirements = HrComplianceRequirement::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'check_type as type']);

        // Build per-staff compliance stats from hr_staff_compliance_status
        $totalRequirements = HrComplianceRequirement::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        // Get active staff user IDs from employee profiles
        $activeStaffQuery = HrEmployeeProfile::where('tenant_id', $tenantId)->where('is_active', true);
        $activeStaffUserIds = (clone $activeStaffQuery)->pluck('user_id');

        // Build paginated per-user compliance data
        $staffQuery = User::whereIn('id', $activeStaffUserIds)
            ->when($search !== '', fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('name', 'like', "%{$search}%")
                   ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($requirementId, fn ($q) => $q->whereHas('complianceStatuses', fn ($cs) =>
                $cs->where('tenant_id', $tenantId)->where('requirement_id', $requirementId)
            ));

        // Apply status filter
        if ($statusFilter === 'fully_compliant') {
            $staffQuery->whereDoesntHave('complianceStatuses', fn ($q) =>
                $q->where('tenant_id', $tenantId)->whereIn('status', ['expired', 'expiring_soon', 'not_started'])
            );
        } elseif ($statusFilter === 'has_expired') {
            $staffQuery->whereHas('complianceStatuses', fn ($q) =>
                $q->where('tenant_id', $tenantId)->where('status', 'expired')
            );
        } elseif ($statusFilter === 'has_expiring') {
            $staffQuery->whereHas('complianceStatuses', fn ($q) =>
                $q->where('tenant_id', $tenantId)->where('status', 'expiring_soon')
            );
        } elseif ($statusFilter === 'incomplete') {
            $staffQuery->whereHas('complianceStatuses', fn ($q) =>
                $q->where('tenant_id', $tenantId)->where('status', 'not_started')
            );
        }

        $staffPaginated = $staffQuery
            ->withCount([
                'complianceStatuses as compliant_count' => fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'compliant'),
                'complianceStatuses as expired_count' => fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'expired'),
                'complianceStatuses as expiring_soon_count' => fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'expiring_soon'),
                'complianceStatuses as not_started_count' => fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'not_started'),
            ])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Pre-load future shift counts for staff on this page (lightweight aggregate)
        $pageUserIds = $staffPaginated->getCollection()->pluck('id');
        $futureShiftCounts = $pageUserIds->isNotEmpty()
            ? Shift::query()
                ->whereIn('user_id', $pageUserIds)
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->where('starts_at', '<', now()->addDays(14))
                ->selectRaw('user_id, COUNT(*) as shift_count')
                ->groupBy('user_id')
                ->pluck('shift_count', 'user_id')
            : collect();

        // Vetting + driver rollups for the unified Overview chips (one query each, no N+1).
        [$vettingRollup, $driverRollup] = $this->statusRollups($pageUserIds, $tenantId);

        // Transform paginated data to match frontend StaffStatus interface
        $staffPaginated->getCollection()->transform(function ($staffUser) use ($futureShiftCounts, $vettingRollup, $driverRollup) {
            $total = max($staffUser->compliant_count + $staffUser->expired_count + $staffUser->expiring_soon_count + $staffUser->not_started_count, 1);
            $hasIssues = $staffUser->expired_count > 0 || $staffUser->expiring_soon_count > 0;
            return [
                'user_id' => $staffUser->id,
                'user_name' => $staffUser->name,
                'user_email' => $staffUser->email,
                'total_requirements' => $total,
                'compliant_count' => $staffUser->compliant_count,
                'expired_count' => $staffUser->expired_count,
                'expiring_soon_count' => $staffUser->expiring_soon_count,
                'not_started_count' => $staffUser->not_started_count,
                'compliance_percent' => $total > 0
                    ? (int) round(($staffUser->compliant_count / $total) * 100)
                    : 0,
                'future_shifts_affected' => $hasIssues ? (int) ($futureShiftCounts->get($staffUser->id, 0)) : 0,
                'vetting_status' => $vettingRollup->get($staffUser->id, 'none'),
                'driver_status' => $driverRollup->get($staffUser->id, 'none'),
            ];
        });

        // Hero band (golden) + the aggregate summary feeding the KPI tiles.
        $hero = $this->complianceHero($user, $tenantId);

        return Inertia::render('hr/compliance/index', [
            'hero' => $hero,
            'staffStatuses' => $staffPaginated,
            'summary' => $hero['summary'],
            'requirements' => $requirements,
            'wizard' => $this->complianceWizardData($tenantId),
            'filters' => [
                'q' => $search,
                'status' => $statusFilter,
                'requirement_id' => $requirementId,
            ],
            'can' => [
                'manage' => $user->canDo('hr.compliance.manage'),
                'vetting' => $user->canDo('hr.vetting.view'),
                'driver' => $user->canDo('hr.driver.view'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Vetting + driver status rollups (shared by Overview + staff hub)   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection}
     *         [ user_id => vetting_status, user_id => driver_status ]
     */
    private function statusRollups($userIds, ?int $tenantId): array
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
        $driverRollup = HrDriverEligibility::where('tenant_id', $tenantId)
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
    /*  Staff Detail — per-staff compliance view                           */
    /* ------------------------------------------------------------------ */

    public function staffDetail(Request $request, User $staff)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $belongsToTenant = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $staff->id)
            ->exists();
        if (! $belongsToTenant) {
            abort(404);
        }

        $statuses = HrStaffComplianceStatus::where('user_id', $staff->id)
            ->where('tenant_id', $tenantId)
            ->with('requirement:id,code,name,description,category,check_type,hard_stop,validity_months')
            ->orderBy('status')
            ->get();

        $complianceStatuses = $statuses
            ->map(fn (HrStaffComplianceStatus $status) => [
                'id' => $status->id,
                'requirement_id' => $status->requirement_id,
                'requirement_name' => $status->requirement?->name ?? 'Unknown requirement',
                'requirement_type' => $status->requirement?->check_type ?? 'manual',
                'renewal_period_months' => $status->requirement?->validity_months,
                'status' => $status->status,
                'expiry_date' => optional($status->expires_at)->toDateString(),
                'completed_date' => optional($status->valid_from)->toDateString(),
                'evidence_url' => $status->evidence_url ?? null,
                'evidence_notes' => $status->notes ?? null,
                'is_mandatory' => (bool) ($status->requirement?->hard_stop ?? false),
            ])
            ->values();

        $summary = [
            'compliant' => $statuses->where('status', 'compliant')->count(),
            'expiring_soon' => $statuses->where('status', 'expiring_soon')->count(),
            'expired' => $statuses->where('status', 'expired')->count(),
            'not_started' => $statuses->where('status', 'not_started')->count(),
        ];

        $hardStopFailures = $this->complianceMatrixService->getHardStopFailures($staff);
        $softWarnings = $this->complianceMatrixService->getSoftWarnings($staff);

        // Latest vetting check + driver record for the right-hand unification panels.
        $latestVetting = StaffBackgroundCheck::where('user_id', $staff->id)
            ->orderByDesc('created_at')
            ->first();
        $driverRecord = HrDriverEligibility::where('tenant_id', $tenantId)
            ->where('user_id', $staff->id)
            ->first();

        $futureShifts = Shift::where('user_id', $staff->id)
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addDays(14))
            ->count();

        // Expiring licence vs already-rostered future shifts (audit fix round
        // 2, item 6): shifts this driver is booked on that START AFTER the
        // licence lapses. The roster gate blocks NEW assignments, but shifts
        // rostered before the expiry was recorded stay silently on the books —
        // surface them so HR can re-roster proactively.
        $atRiskShifts = [];
        if ($driverRecord && $driverRecord->licence_expires_at) {
            $atRiskShifts = Shift::where('user_id', $staff->id)
                ->where('status', 'scheduled')
                ->where('starts_at', '>', now())
                ->where('starts_at', '>', $driverRecord->licence_expires_at->copy()->endOfDay())
                ->orderBy('starts_at')
                ->limit(10)
                ->with('site:id,name')
                ->get()
                ->map(fn (Shift $s) => [
                    'id' => $s->id,
                    'date' => $s->starts_at->toDateString(),
                    'site' => $s->site?->name,
                ])
                ->all();
        }

        // Active requirements offered in the Record / Waive wizards launched here.
        $requirements = HrComplianceRequirement::where('tenant_id', $tenantId)
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
            'staff' => $staff->only(['id', 'name', 'email']),
            'complianceStatuses' => $complianceStatuses,
            'summary' => $summary,
            'statuses' => $statuses,
            'hardStopFailures' => $hardStopFailures,
            'softWarnings' => $softWarnings,
            'futureShiftsAffected' => $hardStopFailures->isNotEmpty() ? $futureShifts : 0,
            'requirements' => $requirements,
            'wizard' => $this->complianceWizardData($tenantId),
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
                'vetting' => $user->canDo('hr.vetting.view'),
                'driver' => $user->canDo('hr.driver.view'),
            ],
        ]);
    }

    /* ================================================================== */
    /*  Record / update a staff compliance status — THE write path        */
    /* ================================================================== */

    public function storeStatus(Request $request, User $staff)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $belongsToTenant = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $staff->id)
            ->exists();
        abort_unless($belongsToTenant, 404);

        $validated = $this->validateStatusPayload($request, $tenantId);

        $requirement = HrComplianceRequirement::where('id', $validated['requirement_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $status = HrStaffComplianceStatus::firstOrNew([
            'tenant_id' => $tenantId,
            'user_id' => $staff->id,
            'requirement_id' => $requirement->id,
        ]);

        $this->applyStatusPayload($status, $validated, $request, $tenantId, $user->id);
        $status->save();

        return redirect()->back()->with('success', "Compliance recorded for {$staff->name}.");
    }

    public function updateStatus(Request $request, HrStaffComplianceStatus $status)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $status->tenant_id);

        $validated = $this->validateStatusPayload($request, $tenantId, requireRequirement: false);
        $this->applyStatusPayload($status, $validated, $request, $tenantId, $user->id);
        $status->save();

        return redirect()->back()->with('success', 'Compliance status updated.');
    }

    private function validateStatusPayload(Request $request, ?int $tenantId, bool $requireRequirement = true): array
    {
        return $request->validate([
            'requirement_id' => [$requireRequirement ? 'required' : 'nullable', 'integer', Rule::exists('hr_compliance_requirements', 'id')->where('tenant_id', $tenantId)],
            'status' => ['required', 'string', Rule::in(self::STATUS_VALUES)],
            'valid_from' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'evidence_category' => ['nullable', 'string', 'max:100'],
            'evidence_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:10240'],
        ]);
    }

    private function applyStatusPayload(HrStaffComplianceStatus $status, array $validated, Request $request, ?int $tenantId, int $actorId): void
    {
        $status->fill([
            'status' => $validated['status'],
            'evidence_type' => 'manual',
            'evidence_category' => $validated['evidence_category'] ?? $status->evidence_category,
            'valid_from' => $validated['valid_from'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'recorded_by' => $actorId,
            'last_checked_at' => now(),
            'next_check_at' => now()->addDay(),
        ]);

        if ($request->hasFile('evidence_file')) {
            // Replace any prior evidence file, then store the new one privately under
            // a tenant-scoped, unguessable path (never reachable at a public URL).
            $this->deleteEvidenceFile($status);
            $file = $request->file('evidence_file');
            $ext = $file->getClientOriginalExtension() ?: $file->extension();
            $dir = "hr-compliance/evidence/{$tenantId}";
            $name = Str::uuid()->toString() . ($ext ? ".{$ext}" : '');
            \Illuminate\Support\Facades\Storage::disk('private')->putFileAs($dir, $file, $name);

            $status->evidence_disk = 'private';
            $status->evidence_path = "{$dir}/{$name}";
            $status->evidence_filename = $file->getClientOriginalName();
            $status->evidence_mime = $file->getClientMimeType();
        }
    }

    /* ================================================================== */
    /*  Waive / exempt — lift a hard-stop with reason + approver           */
    /* ================================================================== */

    public function exempt(Request $request, HrStaffComplianceStatus $status)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $status->tenant_id);

        $validated = $request->validate([
            'exemption_reason' => ['required', 'string', 'max:5000'],
            'exempted_until' => ['nullable', 'date', 'after:today'],
            'approver' => ['nullable', 'string', 'max:255'],
            'acknowledge' => ['accepted'],
        ]);

        $status->update([
            'exemption_reason' => $validated['exemption_reason'],
            'exempted_by' => $user->id,
            'exempted_until' => $validated['exempted_until'] ?? null,
            'exempted_at' => now(),
            'status' => 'compliant', // lifts the hard-stop
            'notes' => trim(($status->notes ? $status->notes . "\n" : '')
                . 'Exemption granted'
                . (! empty($validated['approver']) ? " (approved by {$validated['approver']})" : '')
                . '.'),
            'last_checked_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Exemption recorded and hard-stop lifted.');
    }

    /* ================================================================== */
    /*  Evidence download (private disk, hardened headers)                 */
    /* ================================================================== */

    public function evidence(Request $request, HrStaffComplianceStatus $status)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $status->tenant_id);
        abort_unless($status->evidence_path, 404);

        return $this->streamPrivateAttachment(
            $status->evidence_disk,
            $status->evidence_path,
            $status->evidence_filename ?: 'evidence',
            $status->evidence_mime,
            'inline',
        );
    }

    private function deleteEvidenceFile(HrStaffComplianceStatus $status): void
    {
        if ($status->evidence_path) {
            \Illuminate\Support\Facades\Storage::disk($status->evidence_disk ?: 'private')->delete($status->evidence_path);
        }
    }

    /* ================================================================== */
    /*  Bulk actions                                                       */
    /* ================================================================== */

    public function bulkRemind(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        $recipients = $this->tenantUsers($validated['user_ids'], $tenantId);
        foreach ($recipients as $recipient) {
            $recipient->notify(new \App\Notifications\ComplianceReminderNotification(
                'outstanding compliance requirements',
                null,
                $user->name,
            ));
        }

        $count = $recipients->count();

        return redirect()->back()->with('success', "Reminders sent to {$count} staff.");
    }

    public function bulkRecord(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
            'requirement_id' => ['required', 'integer', Rule::exists('hr_compliance_requirements', 'id')->where('tenant_id', $tenantId)],
            'status' => ['required', 'string', Rule::in(self::STATUS_VALUES)],
            'valid_from' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $userIds = $this->tenantUsers($validated['user_ids'], $tenantId)->pluck('id');
        $affected = 0;
        foreach ($userIds as $uid) {
            $status = HrStaffComplianceStatus::firstOrNew([
                'tenant_id' => $tenantId,
                'user_id' => $uid,
                'requirement_id' => $validated['requirement_id'],
            ]);
            $status->fill([
                'status' => $validated['status'],
                'evidence_type' => 'manual',
                'valid_from' => $validated['valid_from'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'recorded_by' => $user->id,
                'last_checked_at' => now(),
                'next_check_at' => now()->addDay(),
            ]);
            $status->save();
            $affected++;
        }

        return redirect()->back()->with('success', "Compliance recorded for {$affected} staff.");
    }

    public function bulkExempt(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
            'requirement_id' => ['required', 'integer', Rule::exists('hr_compliance_requirements', 'id')->where('tenant_id', $tenantId)],
            'exemption_reason' => ['required', 'string', 'max:5000'],
            'exempted_until' => ['nullable', 'date', 'after:today'],
            'acknowledge' => ['accepted'],
        ]);

        $userIds = $this->tenantUsers($validated['user_ids'], $tenantId)->pluck('id');
        $affected = 0;
        foreach ($userIds as $uid) {
            $status = HrStaffComplianceStatus::firstOrNew([
                'tenant_id' => $tenantId,
                'user_id' => $uid,
                'requirement_id' => $validated['requirement_id'],
            ]);
            $status->fill([
                'status' => 'compliant',
                'exemption_reason' => $validated['exemption_reason'],
                'exempted_by' => $user->id,
                'exempted_until' => $validated['exempted_until'] ?? null,
                'exempted_at' => now(),
                'last_checked_at' => now(),
            ]);
            $status->save();
            $affected++;
        }

        return redirect()->back()->with('success', "Waiver applied to {$affected} staff.");
    }

    /**
     * Assign a requirement to roles / site types (per-role matrix rows). Backs both
     * the Matrix "Bulk assign" wizard and the Requirement wizard's Assignment step.
     */
    public function assign(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'requirement_ids' => ['required', 'array', 'min:1'],
            'requirement_ids.*' => ['integer', Rule::exists('hr_compliance_requirements', 'id')->where('tenant_id', $tenantId)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'max:100'],
            'site_types' => ['nullable', 'array'],
            'site_types.*' => ['string', 'max:100'],
            'is_mandatory' => ['sometimes', 'boolean'],
        ]);

        $siteTypes = ! empty($validated['site_types']) ? $validated['site_types'] : [null];
        $count = 0;
        foreach ($validated['requirement_ids'] as $reqId) {
            foreach ($validated['roles'] as $role) {
                foreach ($siteTypes as $siteType) {
                    \App\Domain\Hr\Models\HrComplianceMatrix::updateOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'requirement_id' => $reqId,
                            'role' => $role,
                            'site_type' => $siteType,
                        ],
                        ['is_mandatory' => $validated['is_mandatory'] ?? true],
                    );
                    $count++;
                }
            }
        }

        return redirect()->back()->with('success', "Assigned across {$count} role/site combinations.");
    }

    /* ================================================================== */
    /*  Renewals actions (remind / snooze) — record renewal uses storeStatus */
    /* ================================================================== */

    public function renewalRemind(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['compliance', 'vetting', 'driver'])],
            'id' => ['required', 'integer'],
        ]);

        [$recipient, $label, $date] = $this->resolveRenewable($validated['type'], $validated['id'], $tenantId);
        abort_unless($recipient, 404);

        $recipient->notify(new \App\Notifications\ComplianceReminderNotification($label, $date, $user->name));

        return redirect()->back()->with('success', "Reminder sent to {$recipient->name}.");
    }

    public function renewalSnooze(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['compliance', 'vetting', 'driver'])],
            'id' => ['required', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days = $validated['days'] ?? 7;

        if ($validated['type'] === 'compliance') {
            $status = HrStaffComplianceStatus::where('tenant_id', $tenantId)->find($validated['id']);
            abort_unless($status, 404);
            $status->update(['next_check_at' => now()->addDays($days)]);
        }

        return redirect()->back()->with('success', "Snoozed for {$days} days.");
    }

    /** @return array{0:?User,1:string,2:?string} */
    private function resolveRenewable(string $type, int $id, ?int $tenantId): array
    {
        return match ($type) {
            'compliance' => (function () use ($id, $tenantId) {
                $s = HrStaffComplianceStatus::where('tenant_id', $tenantId)->with(['user', 'requirement:id,name'])->find($id);
                return [$s?->user, $s?->requirement?->name ?? 'Compliance requirement', optional($s?->expires_at)->toDateString()];
            })(),
            'vetting' => (function () use ($id) {
                $c = StaffBackgroundCheck::with('user')->find($id);
                return [$c?->user, ucfirst(str_replace('_', ' ', (string) $c?->check_type)), optional($c?->expires_at)->toDateString()];
            })(),
            'driver' => (function () use ($id, $tenantId) {
                $d = HrDriverEligibility::where('tenant_id', $tenantId)->with('user')->find($id);
                return [$d?->user, 'Driver licence', optional($d?->licence_expires_at)->toDateString()];
            })(),
            default => [null, '', null],
        };
    }

    /** Active in-tenant users for the given ids (guards cross-tenant bulk writes). */
    private function tenantUsers(array $userIds, ?int $tenantId)
    {
        $scoped = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id');

        return User::whereIn('id', $scoped)->get();
    }
}
