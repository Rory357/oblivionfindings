<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;

class AnnouncementRepeatEditing
{
    public function __construct(private readonly AnnouncementRecurrence $recurrence) {}

    /** The controller holds the edited row's lock and enclosing transaction. */
    public function synchronise(HrAnnouncement $announcement, HrAnnouncement $before): void
    {
        $cadenceChanged = $before->recurrence !== $announcement->recurrence;
        $dateChanged = $before->published_at?->toISOString() !== $announcement->published_at?->toISOString();
        $endChanged = $before->recurrence_ends_at?->toISOString() !== $announcement->recurrence_ends_at?->toISOString();
        $statusChanged = $before->status !== $announcement->status;
        if (! $cadenceChanged && ! $dateChanged && ! $endChanged && ! $statusChanged) {
            return;
        }

        if ($statusChanged && $announcement->status !== 'archived') {
            $announcement->recurrence_cancelled_at = null;
            $announcement->save();
        }

        if ($announcement->published_at && ($cadenceChanged || $dateChanged)) {
            $announcement->recurrence_anchor_at = $announcement->published_at;
            $announcement->save();
        }
        // Drafting/rescheduling a published notice pauses its next occurrence
        // until that notice is deliberately published again.
        $enabled = in_array($announcement->recurrence, ['weekly', 'monthly'], true)
            && $announcement->status === 'published' && $announcement->published_at;
        $predecessor = $announcement;
        while ($next = HrAnnouncement::withTrashed()->where('recurrence_source_id', $predecessor->id)->lockForUpdate()->first()) {
            if ($next->trashed()) {
                return;
            }
            if ($next->status === 'published') {
                // Published history and its receipt versions are unchanged.
                $predecessor = $next;

                continue;
            }
            if ($next->status === 'archived' && ! $next->recurrence_cancelled_at) {
                if (HrAnnouncement::withTrashed()->where('recurrence_source_id', $next->id)->exists()) {
                    $predecessor = $next;

                    continue;
                }

                // An independently archived occurrence is not restarted here.
                return;
            }

            if ($cadenceChanged) {
                $next->recurrence = $announcement->recurrence;
            }
            if ($endChanged) {
                $next->recurrence_ends_at = $announcement->recurrence_ends_at;
            }
            if ($cadenceChanged || $dateChanged) {
                $next->recurrence_anchor_at = $announcement->published_at ? $this->recurrence->anchor($announcement)->utc() : null;
            }
            if ($next->status === 'draft') {
                // An editor's unsent work stays private until explicitly sent.
                $next->save();

                return;
            }

            $date = $next->published_at;
            $rule = $next->recurrence ?: $announcement->recurrence;
            if ($predecessor->published_at && in_array($rule, ['weekly', 'monthly'], true)
                && ($cadenceChanged || $dateChanged || ($enabled && $next->recurrence_cancelled_at && (! $date || $date->lte(now()))))) {
                $anchor = $this->recurrence->anchor($next);
                $date = $this->recurrence->nextDate($predecessor->published_at, $rule, $anchor);
                while ($date->lte(now())) {
                    $date = $this->recurrence->nextDate($date, $rule, $anchor);
                }
            }
            // A paused repeat still follows an edited publication date, so
            // publishing its parent later resumes the new schedule.
            if ($date && $next->published_at && ! $date->eq($next->published_at)) {
                foreach (['expires_at', 'ack_deadline'] as $field) {
                    $next->$field = $this->recurrence->shiftDeadline($next->$field, $next->published_at->copy()->timezone('Pacific/Auckland'), $date->copy()->timezone('Pacific/Auckland'));
                }
                $next->published_at = $date->copy()->utc();
            }
            if (! $enabled || ! $date || ($next->recurrence_ends_at && $date->gt($next->recurrence_ends_at))) {
                $next->status = 'archived';
                $next->recurrence_cancelled_at ??= now();
            } else {
                $next->published_at = $date->copy()->utc();
                $next->status = 'scheduled';
                $next->recurrence_cancelled_at = null;
            }
            $next->save();

            return;
        }

        if ($enabled) {
            // Explicitly starting a repeat on an older notice schedules the
            // next future date, without flooding staff with past occurrences.
            $basis = clone $predecessor;
            $basis->recurrence = $announcement->recurrence;
            $basis->recurrence_ends_at = $announcement->recurrence_ends_at;
            $basis->recurrence_anchor_at = $this->recurrence->anchor($announcement)->utc();
            $this->recurrence->scheduleNext($basis, futureOnly: true);
        }
    }
}
