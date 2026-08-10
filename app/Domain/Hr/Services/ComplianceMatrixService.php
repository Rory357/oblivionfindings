<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ComplianceMatrixService
{
    public function __construct(
        protected LiveComplianceValidator $liveValidator,
        protected HrCurrentStaffService $currentStaff,
        protected ComplianceRequirementApplicabilityService $applicability,
        protected PeopleMutationLockService $mutationLocks,
    ) {}

    /**
     * Evaluate all current approved staff against the application matrix.
     */
    public function evaluateAllStaff(): int
    {
        $count = 0;
        $this->currentStaff->currentUsersQuery()
            ->with([
                'roles:id,name',
                'hrEmployeeProfile.primarySite:id,type',
            ])
            ->orderBy('users.id')
            ->chunkById(200, function ($users) use (&$count): void {
                foreach ($users as $user) {
                    $this->evaluateStaff($user);
                    $count++;
                }
            }, 'users.id', 'id');

        return $count;
    }

    /**
     * Return exact summaries for the supplied current staff collection.
     * Missing cached rows count as not started.
     *
     * @param  Collection<int, User>  $users
     */
    public function summariesForUsers(Collection $users): Collection
    {
        return $this->applicability->summariesForUsers($users);
    }

    /** @return Collection<int, Collection<int, array>> */
    public function snapshotsForUsers(Collection $users): Collection
    {
        return $this->applicability->snapshotsForUsers($users);
    }

    /** @return Collection<int, HrComplianceRequirement> */
    public function getApplicableRequirements(User $user): Collection
    {
        return $this->applicability->forUser($user);
    }

    /**
     * Determine exact fully-compliant IDs from an already authorized staff set.
     * Staff with no applicable requirements are deliberately not classified as
     * fully compliant.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, int>
     */
    public function fullyCompliantUserIds(Collection $users): array
    {
        return $this->summariesForUsers($users)
            ->filter(fn (array $summary) => $summary['fully_compliant'])
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Return current staff IDs with at least one applicable hard-stop failure.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, int>
     */
    public function hardStopFailureUserIds(Collection $users): array
    {
        return $this->summariesForUsers($users)
            ->filter(fn (array $summary) => $summary['hard_stop_failures'] > 0)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Exact per-person summary; intended for response shaping after the caller
     * has already applied its Site-access boundary.
     */
    public function summaryForUser(User $user): array
    {
        return $this->summariesForUsers(collect([$user]))->get((int) $user->id, [
            'total' => 0,
            'compliant' => 0,
            'expiring_soon' => 0,
            'expired' => 0,
            'not_started' => 0,
            'hard_stop_failures' => 0,
            'hard_stop_expiring' => 0,
            'fully_compliant' => false,
        ]);
    }

    /**
     * Evaluate a single staff member against the application-wide matrix.
     */
    public function evaluateStaff(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Use the same canonical User/Profile lock prefix as every manual
            // compliance mutation. A nightly evaluator can therefore never
            // overwrite a manual record created or edited concurrently.
            $locked = $this->mutationLocks->lock([$user->id]);
            $lockedUser = $locked['users']->get((int) $user->id);
            if (! $lockedUser) {
                return;
            }

            $lockedUser->load('roles:id,name');
            $requirements = $this->getApplicableRequirements($lockedUser);

            foreach ($requirements as $requirement) {
                $existing = HrStaffComplianceStatus::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('requirement_id', $requirement->id)
                    ->lockForUpdate()
                    ->first();

                // A manually recorded status (or active exemption) is authoritative — the
                // nightly source-record sweep must not clobber it. Re-derive only its
                // status from its own dates so it still ages from compliant → expiring →
                // expired, preserving the evidence file, notes and valid_from a manager set.
                if ($existing && ($existing->evidence_type === 'manual' || $existing->exemption_reason)) {
                    $existing->update([
                        'status' => $this->deriveManualStatus($existing, $requirement),
                        'last_checked_at' => now(),
                        'next_check_at' => now()->addDay(),
                    ]);

                    continue;
                }

                $status = $this->checkRequirement($lockedUser, $requirement);
                $statusRow = $existing ?? new HrStaffComplianceStatus([
                    'user_id' => $lockedUser->id,
                    'requirement_id' => $requirement->id,
                ]);
                $statusRow->fill([
                    'status' => $status['status'],
                    'evidence_type' => $status['evidence_type'],
                    'evidence_id' => $status['evidence_id'],
                    'valid_from' => $status['valid_from'],
                    'expires_at' => $status['expires_at'],
                    'last_checked_at' => now(),
                    'next_check_at' => now()->addDay(),
                ])->save();
            }
        }, 3);
    }

    /**
     * Re-derive a manually recorded / exempted status from its own dates. An active
     * exemption holds the row compliant until exempted_until passes; otherwise the
     * stored expires_at drives compliant → expiring_soon → expired.
     */
    protected function deriveManualStatus(HrStaffComplianceStatus $status, HrComplianceRequirement $requirement): string
    {
        if ($status->exemption_reason) {
            if ($status->exempted_until === null || $status->exempted_until->isFuture()) {
                return 'compliant';
            }
        }

        // A status someone explicitly marked not_started stays not_started.
        if ($status->status === 'not_started' && $status->expires_at === null && $status->valid_from === null) {
            return 'not_started';
        }

        if ($status->expires_at === null) {
            return $status->status === 'not_started' ? 'not_started' : 'compliant';
        }

        if ($status->expires_at->isPast()) {
            return 'expired';
        }

        $reminderDays = $requirement->renewal_reminder_days ?: 30;
        if ($status->expires_at->diffInDays(now(), true) <= $reminderDays) {
            return 'expiring_soon';
        }

        return 'compliant';
    }

    /**
     * Check if a user can be assigned to a shift.
     *
     * Uses a hybrid approach:
     *   1. Check cached compliance status (fast, from nightly evaluation)
     *   2. For hard-stop requirements, also validate against live source records
     *      to catch credentials that expired since the last nightly run
     *
     * A hard-stop blocks if EITHER the cached status OR the live check fails.
     */
    public function canAssignToShift(User $user, ?Shift $shift = null): array
    {
        // 1. Cached hard-stop failures (existing behaviour).
        $cachedFailures = $this->getHardStopFailures($user);

        // 2. Live hard-stop validation against source records.
        $liveResult = $this->liveValidator->validateHardStops($user);

        // Merge: combine cached and live failures, deduplicate by requirement code.
        $allFailures = collect($cachedFailures->toArray());

        if (! $liveResult['passed']) {
            $existingCodes = $allFailures->pluck('code')->flip();

            foreach ($liveResult['failures'] as $liveFail) {
                if (! $existingCodes->has($liveFail['code'])) {
                    // Live found a failure the cache missed — use the specific message.
                    $allFailures->push([
                        'requirement' => $liveFail['requirement'],
                        'code' => $liveFail['code'],
                        'status' => 'expired',
                        'reason' => $liveFail['reason'],
                        'expires_at' => $liveFail['expires_at'],
                    ]);
                } else {
                    // Both cache and live failed — upgrade the cached entry with the specific reason.
                    $allFailures = $allFailures->map(function (array $f) use ($liveFail) {
                        if ($f['code'] === $liveFail['code'] && ! isset($f['reason'])) {
                            $f['reason'] = $liveFail['reason'];
                            $f['expires_at'] = $liveFail['expires_at'] ?? $f['expires_at'];
                        }

                        return $f;
                    });
                }
            }
        }

        if ($allFailures->isNotEmpty()) {
            return [
                'allowed' => false,
                'blocked' => true,
                'failures' => $allFailures->values()->toArray(),
                'warnings' => [],
            ];
        }

        $warnings = $this->getSoftWarnings($user);

        return [
            'allowed' => true,
            'blocked' => false,
            'failures' => [],
            'warnings' => $warnings->toArray(),
        ];
    }

    /**
     * Get hard-stop compliance failures for a user.
     *
     * When the caller has eager-loaded hrComplianceStatuses.requirement (as the
     * batch eligibility loader does), the same filter is applied in PHP to avoid
     * a per-user query. The WHERE and output shape mirror the query path exactly.
     */
    public function getHardStopFailures(User $user): Collection
    {
        $requirementIds = $this->applicability->forUser($user, true)->pluck('id');
        if ($requirementIds->isEmpty()) {
            return collect();
        }

        if ($user->relationLoaded('hrComplianceStatuses')) {
            return $user->hrComplianceStatuses
                ->filter(fn ($s) => in_array($s->status, ['expired', 'not_started'], true)
                    && $requirementIds->contains($s->requirement_id)
                    && $s->requirement
                    && $s->requirement->hard_stop
                    && $s->requirement->is_active)
                ->map(fn ($s) => [
                    'requirement' => $s->requirement->name,
                    'code' => $s->requirement->code,
                    'status' => $s->status,
                    'expires_at' => $s->expires_at?->toDateString(),
                ])
                ->values();
        }

        return HrStaffComplianceStatus::where('user_id', $user->id)
            ->whereIn('requirement_id', $requirementIds)
            ->whereIn('status', ['expired', 'not_started'])
            ->whereHas('requirement', fn ($q) => $q->where('hard_stop', true)->where('is_active', true))
            ->with('requirement:id,code,name')
            ->get()
            ->map(fn ($s) => [
                'requirement' => $s->requirement->name,
                'code' => $s->requirement->code,
                'status' => $s->status,
                'expires_at' => $s->expires_at?->toDateString(),
            ]);
    }

    /**
     * Get soft warnings for a user (non-blocking).
     *
     * Consumes the eager-loaded hrComplianceStatuses.requirement relation when
     * present (batch path), otherwise queries. The PHP predicate mirrors the
     * query's WHERE: status = 'expiring_soon' OR (status in [expired,not_started]
     * AND requirement.hard_stop = false).
     */
    public function getSoftWarnings(User $user): Collection
    {
        $requirementIds = $this->applicability->forUser($user)->pluck('id');
        if ($requirementIds->isEmpty()) {
            return collect();
        }

        if ($user->relationLoaded('hrComplianceStatuses')) {
            return $user->hrComplianceStatuses
                ->filter(function ($s) use ($requirementIds) {
                    if (! $requirementIds->contains($s->requirement_id)) {
                        return false;
                    }

                    if ($s->status === 'expiring_soon') {
                        return true;
                    }

                    return in_array($s->status, ['expired', 'not_started'], true)
                        && $s->requirement
                        && ! $s->requirement->hard_stop;
                })
                ->map(fn ($s) => [
                    'requirement' => $s->requirement->name,
                    'code' => $s->requirement->code,
                    'status' => $s->status,
                    'expires_at' => $s->expires_at?->toDateString(),
                ])
                ->values();
        }

        return HrStaffComplianceStatus::where('user_id', $user->id)
            ->whereIn('requirement_id', $requirementIds)
            ->where(function ($q) {
                $q->where('status', 'expiring_soon')
                    ->orWhere(function ($q2) {
                        $q2->whereIn('status', ['expired', 'not_started'])
                            ->whereHas('requirement', fn ($q3) => $q3->where('hard_stop', false));
                    });
            })
            ->with('requirement:id,code,name')
            ->get()
            ->map(fn ($s) => [
                'requirement' => $s->requirement->name,
                'code' => $s->requirement->code,
                'status' => $s->status,
                'expires_at' => $s->expires_at?->toDateString(),
            ]);
    }

    /**
     * Get compliance summary for a site.
     */
    public function getComplianceSummary(?int $siteId = null): array
    {
        $users = $this->currentStaff->currentUsersQuery()
            ->when($siteId !== null, fn ($query) => $query->whereHas(
                'hrEmployeeProfile',
                fn ($profile) => $profile->atSite($siteId),
            ))
            ->get();
        $summaries = $this->summariesForUsers($users);

        return [
            'compliant' => (int) $summaries->sum('compliant'),
            'expiring_soon' => (int) $summaries->sum('expiring_soon'),
            'expired' => (int) $summaries->sum('expired'),
            'not_started' => (int) $summaries->sum('not_started'),
        ];
    }

    protected function checkRequirement(User $user, HrComplianceRequirement $requirement): array
    {
        $result = [
            'status' => 'not_started',
            'evidence_type' => null,
            'evidence_id' => null,
            'valid_from' => null,
            'expires_at' => null,
        ];

        switch ($requirement->check_type) {
            case 'training_course':
                // Canonical link is HrCourse (via the requirement back-link); fall
                // back to the legacy training_course_id so records completed before
                // unification (or backfilled) still satisfy the requirement.
                $hrCourseId = $requirement->hrCourse?->id;
                $legacyRef = $requirement->reference_id;
                $record = null;
                if ($hrCourseId || $legacyRef) {
                    $record = $user->staffTrainingRecords()
                        ->where('status', 'completed')
                        ->where(function ($q) use ($hrCourseId, $legacyRef) {
                            if ($hrCourseId) {
                                $q->orWhere('hr_course_id', $hrCourseId);
                            }
                            if ($legacyRef) {
                                $q->orWhere('training_course_id', $legacyRef);
                            }
                        })
                        ->orderByDesc('completed_at')
                        ->first();
                }

                if ($record) {
                    $result['evidence_type'] = 'training_record';
                    $result['evidence_id'] = $record->id;
                    $result['valid_from'] = $record->completed_at?->toDateString();

                    if ($requirement->validity_months) {
                        $expiresAt = $record->completed_at->addMonths($requirement->validity_months);
                        $result['expires_at'] = $expiresAt->toDateString();

                        if ($expiresAt->isPast()) {
                            $result['status'] = 'expired';
                        } elseif ($expiresAt->isFuture() && $expiresAt->diffInDays(now(), true) <= $requirement->renewal_reminder_days) {
                            $result['status'] = 'expiring_soon';
                        } else {
                            $result['status'] = 'compliant';
                        }
                    } else {
                        $result['status'] = 'compliant';
                    }
                }
                break;

            case 'credential':
                $credential = $user->staffCredentials()
                    ->where('type', $requirement->code)
                    ->orderByDesc('issued_at')
                    ->first();

                if ($credential) {
                    $result['evidence_type'] = 'credential';
                    $result['evidence_id'] = $credential->id;
                    $result['valid_from'] = $credential->issued_at?->toDateString();
                    $result['expires_at'] = $credential->expires_at?->toDateString();

                    if ($credential->expires_at && $credential->expires_at->isPast()) {
                        $result['status'] = 'expired';
                    } elseif ($credential->expires_at && $credential->expires_at->isFuture() && $credential->expires_at->diffInDays(now(), true) <= $requirement->renewal_reminder_days) {
                        $result['status'] = 'expiring_soon';
                    } else {
                        $result['status'] = 'compliant';
                    }
                }
                break;

            case 'background_check':
                $check = $user->staffBackgroundChecks()
                    ->where('check_type', 'police_check')
                    ->whereIn('status', ['clear', 'cleared'])
                    ->orderByDesc('completed_at')
                    ->first();

                if ($check) {
                    $result['evidence_type'] = 'background_check';
                    $result['evidence_id'] = $check->id;
                    $result['valid_from'] = $check->completed_at?->toDateString();

                    if ($requirement->validity_months && $check->completed_at) {
                        $expiresAt = $check->completed_at->addMonths($requirement->validity_months);
                        $result['expires_at'] = $expiresAt->toDateString();

                        if ($expiresAt->isPast()) {
                            $result['status'] = 'expired';
                        } elseif ($expiresAt->isFuture() && $expiresAt->diffInDays(now(), true) <= $requirement->renewal_reminder_days) {
                            $result['status'] = 'expiring_soon';
                        } else {
                            $result['status'] = 'compliant';
                        }
                    } else {
                        $result['status'] = 'compliant';
                    }
                }
                break;

            case 'policy_attestation':
                $attestation = HrPolicyAttestation::where('user_id', $user->id)
                    ->where('policy_id', $requirement->reference_id)
                    ->whereHas('policyVersion', fn ($q) => $q->where('is_current', true))
                    ->orderByDesc('attested_at')
                    ->first();

                if ($attestation) {
                    $result['evidence_type'] = 'attestation';
                    $result['evidence_id'] = $attestation->id;
                    $result['valid_from'] = $attestation->attested_at?->toDateString();
                    $result['status'] = 'compliant';

                    if ($requirement->validity_months) {
                        $expiresAt = $attestation->attested_at->addMonths($requirement->validity_months);
                        $result['expires_at'] = $expiresAt->toDateString();

                        if ($expiresAt->isPast()) {
                            $result['status'] = 'expired';
                        } elseif ($expiresAt->isFuture() && $expiresAt->diffInDays(now(), true) <= $requirement->renewal_reminder_days) {
                            $result['status'] = 'expiring_soon';
                        }
                    }
                }
                break;

            case 'driver_licence':
                $record = $user->hrDriverEligibility;

                if ($record) {
                    $result['evidence_type'] = 'driver_licence';
                    $result['evidence_id'] = $record->id;
                    $result['valid_from'] = optional($record->can_drive_clients_approved_at)->toDateString();
                    $result['expires_at'] = optional($record->licence_expires_at)->toDateString();

                    if ($record->status === 'suspended') {
                        $result['status'] = 'expired';
                    } elseif ($record->licence_expires_at && $record->licence_expires_at->isPast()) {
                        $result['status'] = 'expired';
                    } elseif ($record->licence_expires_at
                        && $record->licence_expires_at->isFuture()
                        && $record->licence_expires_at->diffInDays(now(), true) <= ($requirement->renewal_reminder_days ?: 30)) {
                        $result['status'] = 'expiring_soon';
                    } elseif ($record->status === 'eligible') {
                        $result['status'] = 'compliant';
                    } else {
                        $result['status'] = 'not_started'; // pending_review
                    }
                }
                break;

            case 'manual':
                $existing = HrStaffComplianceStatus::where('user_id', $user->id)
                    ->where('requirement_id', $requirement->id)
                    ->where('evidence_type', 'manual')
                    ->first();

                if ($existing && $existing->status === 'compliant') {
                    return [
                        'status' => $existing->status,
                        'evidence_type' => 'manual',
                        'evidence_id' => $existing->evidence_id,
                        'valid_from' => $existing->valid_from?->toDateString(),
                        'expires_at' => $existing->expires_at?->toDateString(),
                    ];
                }
                break;
        }

        return $result;
    }
}
