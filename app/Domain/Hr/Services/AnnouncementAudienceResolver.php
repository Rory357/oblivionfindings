<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Single source of truth for resolving an announcement's audience.
 *
 * Both the publish-notification recipient list (AnnouncementController) and the
 * "of Y acknowledged" denominator (FeedService) delegate here, as does the
 * wizard's live recipient preview and the Tracking roster. Supports the new
 * multi-segment targeting (hr_announcement_targets) and falls back to the
 * legacy single-segment columns for un-migrated rows.
 */
class AnnouncementAudienceResolver
{
    public function __construct(
        private readonly HrAudienceAccessService $audiences,
    ) {}

    /**
     * Resolve an arbitrary set of targets to a unique collection of Users.
     *
     * @param  array<int,array{type:string,value:?string}>  $targets
     */
    public function resolveUsers(array $targets, ?int $excludeUserId = null): Collection
    {
        return $this->audiences->resolveUsers($targets, $excludeUserId);
    }

    /**
     * Recipients for a stored announcement (excludes the creator by default
     * when an id is given — they don't need to be notified of their own post).
     */
    public function resolveForAnnouncement(HrAnnouncement $announcement, ?int $excludeUserId = null): Collection
    {
        return $this->resolveUsers($this->targetsFor($announcement), $excludeUserId);
    }

    public function includesCurrentUser(HrAnnouncement $announcement, User $user): bool
    {
        return $this->resolveUsers($this->targetsFor($announcement))
            ->contains(fn (User $recipient) => (int) $recipient->id === (int) $user->id);
    }

    /**
     * Audience size for the "of Y" denominator — never below 1 so the feed
     * never divides by zero.
     */
    public function countForAnnouncement(HrAnnouncement $announcement): int
    {
        return max(1, $this->resolveForAnnouncement($announcement)->count());
    }

    /**
     * Raw count for the wizard preview (may legitimately be 0 → triggers a
     * "no recipients" warning).
     *
     * @param  array<int,array{type:string,value:?string}>  $targets
     */
    public function count(array $targets): int
    {
        return $this->resolveUsers($targets)->count();
    }

    /**
     * Targets for an announcement, preferring the join table and falling back
     * to the legacy single-segment columns.
     *
     * @return array<int,array{type:string,value:?string}>
     */
    private function targetsFor(HrAnnouncement $announcement): array
    {
        $targets = $announcement->relationLoaded('targets')
            ? $announcement->targets
            : $announcement->targets()->get();

        if ($targets->isNotEmpty()) {
            return $targets->map(fn ($t) => ['type' => $t->type, 'value' => $t->value])->all();
        }

        return [[
            'type' => $announcement->target_audience ?: 'all',
            'value' => $announcement->target_value,
        ]];
    }
}
