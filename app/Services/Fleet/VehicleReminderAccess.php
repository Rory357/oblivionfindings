<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetDocumentSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** A follow-up never widens the visibility of its owning document or work. */
final class VehicleReminderAccess
{
    public function scope(Builder $query, ?User $actor): Builder
    {
        $maintenance = app(MaintenanceAccessService::class);
        $workSites = $actor && $maintenance->canRead($actor) ? $maintenance->approvedSiteIds($actor) : [];
        $siteAssets = $actor
            ? app(SecurityDevicesAccessService::class)->siteScopedVehiclesForFleet($actor)->select('assets.id')
            : Asset::query()->whereRaw('1 = 0')->select('id');
        $workAssets = Asset::query()->whereIn('site_id', $workSites)->select('id');
        $documents = AssetDocumentSet::query()
            ->whereColumn('asset_document_sets.asset_id', 'fleet_vehicle_reminders.asset_id')
            ->where(function (Builder $sets) use ($actor, $siteAssets, $workAssets): void {
                $sets->whereNull('source_type')->orWhereNotIn('source_type', [
                    'finance_review_request', 'booking', 'service_completion', 'speed_limit', 'unavailable_period',
                ]);
                if ($actor?->canDo('finance.assets.view')) {
                    $sets->orWhere(fn (Builder $q) => $q->where('source_type', 'finance_review_request')->whereIn('asset_id', clone $siteAssets));
                }
                if ($actor) {
                    $sets->orWhere(fn (Builder $q) => $q->where('source_type', 'booking')->whereIn('source_id',
                        app(VehicleBookingAccessService::class)->accessibleBookings($actor)->select('fleet_vehicle_bookings.id')));
                }
                $sets->orWhere(fn (Builder $q) => $q->where('source_type', 'service_completion')->whereIn('asset_id', clone $workAssets));
                $sets->orWhere(fn (Builder $q) => $q->whereIn('source_type', ['speed_limit', 'unavailable_period'])->whereIn('asset_id', clone $siteAssets));
            })->select('asset_document_sets.id');

        return $query->where(function (Builder $rows) use ($documents, $workAssets): void {
            $rows->whereNull('source_type')->orWhereNotIn('source_type', ['document_set', 'work_order'])
                ->orWhere(fn (Builder $q) => $q->where('source_type', 'document_set')->whereIn('source_id', $documents))
                ->orWhere(fn (Builder $q) => $q->where('source_type', 'work_order')->whereIn('asset_id', $workAssets));
        });
    }
}
