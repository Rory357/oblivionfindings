<?php

namespace App\Policies;

use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

class SafeguardingConcernPolicy
{
    private const SITE_BYPASS_PERMISSIONS = UserSiteAccessService::SAFEGUARDING_SITE_BYPASS_PERMISSIONS;

    /** P2: the morph types a concern uses when its alleged perpetrator is a staff member. */
    public const STAFF_PERPETRATOR_TYPES = ['staff', User::class];

    /** P2: what someone named as the alleged perpetrator sees. It doesn't say why, so it doesn't tell them. */
    public const NAMED_PERSON_MESSAGE = "You can't open this safeguarding concern. If you think you need to, ask your safeguarding lead.";

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * P2 (Stephan, 6 Nov 2026: "Block them"): the staff member a concern names as the alleged perpetrator never opens
     * it, whatever access they hold. Mirrors the incident owner check (IncidentOwnerEligibility).
     */
    public function namesAsAllegedPerpetrator(User $user, SafeguardingConcern $concern): bool
    {
        return $concern->alleged_perpetrator_id !== null
            && (int) $concern->alleged_perpetrator_id === (int) $user->id
            && in_array($concern->alleged_perpetrator_type, [...self::STAFF_PERPETRATOR_TYPES, $user->getMorphClass()], true);
    }

    /**
     * P3 (Stephan, 6 Nov 2026: "Their report + status only"): someone who can open a concern only because they
     * reported it sees what they reported and where it's up to, not the investigation, plans or later notes.
     */
    public function isReporterOnly(User $user, SafeguardingConcern $concern): bool
    {
        return (int) $concern->reported_by_user_id === (int) $user->id
            && (int) $concern->assigned_to_user_id !== (int) $user->id
            && ! $user->canDo('safeguarding.viewAny');
    }

    /** P2: keeps concerns that name the reader as the alleged perpetrator out of any list query. */
    public function excludeNamedPerpetrator(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $named) use ($user): void {
            $named->whereNull('alleged_perpetrator_id')
                ->orWhere('alleged_perpetrator_id', '!=', $user->id)
                ->orWhereNull('alleged_perpetrator_type')
                ->orWhereNotIn('alleged_perpetrator_type', [...self::STAFF_PERPETRATOR_TYPES, $user->getMorphClass()]);
        });
    }

    /**
     * Determine if the user can view any safeguarding concerns.
     */
    public function viewAny(User $user): bool
    {
        return $user->canDo('safeguarding.viewAny');
    }

    /**
     * Determine if the user can view the safeguarding concern.
     */
    public function view(User $user, SafeguardingConcern $concern): bool
    {
        if (! $this->canAccessConcernSite($user, $concern)) {
            return false;
        }

        // Can view if has viewAny permission
        if ($user->canDo('safeguarding.viewAny')) {
            return true;
        }

        // Can view if assigned to the concern
        if ($concern->assigned_to_user_id === $user->id) {
            return true;
        }

        // Can view if reported the concern
        if ($concern->reported_by_user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Whether the user may see what the concern says, not only that it exists: view() plus the need-to-know rule the
     * concern page uses for its locked shell. Attachments, and the person named on its H&S safety review, follow this.
     */
    public function viewDetail(User $user, SafeguardingConcern $concern): bool
    {
        return $this->view($user, $concern) && $this->canAccessSensitiveConcern($user, $concern);
    }

    /**
     * Determine if the user can create safeguarding concerns.
     */
    public function create(User $user): bool
    {
        return $user->canDo('safeguarding.create');
    }

    /**
     * Determine if the user can update the safeguarding concern.
     */
    public function update(User $user, SafeguardingConcern $concern): bool
    {
        if (! $this->canAccessConcernSite($user, $concern)
            || ! $this->canAccessSensitiveConcern($user, $concern)) {
            return false;
        }

        // Can update if has general update permission
        if ($user->canDo('safeguarding.update')) {
            return true;
        }

        // Can update if assigned to the concern
        if ($concern->assigned_to_user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can investigate the safeguarding concern.
     */
    public function investigate(User $user, SafeguardingConcern $concern): bool
    {
        return $user->canDo('safeguarding.investigate')
            && $this->canAccessConcernSite($user, $concern)
            && $this->canAccessSensitiveConcern($user, $concern);
    }

    /**
     * Determine if the user can report to external authorities.
     */
    public function reportExternal(User $user, SafeguardingConcern $concern): bool
    {
        return $user->canDo('safeguarding.report.external')
            && $this->canAccessConcernSite($user, $concern)
            && $this->canAccessSensitiveConcern($user, $concern);
    }

    /**
     * Determine if the user can view sensitive allegations.
     */
    public function viewSensitive(User $user): bool
    {
        return $user->canDo('safeguarding.viewSensitive');
    }

    public function requestDeclassification(User $user, SafeguardingConcern $concern): bool
    {
        return $concern->is_sensitive && $this->update($user, $concern);
    }

    public function approveDeclassification(User $user, SafeguardingConcern $concern): bool
    {
        return $user->canDo('safeguarding.declassification.approve')
            && $this->canUseDeclassificationAuthorityAtConcernSite($user, $concern)
            && $this->canAccessConcernSite($user, $concern)
            && $this->canAccessSensitiveConcern($user, $concern);
    }

    /**
     * Canonical SQL counterpart to view(): Site-less organisation records and
     * the explicit reports permission remain global; every Site-owned concern
     * is constrained before it is loaded.
     */
    public function applyVisibleScope(Builder $query, User $user): Builder
    {
        // P2: a concern naming the reader as the alleged perpetrator is never in their lists, counts or tasks.
        return $this->excludeNamedPerpetrator($this->applySiteScope($query, $user), $user);
    }

    /**
     * The Site boundary alone. The concern page uses it to answer "not found" for a concern at someone else's Site
     * before it gives the alleged perpetrator their plain refusal.
     */
    public function applySiteScope(Builder $query, User $user): Builder
    {
        if ($this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return $query;
        }

        $siteIds = $this->siteAccess->accessibleSiteIds($user);

        return $query->where(function (Builder $sites) use ($siteIds): void {
            $sites->whereNull('site_id');
            if ($siteIds !== []) {
                $sites->orWhereIn('site_id', $siteIds);
            }
        });
    }

    /**
     * The Sites whose concerns this reader may see: every active Site with the
     * reports bypass, otherwise their current HR Site assignments. Used to keep
     * the register's Site and subject pickers inside the same boundary.
     *
     * @return array<int, int>
     */
    public function visibleSiteIds(User $user): array
    {
        return $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
    }

    private function canAccessConcernSite(User $user, SafeguardingConcern $concern): bool
    {
        // P2: every check runs through here, so the alleged perpetrator is refused everything.
        if ($this->namesAsAllegedPerpetrator($user, $concern)) {
            return false;
        }

        $siteId = is_numeric($concern->site_id) && (int) $concern->site_id > 0
            ? (int) $concern->site_id
            : null;

        if ($siteId === null || $this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return true;
        }

        return in_array($siteId, $this->siteAccess->accessibleSiteIds($user), true);
    }

    private function canAccessSensitiveConcern(User $user, SafeguardingConcern $concern): bool
    {
        return ! $concern->is_sensitive
            || $user->canDo('safeguarding.viewSensitive')
            || $this->canUseDeclassificationAuthorityAtConcernSite($user, $concern)
            || (int) $concern->assigned_to_user_id === (int) $user->id
            || (int) $concern->reported_by_user_id === (int) $user->id;
    }

    private function canUseDeclassificationAuthorityAtConcernSite(
        User $user,
        SafeguardingConcern $concern,
    ): bool {
        if (! $user->canDo('safeguarding.declassification.approve')) {
            return false;
        }

        $siteId = is_numeric($concern->site_id) && (int) $concern->site_id > 0
            ? (int) $concern->site_id
            : null;

        return $siteId !== null
            || $this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS);
    }
}
