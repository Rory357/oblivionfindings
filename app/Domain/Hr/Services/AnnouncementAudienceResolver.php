<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrEmployeeProfile;
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
    /**
     * Resolve an arbitrary set of targets to a unique collection of Users.
     *
     * @param  array<int,array{type:string,value:?string}>  $targets
     */
    public function resolveUsers(array $targets, int $tenantId, ?int $excludeUserId = null): Collection
    {
        $query = HrEmployeeProfile::query()
            ->forTenant($tenantId)
            ->active()
            ->whereNotNull('user_id')
            ->with('user.roles:id,name');

        $hasAll = collect($targets)->contains(fn ($t) => ($t['type'] ?? null) === 'all');

        if (! $hasAll) {
            $query->where(function ($outer) use ($targets) {
                $matched = false;

                foreach ($targets as $target) {
                    $type = $target['type'] ?? null;
                    $value = trim((string) ($target['value'] ?? ''));

                    if ($type !== 'all' && $value === '') {
                        continue;
                    }

                    $matched = true;

                    switch ($type) {
                        case 'site':
                            $outer->orWhere(function ($q) use ($value) {
                                if (is_numeric($value)) {
                                    $q->where('primary_site_id', (int) $value)
                                        ->orWhereJsonContains('secondary_site_ids', (int) $value);
                                } else {
                                    $q->whereRaw('1 = 0');
                                }
                            });
                            break;

                        case 'department':
                            $outer->orWhere(function ($q) use ($value) {
                                $q->where('department', $value);
                                if (is_numeric($value)) {
                                    $q->orWhere('department_id', (int) $value);
                                }
                            });
                            break;

                        case 'role':
                            $outer->orWhere(function ($q) use ($value) {
                                $q->where('position_role', $value)
                                    ->orWhereHas('user', fn ($u) => $u->where('role', $value))
                                    ->orWhereHas('user.roles', fn ($r) => $r->where('name', $value));
                            });
                            break;

                        case 'user':
                            if (is_numeric($value)) {
                                $outer->orWhere('user_id', (int) $value);
                            }
                            break;
                    }
                }

                if (! $matched) {
                    $outer->whereRaw('1 = 0');
                }
            });
        }

        return $query->get()
            ->pluck('user')
            ->filter()
            ->when($excludeUserId, fn (Collection $c) => $c->reject(fn ($u) => (int) $u->id === (int) $excludeUserId))
            ->unique('id')
            ->values();
    }

    /**
     * Recipients for a stored announcement (excludes the creator by default
     * when an id is given — they don't need to be notified of their own post).
     */
    public function resolveForAnnouncement(HrAnnouncement $announcement, ?int $tenantId = null, ?int $excludeUserId = null): Collection
    {
        $tenantId ??= (int) $announcement->tenant_id;

        return $this->resolveUsers($this->targetsFor($announcement), (int) $tenantId, $excludeUserId);
    }

    /**
     * Audience size for the "of Y" denominator — never below 1 so the feed
     * never divides by zero.
     */
    public function countForAnnouncement(HrAnnouncement $announcement, ?int $tenantId = null): int
    {
        return max(1, $this->resolveForAnnouncement($announcement, $tenantId)->count());
    }

    /**
     * Raw count for the wizard preview (may legitimately be 0 → triggers a
     * "no recipients" warning).
     *
     * @param  array<int,array{type:string,value:?string}>  $targets
     */
    public function count(array $targets, int $tenantId): int
    {
        return $this->resolveUsers($targets, $tenantId)->count();
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
