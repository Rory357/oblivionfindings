<?php

namespace App\Services\Medication\EmergencyAccess;

use App\Models\ClientBreakGlassAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** The exception is restricted to a queued dose captured inside the actual grant window. */
final class OfflineGrantEvidence
{
    public static function eligible(ClientBreakGlassAccess $grant, array $envelope, CarbonInterface $clinicalAt, bool $requiresSecondPerson): bool
    {
        if ($requiresSecondPerson || ($envelope['queued_offline'] ?? null) !== true
            || ! is_string($envelope['captured_offline_at'] ?? null)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $envelope['captured_offline_at'])
            || $grant->created_at === null || $grant->expires_at === null) {
            return false;
        }
        try {
            $captured = CarbonImmutable::parse($envelope['captured_offline_at']);
            if ($captured->format('Y-m-d\TH:i:s') !== substr($envelope['captured_offline_at'], 0, 19)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        $end = $grant->ended_at ?? $grant->deleted_at ?? $grant->expires_at;
        if ($end->gt($grant->expires_at)) {
            $end = $grant->expires_at;
        }

        return $captured->gte($grant->created_at) && $captured->lt($end) && $captured->lte(now())
            && $clinicalAt->gte($grant->created_at) && $clinicalAt->lt($end);
    }
}
