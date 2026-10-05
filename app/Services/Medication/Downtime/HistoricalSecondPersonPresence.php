<?php

namespace App\Services\Medication\Downtime;

use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Completed actual attendance is historical evidence, never current live presence. */
final class HistoricalSecondPersonPresence
{
    public static function fromLocked(User $user, int $siteId, CarbonInterface $at, ?Collection $shifts): ?array
    {
        foreach ($shifts ?? [] as $shift) {
            $start = self::instant($shift, 'actual_starts_at');
            $end = self::instant($shift, 'actual_ends_at');
            $clientSite = $shift->client ? (int) $shift->client->site_id : null;
            if ((int) $shift->user_id === (int) $user->id && $shift->status === 'completed' && $start && $end
                && $start->lte($at) && $end->gte($at)
                && (((int) $shift->site_id === $siteId && ($shift->client_id === null || $clientSite === $siteId))
                    || ($shift->site_id === null && $clientSite === $siteId))) {
                return ['presence_source' => 'completed_actual_shift', 'presence_record_id' => (int) $shift->id,
                    'presence_started_at' => $start->toIso8601String(), 'presence_ends_at' => $end->toIso8601String()];
            }
        }

        return null;
    }

    private static function instant(Shift $shift, string $field): ?Carbon
    {
        $raw = $shift->getRawOriginal($field);

        return filled($raw) ? Carbon::parse($raw, config('app.timezone', 'UTC')) : null;
    }
}
