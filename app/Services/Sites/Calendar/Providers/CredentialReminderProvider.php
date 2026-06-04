<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteCredential;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * Credentials carry no explicit expiry, so a rotation-due date is derived from a
 * base date + a configurable cadence (sites.calendar.credential_rotation_days,
 * default 90). The base is last_rotated_at when present, else created_at — so
 * never-rotated credentials (the most likely to be forgotten) still surface a
 * first-rotation obligation instead of being silently skipped.
 */
class CredentialReminderProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'credential';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        $cadenceDays = (int) (function_exists('settings')
            ? settings('sites.calendar.credential_rotation_days', 90)
            : 90);
        $cadenceDays = $cadenceDays > 0 ? $cadenceDays : 90;

        $items = [];

        $credentials = SiteCredential::query()
            ->whereIn('site_id', $siteIds)
            ->with('site:id,name,type')
            ->get();

        foreach ($credentials as $credential) {
            // Fall back to created_at for never-rotated credentials.
            $base = $credential->last_rotated_at ?? $credential->created_at;
            if (! $base) {
                continue;
            }

            $neverRotated = $credential->last_rotated_at === null;
            $due = $base->copy()->addDays($cadenceDays);
            if (! $this->inRange($due, $start, $end)) {
                continue;
            }

            $items[] = new CalendarItem(
                id: "credential-{$credential->id}",
                source: 'credential',
                group: 'auto',
                title: ($credential->label ?: 'Credential').' — '.($neverRotated ? 'first rotation due' : 'rotation due'),
                start: $this->isoDate($due),
                allDay: true,
                status: $due->lt(Carbon::today()) ? 'overdue' : 'scheduled',
                ref: 'CRED-'.$credential->id,
                site: $this->siteArray($credential->site),
                // Unified Vendor Directory & Access Vault (sites.vendors.global),
                // pre-filtered to this site and opened on the Credentials tab —
                // not the legacy per-site /sites/{id}/credentials index.
                link: "/vendors?site_id={$credential->site_id}&tab=credentials",
            );
        }

        return $items;
    }
}
