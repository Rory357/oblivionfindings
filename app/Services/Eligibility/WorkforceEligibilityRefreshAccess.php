<?php

namespace App\Services\Eligibility;

use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/** Read/retry scope only; never an assignment authorization or permission grant. */
final class WorkforceEligibilityRefreshAccess
{
    public const SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function shifts(Builder $query, User $actor): Builder
    {
        $query->employeeDuties();
        $this->sites->applyShiftScope($query, $actor, self::SITE_BYPASS_PERMISSIONS);
        if (! $actor->canDo('shifts.manageAny')) {
            $query->where($query->qualifyColumn('user_id'), $actor->id)->visibleToFrontline();
        }

        return $query;
    }

    /** Called after the governing mutex, participant locks and current Shift lock. */
    public function assertCurrentRetryAccess(Shift $shift, User $actor): void
    {
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($shift, $actor): void {
            // The ordinary read scope embeds raw correlated SQL. Lock each
            // canonical related source explicitly instead of wrapping that SQL.
            $client = $shift->client_id === null ? null : $reads->query(Client::query())
                ->whereKey($shift->client_id)->first(['id', 'site_id']);
            if ($shift->client_id !== null) {
                abort_unless($client && (int) $client->site_id > 0
                    && ($shift->site_id === null || (int) $shift->site_id === (int) $client->site_id), 403);
            }
            $shift->setRelation('client', $client);
            $siteId = $this->sites->shiftSiteId($shift);
            abort_unless($siteId && in_array($siteId, $this->sites->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS, $reads), true), 403);
            $worker = $this->sites->applyFleetRecipientEligibility(User::query()->whereKey($shift->user_id), $siteId);
            abort_unless($reads->query($worker)->exists(), 403);
            if (! $actor->canDo('shifts.manageAny')) {
                abort_unless((int) $shift->user_id === (int) $actor->id
                    && $reads->query(Shift::query()->whereKey($shift->id)->visibleToFrontline())->exists(), 403);
            }
        });
    }
}
