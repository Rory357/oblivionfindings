<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Notifications\AnnouncementPublishedNotification;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\AnnouncementInboxBridge;
use App\Domain\Hr\Services\AnnouncementRecurrence;
use App\Domain\Hr\Services\AnnouncementRepeatEditing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PublishDueAnnouncementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AnnouncementAudienceResolver $resolver, AnnouncementInboxBridge $bridge): void
    {
        // Snapshot IDs so newly cloned dates cannot form an unbounded catch-up loop.
        $ids = HrAnnouncement::query()->where('status', 'scheduled')->whereNotNull('published_at')->where('published_at', '<=', now())->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $resolver, $bridge) {
                $announcement = HrAnnouncement::whereKey($id)->lockForUpdate()->first();
                if (! $announcement || $announcement->status !== 'scheduled' || ! $announcement->published_at?->lte(now())) {
                    return;
                }
                // Do not alert staff to expired occurrences after downtime.
                if ($announcement->expires_at && ! $announcement->expires_at->isFuture()) {
                    $announcement->update(['status' => 'archived']);
                    $bridge->withdraw($announcement);
                } else {
                    $before = clone $announcement;
                    $announcement->update(['status' => 'published']);
                    app(AnnouncementRepeatEditing::class)->synchronise($announcement, $before);
                    $announcement->push_to_bell ? $bridge->publish($announcement) : $bridge->withdraw($announcement);
                    $notification = (new AnnouncementPublishedNotification($announcement))->afterCommit();
                    $resolver->resolveForAnnouncement($announcement, $announcement->created_by)
                        ->each(fn ($recipient) => $recipient->notify($notification));
                }
                app(AnnouncementRecurrence::class)->scheduleNext($announcement);
            });
        }
    }
}
