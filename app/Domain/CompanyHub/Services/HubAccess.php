<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\User;
use App\Services\UserSiteAccessService;

class HubAccess
{
    public function __construct(
        private readonly HrCurrentStaffService $staff,
        private readonly UserSiteAccessService $sites,
    ) {}

    public function canEnter(User $user): bool
    {
        return $user->canDo('company_hub.view') && $this->staff->isCurrent($user);
    }

    public function assertEnter(User $user): void
    {
        abort_unless($this->canEnter($user), 403);
    }

    public function audience(User $user, array $content): bool
    {
        $audience = $content['audience'] ?? ['type' => 'all', 'ids' => []];

        return match ($audience['type'] ?? '') {
            'all' => true,
            'sites' => array_intersect($audience['ids'] ?? [], $this->sites->accessibleSiteIds($user)) !== [],
            'departments' => in_array((int) $user->hrEmployeeProfile?->department_id, $audience['ids'] ?? [], true),
            default => false,
        };
    }

    public function canEdit(User $user, HubPage $page): bool
    {
        return $this->canEnter($user)
            && $this->canAuthorAudience($user, $page->draft)
            && ($page->kind !== 'home' || $user->canDo('company_hub.layout'))
            && ($user->canDo('company_hub.edit_all') || ((int) $page->owner_id === (int) $user->id && $user->canDo('company_hub.write')));
    }

    public function canAuthorAudience(User $user, array $content): bool
    {
        $type = $content['audience']['type'] ?? '';
        $ids = $content['audience']['ids'] ?? [];
        if ($type === 'all') {
            return $ids === [];
        }
        $allowed = match ($type) {
            'sites' => $this->sites->accessibleSiteIds($user),
            'departments' => app(HrCalendarAccessService::class)->visibleDepartmentsQuery($user)->pluck('id')->all(),
            default => [],
        };

        return $ids !== [] && array_diff($ids, $allowed) === [];
    }

    public function assertEdit(User $user, HubPage $page): void
    {
        abort_unless($this->canEdit($user, $page), 404);
    }

    public function visibleRevision(User $user, HubPage $page): ?HubRevision
    {
        if (! $this->canEnter($user)) {
            return null;
        }
        $revision = $page->liveRevision();

        return $revision && $this->audience($user, $revision->content) ? $revision : null;
    }

    public function assertRead(User $user, HubPage $page): HubRevision
    {
        $revision = $this->visibleRevision($user, $page);
        abort_unless($revision, 404);

        return $revision;
    }

    public function capabilities(User $user): array
    {
        return collect(['write', 'edit_all', 'publish', 'layout', 'moderate', 'celebrations'])
            ->mapWithKeys(fn ($key) => [$key => $this->canEnter($user) && $user->canDo('company_hub.'.$key)])->all();
    }
}
