<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\SiteCredential;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * Credentials carry no explicit expiry, so a rotation-due date is derived from
 * last_rotated_at + a configurable cadence (sites.calendar.credential_rotation_days,
 * default 90). Never-rotated credentials are skipped to avoid noise.
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
            ->whereNotNull('last_rotated_at')
            ->with('site:id,name,type')
            ->get();

        foreach ($credentials as $credential) {
            $due = $credential->last_rotated_at->copy()->addDays($cadenceDays);
            if (! $this->inRange($due, $start, $end)) {
                continue;
            }

            $items[] = new CalendarItem(
                id: "credential-{$credential->id}",
                source: 'credential',
                group: 'auto',
                title: ($credential->label ?: 'Credential').' — rotation due',
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
