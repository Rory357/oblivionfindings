<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Models\Announcement;

/**
 * Bridges a multi-segment {@see HrAnnouncement} onto the application header-bell
 * {@see Announcement} inbox model. Idempotent via the
 * hr_announcements.inbox_announcement_id FK so re-publishing or editing never
 * creates a duplicate bell entry.
 *
 * Mapping (lossy by design):
 *   title              -> title
 *   content (plain)    -> body
 *   role targets       -> audience_roles[]   (best effort)
 *   published_at       -> starts_at
 *   expires_at         -> ends_at
 *   status=published   -> is_active=true
 *
 * The header-bell model can express all-staff and role audiences only. A Site,
 * department, person, or mixed audience is deliberately not bridged because
 * widening it to every role would disclose a targeted notice.
 */
class AnnouncementInboxBridge
{
    /**
     * Create or update the linked header-bell announcement.
     */
    public function publish(HrAnnouncement $announcement): ?Announcement
    {
        $roles = $this->roleAudience($announcement);
        if ($roles === false) {
            $this->withdraw($announcement);

            return null;
        }

        $attributes = [
            'title' => $announcement->title,
            'body' => $announcement->content,
            'created_by' => $announcement->created_by,
            'audience_roles' => $roles,
            'starts_at' => $announcement->published_at,
            'ends_at' => $announcement->expires_at,
            'is_active' => true,
        ];

        if ($announcement->inbox_announcement_id) {
            $inbox = Announcement::find($announcement->inbox_announcement_id);
            if ($inbox) {
                $inbox->update($attributes);

                return $inbox;
            }
        }

        $inbox = Announcement::create($attributes);
        $announcement->forceFill(['inbox_announcement_id' => $inbox->id])->saveQuietly();

        return $inbox;
    }

    /**
     * Deactivate the linked header-bell announcement (on archive / unpublish /
     * delete). Leaves the FK so a later re-publish updates the same row.
     */
    public function withdraw(HrAnnouncement $announcement): void
    {
        if (! $announcement->inbox_announcement_id) {
            return;
        }

        $inbox = Announcement::find($announcement->inbox_announcement_id);
        $inbox?->update(['is_active' => false]);
    }

    /**
     * Role names amongst the announcement's targets, or null (= everyone) when
     * there are no role segments.
     *
     * @return array<int,string>|false|null
     */
    private function roleAudience(HrAnnouncement $announcement): array|false|null
    {
        $targets = $announcement->relationLoaded('targets')
            ? $announcement->targets
            : $announcement->targets()->get();

        if ($targets->isEmpty()) {
            return match ($announcement->target_audience) {
                'all', null, '' => null,
                'role' => $announcement->target_value ? [$announcement->target_value] : false,
                default => false,
            };
        }

        if ($targets->contains(fn ($target) => ! in_array($target->type, ['all', 'role'], true))
            || ($targets->contains(fn ($target) => $target->type === 'all') && $targets->count() !== 1)) {
            return false;
        }

        if ($targets->contains(fn ($target) => $target->type === 'all')) {
            return null;
        }

        $roles = $targets->where('type', 'role')->pluck('value')->filter()->unique()->values()->all();

        return $roles ?: false;
    }
}
