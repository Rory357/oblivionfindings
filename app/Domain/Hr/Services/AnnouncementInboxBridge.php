<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Models\Announcement;

/**
 * Bridges a tenant-scoped, multi-segment {@see HrAnnouncement} onto the global
 * header-bell {@see Announcement} inbox model. Idempotent via the
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
 * Note: the inbox Announcement model is role-scoped and NOT tenant-scoped, while
 * HrAnnouncement is tenant-scoped + multi-segment. Non-role segments
 * (site/department/user) don't map onto audience_roles; in this single-tenant
 * deployment we drop those to "all roles" (audience_roles = null = everyone),
 * which is the agreed pragmatic behaviour.
 */
class AnnouncementInboxBridge
{
    /**
     * Create or update the linked header-bell announcement.
     */
    public function publish(HrAnnouncement $announcement, ?int $tenantId = null): ?Announcement
    {
        $roles = $this->roleAudience($announcement);

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
     * @return array<int,string>|null
     */
    private function roleAudience(HrAnnouncement $announcement): ?array
    {
        $targets = $announcement->relationLoaded('targets')
            ? $announcement->targets
            : $announcement->targets()->get();

        if ($targets->isEmpty() && $announcement->target_audience === 'role' && $announcement->target_value) {
            return [$announcement->target_value];
        }

        $roles = $targets->where('type', 'role')->pluck('value')->filter()->unique()->values()->all();

        return $roles ?: null;
    }
}
