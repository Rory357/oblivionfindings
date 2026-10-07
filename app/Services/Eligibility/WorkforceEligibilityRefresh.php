<?php

namespace App\Services\Eligibility;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Jobs\RecalculateFutureShiftEligibility;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkforceEligibilityObservation;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\ShiftSignalService;
use App\Services\ShiftStaffEligibilityService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class WorkforceEligibilityRefresh
{
    public const NIGHTLY_SCAN_SOURCE = 'nightly_scan';

    public const NIGHTLY_SCAN_CHUNK_SIZE = 50;

    public function sourceChanged(Model $source, bool $deleted = false): void
    {
        // Source migrations/initial seeds can precede this additive migration.
        if (! Schema::hasTable('workforce_eligibility_rechecks')) {
            return;
        }
        $description = app(WorkforceEligibilitySources::class)->describe($source, $deleted);
        if ($description) {
            $this->stage($source->getTable(), (int) $source->getKey(), $description['fingerprint'], $description['scope']);
        }
    }

    public function membershipChanged(int $userId): void
    {
        if (! Schema::hasTable('workforce_eligibility_rechecks')) {
            return;
        }
        $roles = DB::table('role_user')->where('user_id', $userId)->orderBy('role_id')->pluck('role_id')->all();
        $this->stage('role_membership', $userId, hash('sha256', json_encode($roles)), ['user_ids' => [$userId]]);
    }

    /** Persist intent in the source transaction; queue work only after its outermost commit. */
    public function stage(string $type, int $id, string $fingerprint, array $scope): WorkforceEligibilityRecheck
    {
        return DB::transaction(function () use ($type, $id, $fingerprint, $scope): WorkforceEligibilityRecheck {
            $empty = ['user_ids' => [], 'site_ids' => [], 'client_ids' => [], 'shift_ids' => [], 'all_assigned' => false];
            WorkforceEligibilityRecheck::query()->insertOrIgnore([
                'source_type' => $type, 'source_id' => $id, 'source_fingerprint' => $fingerprint,
                'source_version' => 0, 'user_ids' => '[]', 'site_ids' => '[]', 'client_ids' => '[]', 'shift_ids' => '[]',
                'all_assigned' => false, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $request = WorkforceEligibilityRecheck::query()->where('source_type', $type)->where('source_id', $id)->lockForUpdate()->firstOrFail();
            if ($request->source_version > 0 && hash_equals($request->source_fingerprint, $fingerprint)) {
                return $request;
            }
            // Preserve earlier affected owners/scopes until all their changes have been observed.
            $previous = in_array($request->status, ['pending', 'processing', 'failed'], true) ? $request->only(array_keys($empty)) : $empty;
            $merged = [];
            foreach (['user_ids', 'site_ids', 'client_ids', 'shift_ids'] as $field) {
                $merged[$field] = collect([...($previous[$field] ?? []), ...($scope[$field] ?? [])])
                    ->map(fn ($value): int => (int) $value)->filter(fn (int $value): bool => $value > 0)
                    ->unique()->sort()->values()->all();
            }
            $request->forceFill([
                ...$merged, 'all_assigned' => ($previous['all_assigned'] ?? false) || ($scope['all_assigned'] ?? false),
                'source_fingerprint' => $fingerprint, 'source_version' => $request->source_version + 1,
                'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'completed_at' => null,
                'error_code' => null, 'scanned_count' => 0, 'failed_count' => 0, 'last_shift_id' => 0,
            ])->save();
            $requestId = (int) $request->id;
            $version = $request->source_version;
            DB::afterCommit(fn () => $this->dispatch($requestId, $version));

            return $request;
        }, 3);
    }

    public function requestShift(Shift $shift, string $source = 'manual_shift'): WorkforceEligibilityRecheck
    {
        $existing = WorkforceEligibilityRecheck::query()->where('source_type', $source)->where('source_id', $shift->id)->first();
        if ($existing && in_array($existing->status, ['pending', 'processing'], true)) {
            return $existing;
        }

        return $this->stage($source, (int) $shift->id, hash('sha256', (string) str()->uuid()), ['shift_ids' => [(int) $shift->id]]);
    }

    /** A fixed upper ID bounds this cycle; unfinished cycles are reused across days. */
    public function requestNightlyScan(): WorkforceEligibilityRecheck
    {
        return DB::transaction(function (): WorkforceEligibilityRecheck {
            app(WorkforceMutationGuard::class)->lock();
            $existing = WorkforceEligibilityRecheck::query()->where('source_type', self::NIGHTLY_SCAN_SOURCE)
                ->where(function (Builder $unfinished): void {
                    $unfinished->whereIn('status', ['pending', 'processing'])
                        ->orWhere(fn (Builder $retryable) => $retryable->where('status', 'failed')->where('attempts', '<', 3));
                })->orderByDesc('id')->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $upperShiftId = (int) ($this->nightlyDuties()->max('shifts.id') ?? 0);
            $day = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

            // A scan is recovery metadata, not a source requirement. Its fixed
            // upper Shift ID and fingerprint prevent an ever-growing live scan.
            return $this->stage(self::NIGHTLY_SCAN_SOURCE, $upperShiftId, hash('sha256', 'nightly:'.$day), []);
        }, 3);
    }

    public function dispatch(int $id, int $version): void
    {
        try {
            RefreshWorkforceEligibility::dispatch($id, $version);
        } catch (Throwable $exception) {
            // This callback runs after the source commit. Delivery diagnostics
            // must never turn saved source/intent into an apparent failed save.
            // Pending intent remains eligible for recovery if metadata fails.
            try {
                WorkforceEligibilityRecheck::query()->whereKey($id)->where('source_version', $version)->where('status', 'pending')
                    ->update(['status' => 'failed', 'error_code' => 'queue_unavailable', 'available_at' => now()->addMinute()]);
            } catch (Throwable) {
            }
            try {
                Log::warning('Workforce eligibility refresh could not be queued', ['recheck_id' => $id, 'exception_class' => $exception::class]);
            } catch (Throwable) {
            }
        }
    }

    public function recover(): int
    {
        // A killed worker cannot leave an invisible permanent processing state.
        WorkforceEligibilityRecheck::query()->where('status', 'processing')->where('last_attempt_at', '<', now()->subMinutes(10))
            ->update(['status' => 'failed', 'error_code' => 'worker_interrupted', 'available_at' => now()]);
        $count = 0;
        WorkforceEligibilityRecheck::query()->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 3)
            ->where('available_at', '<=', now())->orderBy('id')->chunkById(100, function ($requests) use (&$count): void {
                foreach ($requests as $request) {
                    $this->dispatch((int) $request->id, $request->source_version);
                    $count++;
                }
            });

        return $count;
    }

    /** Evaluate committed current evidence; historical source payloads never drive this decision. */
    public function process(int $id, int $version): void
    {
        $request = DB::transaction(function () use ($id, $version): ?WorkforceEligibilityRecheck {
            $request = WorkforceEligibilityRecheck::query()->whereKey($id)->lockForUpdate()->first();
            if (! $request || $request->source_version !== $version || in_array($request->status, ['completed', 'processing'], true) || $request->attempts >= 3) {
                return null;
            }
            $request->forceFill(['status' => 'processing', 'attempts' => $request->attempts + 1, 'last_attempt_at' => now()])->save();

            return $request;
        });
        if (! $request) {
            return;
        }
        $resuming = $request->last_shift_id > 0;
        $scanned = $resuming ? (int) $request->scanned_count : 0;
        $failed = $resuming ? (int) $request->failed_count : 0;
        try {
            if ($request->source_type === self::NIGHTLY_SCAN_SOURCE) {
                $this->stageNightlyChunk($id, $version);

                return;
            }
            $query = $this->duties();
            $this->applyScope($query, $request);
            $query->where('shifts.id', '>', $request->last_shift_id);
            $query->select(['shifts.id', 'shifts.user_id'])->chunkById(50, function ($shifts) use ($id, $version, &$scanned, &$failed): bool {
                foreach ($shifts as $shift) {
                    $outcome = $this->observeShift((int) $shift->id, (int) $shift->user_id, $id, $version);
                    if ($outcome === 'superseded') {
                        return false;
                    }
                    if ($outcome !== 'skipped') {
                        $scanned++;
                        $failed += $outcome === 'failed' ? 1 : 0;
                    }
                }
                WorkforceEligibilityRecheck::query()->whereKey($id)->where('source_version', $version)->update([
                    'last_shift_id' => (int) $shifts->last()->id, 'scanned_count' => $scanned, 'failed_count' => $failed,
                ]);

                return true;
            });
            WorkforceEligibilityRecheck::query()->whereKey($id)->where('source_version', $version)->update([
                'status' => $failed ? 'failed' : 'completed', 'error_code' => $failed ? 'evaluation_unavailable' : null,
                'scanned_count' => $scanned, 'failed_count' => $failed,
                'completed_at' => $failed ? null : now(), 'available_at' => $failed ? now()->addMinutes(2) : null,
                // Retry failures, while a killed worker resumes after its last durable chunk.
                'last_shift_id' => 0,
            ]);
        } catch (Throwable $exception) {
            WorkforceEligibilityRecheck::query()->whereKey($id)->where('source_version', $version)->update([
                'status' => 'failed', 'error_code' => 'refresh_unavailable', 'available_at' => now()->addMinutes(2),
                'scanned_count' => $scanned, 'failed_count' => $failed,
            ]);
            Log::warning('Workforce eligibility refresh failed', ['recheck_id' => $id, 'exception_class' => $exception::class]);
            throw new \RuntimeException('Workforce eligibility refresh is temporarily unavailable.');
        }
        if ($failed > 0) {
            throw new \RuntimeException('Workforce eligibility refresh is temporarily unavailable.');
        }
    }

    public function duties(): Builder
    {
        return Shift::query()->whereNotNull('user_id')->whereIn('status', ['scheduled', 'in_progress'])->where('ends_at', '>', now());
    }

    public function nightlyDuties(): Builder
    {
        return $this->duties()->employeeDuties()->where(function (Builder $coverage): void {
            // Retain the existing near-term unpublished backstop, and include
            // every published future employee duty without a date horizon.
            $coverage->where('starts_at', '<', now()->addDays(14))->orWhereNotNull('published_at');
        });
    }

    private function stageNightlyChunk(int $id, int $version): void
    {
        DB::transaction(function () use ($id, $version): void {
            app(WorkforceMutationGuard::class)->lock();
            $scan = WorkforceEligibilityRecheck::query()->whereKey($id)->lockForUpdate()->first();
            if (! $scan || $scan->source_version !== $version || $scan->status !== 'processing') {
                return;
            }
            $shifts = $this->nightlyDuties()->where('shifts.id', '>', $scan->last_shift_id)
                ->where('shifts.id', '<=', $scan->source_id)->orderBy('shifts.id')
                ->limit(self::NIGHTLY_SCAN_CHUNK_SIZE)->get(['shifts.id']);
            foreach ($shifts as $shift) {
                $this->requestShift($shift, 'nightly_shift');
            }
            $lastShiftId = (int) ($shifts->last()?->id ?? $scan->last_shift_id);
            $more = $this->nightlyDuties()->where('shifts.id', '>', $lastShiftId)
                ->where('shifts.id', '<=', $scan->source_id)->exists();
            $scan->forceFill([
                'last_shift_id' => $lastShiftId, 'scanned_count' => $scan->scanned_count + $shifts->count(),
                'failed_count' => 0, 'error_code' => null, 'status' => $more ? 'pending' : 'completed',
                'source_version' => $version + ($more ? 1 : 0), 'attempts' => $more ? 0 : $scan->attempts,
                'available_at' => $more ? now() : null, 'completed_at' => $more ? null : now(),
            ])->save();
            // The existing minute recovery dispatcher queues the next version.
            // Never self-dispatch here: sync queues must not recurse through an
            // unbounded scan in one request. Each delivery stages at most 50.
            Log::info('Nightly workforce eligibility refresh scan progressed', [
                'recheck_id' => $scan->id, 'staged_count' => $scan->scanned_count, 'complete' => ! $more,
            ]);
        }, 3);
    }

    private function applyScope(Builder $query, WorkforceEligibilityRecheck $request): void
    {
        if ($request->all_assigned) {
            return;
        }
        $query->where(function (Builder $scope) use ($request): void {
            $scope->whereIn('shifts.id', $request->shift_ids ?? [])->orWhereIn('shifts.user_id', $request->user_ids ?? [])
                ->orWhereIn('shifts.client_id', $request->client_ids ?? [])
                ->orWhere(function (Builder $sites) use ($request): void {
                    $sites->whereIn('shifts.site_id', $request->site_ids ?? [])
                        ->orWhere(function (Builder $legacy) use ($request): void {
                            $legacy->whereNull('shifts.site_id')->whereHas('client', fn (Builder $client) => $client->whereIn('site_id', $request->site_ids ?? []));
                        });
                });
        });
    }

    private function observeShift(int $shiftId, int $userId, int $requestId, int $version): string
    {
        return DB::transaction(function () use ($shiftId, $userId, $requestId, $version): string {
            app(WorkforceMutationGuard::class)->lock();
            // Match source writers: application, User/RBAC/profile, Shift, then intent.
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            if ($user) {
                $user = app(AuthorizationEvidenceLockService::class)->lockForUser($userId, []);
                $user->setRelation('hrEmployeeProfile', HrEmployeeProfile::withTrashed()->where('user_id', $userId)->lockForUpdate()->first());
            }
            $shift = $this->duties()->whereKey($shiftId)->lockForUpdate()->first();
            $request = WorkforceEligibilityRecheck::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $request || $request->source_version !== $version || $request->status !== 'processing') {
                return 'superseded';
            }
            if (! $shift || (int) $shift->user_id !== $userId) {
                return 'skipped'; // Its own committed assignment event will refresh the new worker.
            }
            $shift->load(['client:id,site_id', 'site:id,name']);
            $observation = WorkforceEligibilityObservation::query()->firstOrNew(['shift_id' => $shiftId]);
            $sameWorker = (int) $observation->user_id === $userId;
            if (! $sameWorker) {
                $observation->forceFill(['posture' => 'unverified', 'block_count' => null, 'warning_count' => null,
                    'failed_rules' => [], 'observed_versions' => [], 'last_successful_at' => null, 'condition_fingerprint' => null]);
            }
            $observation->forceFill(['user_id' => $userId, 'shift_fingerprint' => $this->shiftFingerprint($shift), 'checked_at' => now()]);
            try {
                // Resolve a fresh rule stack so a long-lived queue worker does not reuse fatigue/source memos.
                if (! $user) {
                    throw new \RuntimeException('Assigned account unavailable.');
                }
                // Canonical assignment checks also consult HR's projection. Use its existing
                // manual-evidence/exemption-preserving projector so renewal is current too.
                app(ComplianceMatrixService::class)->evaluateStaff($user);
                // Freshness metadata observes the projector's status event in this same commit.
                $observedVersions = WorkforceEligibilityRecheck::query()->outstanding()->affecting($shift)
                    ->pluck('source_version', 'id')->all();
                $result = app(ShiftStaffEligibilityService::class)->evaluate($shift, $user);
                $accountCurrent = true;
                try {
                    // The existing intrinsic Shift/Site/approved-worker boundary is observation evidence only.
                    app(UserSiteAccessService::class)->assertCanAccessShift($user, $shift);
                } catch (HttpExceptionInterface) {
                    $accountCurrent = false;
                }
                $rules = collect($result->checked_rules)->filter(fn (array $rule): bool => ! ($rule['passed'] ?? false))
                    ->map(fn (array $rule): array => ['rule' => (string) $rule['rule'], 'severity' => (string) $rule['severity']])
                    ->sortBy('rule')->values()->all();
                if (! $accountCurrent) {
                    $rules[] = ['rule' => 'assignment_account_review', 'severity' => 'review'];
                }
                $posture = $result->hasBlocks() ? 'blocked' : (! $accountCurrent ? 'review' : ($result->hasWarnings() ? 'warning' : 'clear'));
            } catch (Throwable $exception) {
                // Preserve the last successful posture. Failure is never presented as cleared evidence.
                $observation->forceFill(['failed_at' => now(), 'error_code' => 'evaluation_unavailable'])->save();
                Log::warning('Workforce duty eligibility is unavailable', ['shift_id' => $shiftId, 'exception_class' => $exception::class]);

                return 'failed';
            }
            $condition = hash('sha256', json_encode([$userId, $posture, $rules], JSON_THROW_ON_ERROR));
            $changed = $observation->condition_fingerprint !== $condition;
            $observation->forceFill([
                'posture' => $posture, 'block_count' => count($result->blocking_reasons), 'warning_count' => count($result->warnings),
                'failed_rules' => $rules, 'observed_versions' => $observedVersions,
                'last_successful_at' => now(), 'failed_at' => null, 'error_code' => null,
                'condition_fingerprint' => $condition,
                'condition_version' => $observation->condition_version + ($changed ? 1 : 0),
            ])->save();
            if ($changed && in_array($posture, ['blocked', 'review'], true)) {
                // Historical signals are retained. Corrected observations never auto-resolve other alerts.
                $signal = app(ShiftSignalService::class)->emitForShift($shift, RecalculateFutureShiftEligibility::SIGNAL_TYPE, 'high', now(), [
                    'blocking_reasons' => ['Current assignment evidence needs review. Open the roster to recheck this duty.'],
                    'failed_rules' => $rules, 'checked_at' => now()->toIso8601String(),
                ], 'observation:'.$observation->condition_version);
                if ($signal->wasRecentlyCreated) {
                    DB::afterCommit(fn () => (new RecalculateFutureShiftEligibility)->notifyEligibilityReview($shift, [
                        'Current assignment evidence needs review. Open the roster to recheck this duty.',
                    ]));
                }
            }

            // Signal persistence failures roll back the observation too, allowing a genuine retry.
            return 'success';
        }, 3);
    }

    public function shiftFingerprint(Shift $shift): string
    {
        $fields = WorkforceEligibilitySources::definitions()[Shift::class]['fields'];

        return hash('sha256', json_encode(array_intersect_key($shift->getAttributes(), array_flip($fields)), JSON_THROW_ON_ERROR));
    }
}
