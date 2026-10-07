<?php

namespace App\Services\Eligibility;

use App\Models\Shift;
use App\Models\User;
use App\Models\WorkforceEligibilityObservation;
use App\Models\WorkforceEligibilityRecheck;

final class WorkforceEligibilityRefreshPresenter
{
    public function present(Shift $shift, User $actor): array
    {
        $observation = WorkforceEligibilityObservation::query()->where('shift_id', $shift->id)->first();
        $sameWorker = $observation && (int) $observation->user_id === (int) $shift->user_id;
        $covered = $sameWorker ? ($observation->observed_versions ?? []) : [];
        $outstanding = WorkforceEligibilityRecheck::query()->outstanding()->affecting($shift)->get(['id', 'source_version', 'status', 'error_code'])
            ->filter(fn (WorkforceEligibilityRecheck $request): bool => (int) ($covered[$request->id] ?? 0) !== $request->source_version);
        $failedRequest = $outstanding->firstWhere('status', 'failed');
        $error = $failedRequest?->error_code ?: ($sameWorker ? $observation->error_code : null);
        if ($failedRequest) {
            $freshness = 'failed';
        } elseif ($outstanding->isNotEmpty()) {
            $freshness = 'pending';
            $error = null;
        } elseif (! $sameWorker || ! $observation->last_successful_at) {
            $freshness = $error ? 'failed' : 'unverified';
        } elseif ($error) {
            $freshness = 'failed';
        } elseif ($observation->shift_fingerprint !== app(WorkforceEligibilityRefresh::class)->shiftFingerprint($shift)
            || $observation->last_successful_at->lt(now()->subDay())) {
            $freshness = 'stale';
        } else {
            $freshness = 'current';
        }
        $canRetry = $actor->isApproved() && $actor->canDo('shifts.update');

        return [
            'shift_id' => (int) $shift->id, 'user_id' => $shift->user_id ? (int) $shift->user_id : null,
            'starts_at' => $shift->starts_at?->toIso8601String(), 'ends_at' => $shift->ends_at?->toIso8601String(),
            'site_id' => $shift->site_id ?: $shift->client?->site_id,
            'posture' => $sameWorker ? $observation->posture : 'unverified', 'freshness' => $freshness,
            'checked_at' => $sameWorker ? $observation->checked_at?->toIso8601String() : null,
            'last_successful_at' => $sameWorker ? $observation->last_successful_at?->toIso8601String() : null,
            'block_count' => $sameWorker ? $observation->block_count : null,
            'warning_count' => $sameWorker ? $observation->warning_count : null,
            'error_code' => $error, 'can_retry' => $canRetry,
            'retry_url' => $canRetry ? route('operations.workforce.eligibility-refresh.retry', $shift) : null,
        ];
    }
}
