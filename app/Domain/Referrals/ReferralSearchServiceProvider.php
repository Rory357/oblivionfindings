<?php

namespace App\Domain\Referrals;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Services\ReferralSearchIndex;
use Illuminate\Support\ServiceProvider;

/** Keeps the hashed name index in step with every identity write path (public, staff, internal, corrections). */
final class ReferralSearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        CareReferral::saved(function (CareReferral $referral): void {
            if ($referral->wasRecentlyCreated || $referral->wasChanged(['identity', 'intake'])) {
                app(ReferralSearchIndex::class)->sync($referral);
            }
        });
    }
}
