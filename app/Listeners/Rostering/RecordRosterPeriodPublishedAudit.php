<?php

namespace App\Listeners\Rostering;

use App\Events\RosterPeriodPublished;
use App\Services\AuditLogger;

/**
 * Writes an audit-log row whenever a roster period is published or
 * republished. Deliberately an audit entry, NOT a client TimelineEvent —
 * roster planning decisions would pollute care timelines (see
 * docs/rostering-frontline-end-to-end-audit.md §6).
 *
 * Runs synchronously inside the publish transaction; AuditLogger::log()
 * try/catches internally so a logging failure can never break publishing.
 */
class RecordRosterPeriodPublishedAudit
{
    public function handle(RosterPeriodPublished $event): void
    {
        AuditLogger::log('rostering.period.published', $event->period, [
            'roster_period_id' => $event->period->id,
            'actor_id' => $event->actor->id,
            'republished' => $event->republished,
            'site_id' => $event->period->site_id,
            'week_start' => optional($event->period->week_start)->toDateString(),
            'version' => $event->period->version,
            'shift_count' => (int) $event->period->shift_count,
        ]);
    }
}
