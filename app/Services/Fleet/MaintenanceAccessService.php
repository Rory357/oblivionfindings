<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MaintenanceAccessService
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function canReport(User $actor): bool
    {
        return $actor->canDo('fleet.maintenance.report')
            || $actor->canDo('fleet.maintenance.manage')
            || $actor->canDo('fleet.manage');
    }

    public function canManage(User $actor): bool
    {
        return $actor->canDo('fleet.maintenance.manage') || $actor->canDo('fleet.manage');
    }

    public function canRead(User $actor): bool
    {
        return $this->canManage($actor)
            || $actor->canDo('fleet.viewAny')
            || $actor->canDo('assets.viewAny');
    }

    public function canReview(User $actor, Asset $asset): bool
    {
        if (! $actor->canDo('fleet.maintenance.release') || ! $asset->site_id || ! $asset->category) {
            return false;
        }
        if (! in_array((int) $asset->site_id, $this->approvedSiteIds($actor), true)) {
            return false;
        }
        $grant = DB::table('fleet_maintenance_reviewer_grants')
            ->where('site_id', $asset->site_id)->where('asset_category', $asset->category)
            ->where('review_kind', 'maintenance_release')->where('user_id', $actor->id)
            ->orderByDesc('version')->first();

        return $grant?->decision === 'grant';
    }

    /** @return array<int, int> */
    public function approvedSiteIds(User $actor): array
    {
        // Fleet action permissions do not themselves widen the Site boundary.
        return $this->siteAccess->accessibleSiteIds($actor, ['sites.viewAll']);
    }

    public function asset(User $actor, int $id, bool $lock = false): Asset
    {
        $sites = $this->approvedSiteIds($actor);
        abort_if($sites === [], 404);

        $query = Asset::query()
            ->whereKey($id)
            ->whereNotNull('site_id')
            ->whereIn('site_id', $sites);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function scopedWorkOrders(User $actor): Builder
    {
        abort_unless($this->canRead($actor), 403);
        $sites = $this->approvedSiteIds($actor);

        return FleetWorkOrder::query()->whereHas('asset', static function (Builder $query) use ($sites): void {
            $query->whereNotNull('site_id')->whereIn('site_id', $sites);
        });
    }

    public function workOrder(User $actor, int $id): FleetWorkOrder
    {
        if ($this->canRead($actor)) {
            return $this->scopedWorkOrders($actor)->whereKey($id)->firstOrFail();
        }

        $order = FleetWorkOrder::query()->whereKey($id)->firstOrFail();
        $asset = $this->asset($actor, (int) $order->asset_id);
        if ($this->canReview($actor, $asset)) {
            return $order;
        }
        if ((int) $order->assigned_to_user_id === (int) $actor->id) {
            return $order;
        }
        if ($this->isCurrentCustodyParticipant($actor, $id)) {
            return $order;
        }
        $latest = DB::table('fleet_maintenance_actions')
            ->where('work_order_id', $id)
            ->whereIn('action_type', ['propose_handover', 'accept_handover'])
            ->orderByDesc('id')->first();
        abort_unless($latest && $latest->action_type === 'propose_handover'
            && (int) $latest->target_user_id === (int) $actor->id, 404);

        return $order;
    }

    public function isCurrentCustodyParticipant(User $actor, int $workOrderId): bool
    {
        $latest = DB::table('fleet_maintenance_actions')
            ->where('work_order_id', $workOrderId)
            ->whereIn('action_type', ['propose_custody', 'acknowledge_custody'])
            ->orderByDesc('id')->first();

        return $latest && (int) $latest->target_user_id === (int) $actor->id
            && ! DB::table('fleet_maintenance_actions')->where('work_order_id', $workOrderId)
                ->where('action_type', 'release')->where('id', '>', $latest->id)->exists();
    }
}
