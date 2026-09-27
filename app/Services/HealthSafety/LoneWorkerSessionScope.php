<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

final class LoneWorkerSessionScope
{
    private const SITE_BYPASS_PERMISSIONS = UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function apply(Builder $query, ?User $user): Builder
    {
        $this->applySessionIntrinsicIntegrity($query);

        if ($this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)) {
            return $query;
        }

        $siteIds = $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $sessionScope) use ($siteIds) {
            $siteColumn = $sessionScope->qualifyColumn('site_id');
            $clientColumn = $sessionScope->qualifyColumn('client_id');
            $shiftColumn = $sessionScope->qualifyColumn('shift_id');

            // The session's own site is authoritative. Client and shift data
            // may enrich it only when their resolved site agrees.
            $sessionScope->where(function (Builder $directSite) use (
                $siteIds,
                $siteColumn,
                $clientColumn,
                $shiftColumn,
            ) {
                $directSite->whereIn($siteColumn, $siteIds)
                    ->where(function (Builder $clientAgreement) use ($clientColumn, $siteColumn) {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                                ->whereColumn('clients.site_id', $siteColumn));
                    })
                    ->where(function (Builder $shiftAgreement) use ($shiftColumn, $siteColumn) {
                        $shiftAgreement->whereNull($shiftColumn)
                            ->orWhereHas('shift', function (Builder $shiftQuery) use ($siteColumn) {
                                $shiftQuery->where(function (Builder $resolvedShiftSite) use ($siteColumn) {
                                    $resolvedShiftSite->whereColumn('shifts.site_id', $siteColumn)
                                        ->orWhere(function (Builder $shiftClientFallback) use ($siteColumn) {
                                            $shiftClientFallback->whereNull('shifts.site_id')
                                                ->whereHas('client', fn (Builder $clientQuery) => $clientQuery
                                                    ->whereColumn('clients.site_id', $siteColumn));
                                        });
                                });
                            });
                    });
            })
                // With no direct site, the client site is authoritative. A
                // linked shift must be for that same client or the row is
                // treated as conflicting legacy data and hidden.
                ->orWhere(function (Builder $clientFallback) use (
                    $siteIds,
                    $siteColumn,
                    $clientColumn,
                    $shiftColumn,
                ) {
                    $clientFallback->whereNull($siteColumn)
                        ->whereNotNull($clientColumn)
                        ->whereHas('client', fn (Builder $clientQuery) => $clientQuery
                            ->whereIn('site_id', $siteIds))
                        ->where(function (Builder $shiftAgreement) use ($shiftColumn, $clientColumn) {
                            $shiftAgreement->whereNull($shiftColumn)
                                ->orWhereHas('shift', fn (Builder $shiftQuery) => $shiftQuery
                                    ->whereColumn('shifts.client_id', $clientColumn));
                        });
                })
                // Shift provenance is the final fallback. The shift's direct
                // site wins; its client site is used only when that is absent.
                ->orWhere(function (Builder $shiftFallback) use (
                    $siteIds,
                    $siteColumn,
                    $clientColumn,
                ) {
                    $shiftFallback->whereNull($siteColumn)
                        ->whereNull($clientColumn)
                        ->whereHas('shift', function (Builder $shiftQuery) use ($siteIds) {
                            $shiftQuery->where(function (Builder $resolvedShiftSite) use ($siteIds) {
                                $resolvedShiftSite->whereIn('shifts.site_id', $siteIds)
                                    ->orWhere(function (Builder $shiftClientFallback) use ($siteIds) {
                                        $shiftClientFallback->whereNull('shifts.site_id')
                                            ->whereHas('client', fn (Builder $clientQuery) => $clientQuery
                                                ->whereIn('site_id', $siteIds));
                                    });
                            });
                        });
                });
        });
    }

    private function applySessionIntrinsicIntegrity(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $clientSite = "(SELECT `site_id` FROM `clients` WHERE `clients`.`id` = {$row}.`client_id` LIMIT 1)";
        $shiftSite = "(SELECT COALESCE(`lw_shift`.`site_id`, `lw_shift_client`.`site_id`) FROM `shifts` AS `lw_shift` LEFT JOIN `clients` AS `lw_shift_client` ON `lw_shift_client`.`id` = `lw_shift`.`client_id` WHERE `lw_shift`.`id` = {$row}.`shift_id` LIMIT 1)";
        $authoritativeSite = "COALESCE({$row}.`site_id`, {$clientSite}, {$shiftSite})";
        $today = now()->toDateString();

        return $query
            ->whereRaw("{$authoritativeSite} IS NOT NULL")
            ->whereRaw("EXISTS (SELECT 1 FROM `sites` AS `lw_site` WHERE `lw_site`.`id` = {$authoritativeSite} AND `lw_site`.`is_active` = 1 AND `lw_site`.`archived` = 0 AND `lw_site`.`archived_at` IS NULL)")
            ->whereRaw("EXISTS (SELECT 1 FROM `users` AS `lw_worker` JOIN `hr_employee_profiles` AS `lw_profile` ON `lw_profile`.`user_id` = `lw_worker`.`id` AND `lw_profile`.`deleted_at` IS NULL WHERE `lw_worker`.`id` = {$row}.`user_id` AND `lw_worker`.`approved_at` IS NOT NULL AND `lw_profile`.`is_active` = 1 AND (`lw_profile`.`start_date` IS NULL OR DATE(`lw_profile`.`start_date`) <= ?) AND (`lw_profile`.`end_date` IS NULL OR DATE(`lw_profile`.`end_date`) >= ?) AND (`lw_profile`.`primary_site_id` = {$authoritativeSite} OR JSON_CONTAINS(COALESCE(`lw_profile`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY({$authoritativeSite}))))", [$today, $today])
            ->whereRaw("({$row}.`client_id` IS NULL OR EXISTS (SELECT 1 FROM `clients` AS `lw_client` WHERE `lw_client`.`id` = {$row}.`client_id` AND `lw_client`.`site_id` = {$authoritativeSite}))")
            ->whereRaw("({$row}.`shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `lw_linked_shift` LEFT JOIN `clients` AS `lw_linked_client` ON `lw_linked_client`.`id` = `lw_linked_shift`.`client_id` WHERE `lw_linked_shift`.`id` = {$row}.`shift_id` AND `lw_linked_shift`.`user_id` = {$row}.`user_id` AND (`lw_linked_shift`.`client_id` <=> {$row}.`client_id`) AND (`lw_linked_shift`.`client_id` IS NULL OR (`lw_linked_client`.`site_id` IS NOT NULL AND (`lw_linked_shift`.`site_id` IS NULL OR `lw_linked_shift`.`site_id` = `lw_linked_client`.`site_id`))) AND COALESCE(`lw_linked_shift`.`site_id`, `lw_linked_client`.`site_id`) = {$authoritativeSite}))");
    }
}
