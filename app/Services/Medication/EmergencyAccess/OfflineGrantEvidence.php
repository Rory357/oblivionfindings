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
            || ! preg_match('/T.*(?:Z|[+-]\d{2}:\d{2})$/', $envelope['captured_offline_at'])
            || $grant->created_at === null || $grant->expires_at === null) {
            return false;
        }
        try {
            $captured = CarbonImmutable::parse($envelope['captured_offline_at']);
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
