<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\CollectorEnrollment;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class CollectorEnrollmentRecoveryPruner
{
    public function prune(int $batchSize = 100, int $maxBatches = 10): int
    {
        if ($batchSize < 1 || $batchSize > 1000 || $maxBatches < 1 || $maxBatches > 100) {
            throw new InvalidArgumentException('Recovery pruning limits are out of range.');
        }

        // Consumption is deliberately NOT a retention boundary: the first reply
        // can be lost after commit. Recovery remains available until token expiry.
        $cutoff = CarbonImmutable::now('UTC');
        $pruned = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $ids = CollectorEnrollment::query()
                ->where('expires_at', '<=', $cutoff)
                ->where(fn ($query) => $query->whereNotNull('recovery_response')->orWhereNotNull('recovery_identity_fingerprint'))
                ->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            // Recheck eligibility in the write itself. UPDATE acquires the same
            // row lock as enrol(), so concurrent recovery/issuance serializes;
            // an expiry extended since selection cannot lose its response.
            $pruned += CollectorEnrollment::query()->whereIn('id', $ids)
                ->where('expires_at', '<=', $cutoff)
                ->where(fn ($query) => $query->whereNotNull('recovery_response')->orWhereNotNull('recovery_identity_fingerprint'))
                ->update(['recovery_response' => null, 'recovery_identity_fingerprint' => null]);
        }

        return $pruned;
    }
}
