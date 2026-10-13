<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use Illuminate\Support\Carbon;

class AnnouncementRecurrence
{
    /** Called inside the publication transaction with the source row locked. */
    public function scheduleNext(HrAnnouncement $announcement, bool $futureOnly = false): ?HrAnnouncement
    {
        if (! in_array($announcement->recurrence, ['weekly', 'monthly'], true) || ! $announcement->published_at) {
            return null;
        }
        if ($existing = HrAnnouncement::withTrashed()->where('recurrence_source_id', $announcement->id)->first()) {
            return $existing;
        }
        $start = $announcement->published_at->copy()->timezone('Pacific/Auckland');
        $anchor = $this->anchor($announcement);
        $next = $this->nextDate($start, $announcement->recurrence, $anchor);
        while ($futureOnly && $next->lte(now())) {
            $next = $this->nextDate($next, $announcement->recurrence, $anchor);
        }
        if ($announcement->recurrence_ends_at && $next->gt($announcement->recurrence_ends_at)) {
            return null;
        }
        $clone = $announcement->replicate(['status', 'inbox_announcement_id', 'created_at', 'updated_at', 'deleted_at', 'read_version', 'recurrence_source_id', 'recurrence_cancelled_at']);
        $clone->status = 'scheduled';
        $clone->published_at = $next->copy()->utc();
        $clone->read_version = 1;
        $clone->inbox_announcement_id = null;
        $clone->recurrence_source_id = $announcement->id;
        $clone->recurrence_parent_id = $announcement->recurrence_parent_id ?: $announcement->id;
        $clone->recurrence_anchor_at = $anchor->copy()->utc();
        foreach (['expires_at', 'ack_deadline'] as $field) {
            $clone->$field = $this->shiftDeadline($announcement->$field, $start, $next);
        }
        $clone->save();
        foreach ($announcement->targets()->get() as $target) {
            $clone->targets()->create($target->only(['type', 'value']));
        }
        foreach ($announcement->attachments()->get() as $file) {
            // Immutable private blobs; each occurrence has its own audience-bound row.
            $clone->attachments()->create($file->only(['disk', 'path', 'original_name', 'mime', 'size', 'uploaded_by']));
        }

        return $clone->fresh();
    }

    public function anchor(HrAnnouncement $announcement): Carbon
    {
        $root = $announcement->recurrence_parent_id ? HrAnnouncement::withTrashed()->find($announcement->recurrence_parent_id) : $announcement;

        return ($announcement->recurrence_anchor_at ?? $root?->published_at ?? $announcement->published_at)->copy()->timezone('Pacific/Auckland');
    }

    public function nextDate(Carbon $start, string $rule, Carbon $anchor): Carbon
    {
        $start = $start->copy()->timezone('Pacific/Auckland');
        $next = $start->copy()->addWeek();
        if ($rule === 'monthly') {
            $next = $start->copy()->startOfMonth()->addMonth();
            $next->day(min($anchor->day, $next->daysInMonth));
        }

        // A skipped hour moves forward once, without changing the series time.
        return $next->setTime($anchor->hour, $anchor->minute, $anchor->second);
    }

    public function shiftDeadline(?Carbon $deadline, Carbon $start, Carbon $next): ?Carbon
    {
        if (! $deadline) {
            return null;
        }
        $local = $deadline->copy()->timezone('Pacific/Auckland');
        $days = (int) Carbon::parse($start->toDateString(), 'UTC')->diffInDays(Carbon::parse($local->toDateString(), 'UTC'), false);

        return $next->copy()->startOfDay()->addDays($days)->setTime($local->hour, $local->minute, $local->second)->utc();
    }
}
