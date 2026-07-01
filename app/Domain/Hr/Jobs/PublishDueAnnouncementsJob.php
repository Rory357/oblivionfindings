<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Notifications\AnnouncementPublishedNotification;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\AnnouncementInboxBridge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Flips scheduled announcements live the moment their published_at arrives —
 * closing the silent-future-dated gap where a scheduled notice appeared on
 * screen but never actually alerted anyone. Runs every minute via the
 * scheduler.
 */
class PublishDueAnnouncementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AnnouncementAudienceResolver $resolver, AnnouncementInboxBridge $bridge): void
    {
        HrAnnouncement::query()
            ->where('status', 'scheduled')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with('targets')
            ->get()
            ->each(function (HrAnnouncement $announcement) use ($resolver, $bridge) {
                $announcement->update(['status' => 'published']);

                // Header-bell bridge (high/urgent default on; otherwise still
                // bridge — managers opt notices into the bell via the wizard,
                // which sets requires_acknowledgement/priority on the row).
                $bridge->publish($announcement->fresh('targets'), $announcement->tenant_id);

                // Notify the resolved audience (the creator is excluded).
                $notification = new AnnouncementPublishedNotification($announcement->fresh());
                $resolver->resolveForAnnouncement($announcement, $announcement->tenant_id, $announcement->created_by)
                    ->each(fn ($recipient) => $recipient->notify($notification));

                $this->cloneNextOccurrence($announcement);
            });
    }

    /**
     * Clone the next occurrence of a recurring series as a fresh scheduled row.
     */
    private function cloneNextOccurrence(HrAnnouncement $announcement): void
    {
        if (! in_array($announcement->recurrence, ['weekly', 'monthly'], true) || ! $announcement->published_at) {
            return;
        }

        $next = match ($announcement->recurrence) {
            'weekly' => $announcement->published_at->copy()->addWeek(),
            'monthly' => $announcement->published_at->copy()->addMonth(),
            default => null,
        };

        if (! $next || ($announcement->recurrence_ends_at && $next->gt($announcement->recurrence_ends_at))) {
            return;
        }

        $clone = $announcement->replicate([
            'status', 'inbox_announcement_id', 'created_at', 'updated_at', 'deleted_at',
        ]);
        $clone->status = 'scheduled';
        $clone->published_at = $next;
        $clone->inbox_announcement_id = null;
        $clone->recurrence_parent_id = $announcement->recurrence_parent_id ?: $announcement->id;
        $clone->save();

        foreach ($announcement->targets as $target) {
            $clone->targets()->create(['type' => $target->type, 'value' => $target->value]);
        }
    }
}
