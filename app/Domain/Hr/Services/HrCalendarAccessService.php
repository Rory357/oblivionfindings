<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Canonical access boundary for the single-application HR calendar.
 *
 * Legacy storage markers are deliberately absent. Visibility is derived from
 * current-staff eligibility, explicit event audiences, record ownership and
 * the viewer's approved Sites. Direct-object checks conceal inaccessible rows.
 */
final class HrCalendarAccessService
{
    /** @var array<int, bool> */
    private array $currentStaffCache = [];

    /** @var array<int, HrEmployeeProfile|null> */
    private array $profileCache = [];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /** @return array<int, int> */
    public function accessibleSiteIds(User $viewer): array
    {
        return $this->siteAccess->accessibleSiteIds($viewer);
    }

    /** @return Builder<Site> */
    public function visibleSitesQuery(User $viewer): Builder
    {
        return $this->siteAccess->applySiteScope(Site::query(), $viewer);
    }

    /** @return Builder<User> */
    public function visibleCurrentStaffQuery(User $viewer): Builder
    {
        return $this->siteAccess->applyStaffScope(User::query(), $viewer);
    }

    /** @return Builder<HrEmployeeProfile> */
    public function visibleCurrentProfilesQuery(User $viewer): Builder
    {
        return $this->siteAccess->applyCurrentStaffProfileScope(
            HrEmployeeProfile::query(),
            $viewer,
        );
    }

    /** @return Builder<HrDepartment> */
    public function visibleDepartmentsQuery(User $viewer): Builder
    {
        $siteIds = $this->accessibleSiteIds($viewer);

        return HrDepartment::query()
            ->active()
            ->where(function (Builder $departments) use ($siteIds): void {
                // Departments without an explicit Site footprint are
                // application-wide. Site-linked departments must intersect the
                // viewer's canonical Site access.
                $departments->whereDoesntHave('sites');
                if ($siteIds !== []) {
                    $departments->orWhereHas(
                        'sites',
                        fn (Builder $sites) => $sites->whereIn('sites.id', $siteIds),
                    );
                }
            });
    }

    /**
     * Apply only the intrinsic Site boundary in SQL. Audience rules are then
     * evaluated against the complete attendee graph by canViewEvent().
     *
     * @return Builder<HrCalendarEvent>
     */
    public function applySiteScope(Builder $query, User $viewer): Builder
    {
        $siteIds = $this->accessibleSiteIds($viewer);

        return $query->where(function (Builder $events) use ($siteIds): void {
            $events->whereNull('site_id');
            if ($siteIds !== []) {
                $events->orWhereIn('site_id', $siteIds);
            }
        });
    }

    public function applyShiftScope(Builder $query, User $viewer): Builder
    {
        return $this->siteAccess->applyShiftScope($query, $viewer);
    }

    public function canViewEvent(User $viewer, HrCalendarEvent $event): bool
    {
        if ($event->recurrence_parent_id !== null) {
            $event->loadMissing('recurrenceParent.attendees');
            $parent = $event->recurrenceParent;

            return $parent !== null
                && $parent->recurrence_parent_id === null
                && (int) $parent->id !== (int) $event->id
                && (int) ($parent->site_id ?? 0) === (int) ($event->site_id ?? 0)
                && $this->canViewEvent($viewer, $parent);
        }

        if (! $this->siteIsVisible($viewer, $event->site_id)) {
            return false;
        }

        $event->loadMissing('attendees');
        if ($event->attendees->isEmpty()) {
            return $this->canManageAll($viewer)
                || (int) $event->created_by === (int) $viewer->id
                || $this->isCurrent($viewer);
        }

        $groups = $event->attendees->where('audience_type', '!=', 'person');
        $people = $event->attendees->where('audience_type', 'person');
        if ($groups->count() > 1
            || ($groups->isNotEmpty() && $people->isNotEmpty())
            || ! $this->audienceGraphIsCoherent($event, $groups, $people)
        ) {
            return false;
        }

        if ($this->canManageAll($viewer) || (int) $event->created_by === (int) $viewer->id) {
            return true;
        }

        if (! $this->isCurrent($viewer)) {
            return false;
        }

        if ($groups->isEmpty()) {
            return $people->contains(fn ($audience) => (int) $audience->user_id === (int) $viewer->id);
        }

        $profile = $this->profileFor($viewer);

        return $groups->contains(function ($audience) use ($viewer, $profile, $event): bool {
            return match ($audience->audience_type) {
                'org' => true,
                'site' => (int) $event->site_id === (int) $audience->audience_ref
                    && $this->audienceSiteIsVisible($viewer, $audience->audience_ref),
                'department' => $profile !== null
                    && (int) $event->department_id === (int) $audience->audience_ref
                    && (int) $profile->department_id === (int) $audience->audience_ref,
                'team' => $this->teamsMatch($profile?->team, $audience->audience_ref),
                default => false,
            };
        });
    }

    public function assertCanViewEvent(User $viewer, HrCalendarEvent $event): void
    {
        abort_unless($this->canViewEvent($viewer, $event), 404);
    }

    public function canManageEvent(User $viewer, HrCalendarEvent $event): bool
    {
        if (! $this->canViewEvent($viewer, $event)) {
            return false;
        }

        return $this->canManageAll($viewer)
            || ((int) $event->created_by === (int) $viewer->id
                && ($viewer->canDo('calendar.create') || $viewer->canDo('calendar.manage_recurring')));
    }

    public function assertCanManageEvent(User $viewer, HrCalendarEvent $event): void
    {
        abort_unless($this->canManageEvent($viewer, $event), 404);
    }

    public function assertCanUseSite(User $viewer, ?int $siteId): void
    {
        if ($siteId === null) {
            return;
        }

        abort_unless($this->siteIsVisible($viewer, $siteId), 422, 'The selected Site is not available.');
    }

    public function assertCanUseDepartment(User $viewer, ?int $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        abort_unless(
            $this->visibleDepartmentsQuery($viewer)->whereKey($departmentId)->exists(),
            422,
            'The selected department is not available.',
        );
    }

    /** @param array<int, mixed> $userIds */
    public function assertCanInviteUsers(User $viewer, array $userIds): void
    {
        $ids = collect($userIds)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $visibleIds = $this->visibleCurrentStaffQuery($viewer)
            ->whereKey($ids->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values();

        abort_unless($visibleIds->all() === $ids->sort()->values()->all(), 422, 'Every invitee must be current visible staff.');
    }

    public function canonicalVisibleTeam(User $viewer, ?string $team): ?string
    {
        $normalised = HrEmployeeProfile::normalizeTeam($team);
        if ($normalised === null) {
            return null;
        }

        return $this->visibleCurrentProfilesQuery($viewer)
            ->whereNotNull('team')
            ->pluck('team')
            ->map(fn (string $existing) => HrEmployeeProfile::normalizeTeam($existing))
            ->first(fn (?string $existing) => $existing !== null
                && mb_strtolower($existing) === mb_strtolower($normalised));
    }

    /** @return Collection<int, HrCalendarEvent> */
    public function visibleEvents(Collection $events, User $viewer): Collection
    {
        return $events
            ->filter(fn (HrCalendarEvent $event) => $this->canViewEvent($viewer, $event))
            ->values();
    }

    private function canManageAll(User $viewer): bool
    {
        return $viewer->canDo('hr.calendar.manage') || $viewer->canDo('calendar.manage');
    }

    private function siteIsVisible(User $viewer, mixed $siteId): bool
    {
        return $siteId === null
            || in_array((int) $siteId, $this->accessibleSiteIds($viewer), true);
    }

    private function audienceSiteIsVisible(User $viewer, mixed $siteId): bool
    {
        return is_numeric($siteId)
            && in_array((int) $siteId, $this->accessibleSiteIds($viewer), true);
    }

    private function teamsMatch(?string $left, mixed $right): bool
    {
        $left = HrEmployeeProfile::normalizeTeam($left);
        $right = HrEmployeeProfile::normalizeTeam(is_string($right) ? $right : null);

        return $left !== null && $right !== null && mb_strtolower($left) === mb_strtolower($right);
    }

    private function isCurrent(User $viewer): bool
    {
        return $this->currentStaffCache[$viewer->id]
            ??= $this->currentStaff->isCurrent($viewer);
    }

    private function profileFor(User $viewer): ?HrEmployeeProfile
    {
        if (! array_key_exists($viewer->id, $this->profileCache)) {
            $this->profileCache[$viewer->id] = $viewer->hrEmployeeProfile()->first();
        }

        return $this->profileCache[$viewer->id];
    }

    private function audienceGraphIsCoherent(
        HrCalendarEvent $event,
        Collection $groups,
        Collection $people,
    ): bool {
        if ($groups->isEmpty()) {
            return $people->every(fn ($audience) => is_numeric($audience->user_id)
                && (int) $audience->user_id > 0);
        }

        $group = $groups->first();

        return match ($group->audience_type) {
            'org' => blank($group->audience_ref),
            'site' => is_numeric($group->audience_ref)
                && (int) $event->site_id === (int) $group->audience_ref,
            'department' => is_numeric($group->audience_ref)
                && (int) $event->department_id === (int) $group->audience_ref,
            'team' => HrEmployeeProfile::normalizeTeam(
                is_string($group->audience_ref) ? $group->audience_ref : null,
            ) !== null,
            default => false,
        };
    }
}
