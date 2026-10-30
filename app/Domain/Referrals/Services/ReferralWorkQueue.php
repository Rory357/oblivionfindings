<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/** Shared canonical scope for the module calendar and company task projections. */
final class ReferralWorkQueue
{
    public function __construct(private readonly ReferralAccess $access) {}

    public function canView(User $actor): bool
    {
        return ($this->access->can($actor, 'viewAny') || $this->access->can($actor, 'viewAssigned'))
            && app(UserSiteAccessService::class)->applyStaffScope(User::query()->whereKey($actor->id), $actor, ['referrals.viewAllSites', 'referrals.centralIntake'])->exists();
    }

    public function work(User $actor): Builder
    {
        $audiences = array_values(array_filter(['intake', 'assessment', 'funding'], fn ($audience) => $this->access->audience($actor, $audience)));

        return ReferralWorkItem::query()->whereIn('referral_id', $this->access->query($actor)->select('care_referrals.id'))
            ->whereIn('audience', $audiences);
    }

    public function nextActions(User $actor): Builder
    {
        // A linked work item is projected once, through work(), rather than twice.
        return $this->access->query($actor)->where('stage', '!=', 'closed')->whereNull('primary_work_item_id')->whereNotNull('next_due_at');
    }
}
