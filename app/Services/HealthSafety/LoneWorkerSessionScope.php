<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Source tuple scope for one organisation; actor evidence comes from the shared reader. */
final class LoneWorkerSessionScope
{
    public function apply(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return app(LoneWorkerSessionAccessService::class)->withCurrentViewer($user, function ($current, $reads, $sites) use ($query) {
            abort_unless($current->canDo('hazards.view'), 403);

            return $this->applyForSites($query, $sites);
        });
    }

    public function applyForSites(Builder $query, array $siteIds, bool $requireCurrentWorker = false): Builder
    {
        $this->applyIntrinsicIntegrity($query, $requireCurrentWorker);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw($this->resolvedSiteExpression($query).' IN ('.implode(',', array_fill(0, count($siteIds), '?')).')', $siteIds ?: [0]);
    }

    public function resolvedSiteExpression(Builder $query): string
    {
        $row = '`'.$query->getModel()->getTable().'`';
        $clientSite = "(SELECT `site_id` FROM `clients` WHERE `clients`.`id` = {$row}.`client_id` LIMIT 1)";
        $shiftSite = "(SELECT COALESCE(`lw_shift`.`site_id`, `lw_shift_client`.`site_id`) FROM `shifts` AS `lw_shift` LEFT JOIN `clients` AS `lw_shift_client` ON `lw_shift_client`.`id` = `lw_shift`.`client_id` WHERE `lw_shift`.`id` = {$row}.`shift_id` LIMIT 1)";

        return "COALESCE({$row}.`site_id`, {$clientSite}, {$shiftSite})";
    }

    public function applyIntrinsicIntegrity(Builder $query, bool $requireCurrentWorker = false): Builder
    {
        $row = '`'.$query->getModel()->getTable().'`';
        $site = $this->resolvedSiteExpression($query);
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $query->whereRaw("{$site} IS NOT NULL")
            ->whereRaw("EXISTS (SELECT 1 FROM `sites` AS `lw_site` WHERE `lw_site`.`id` = {$site} AND `lw_site`.`is_active` = 1 AND `lw_site`.`archived` = 0 AND `lw_site`.`archived_at` IS NULL AND `lw_site`.`deleted_at` IS NULL)")
            ->whereHas('user')
            ->whereRaw("({$row}.`client_id` IS NULL OR EXISTS (SELECT 1 FROM `clients` AS `lw_client` WHERE `lw_client`.`id` = {$row}.`client_id` AND `lw_client`.`site_id` = {$site} AND `lw_client`.`deleted_at` IS NULL))")
            ->whereRaw("({$row}.`shift_id` IS NULL OR EXISTS (SELECT 1 FROM `shifts` AS `lw_linked_shift` LEFT JOIN `clients` AS `lw_linked_client` ON `lw_linked_client`.`id` = `lw_linked_shift`.`client_id` AND `lw_linked_client`.`deleted_at` IS NULL WHERE `lw_linked_shift`.`id` = {$row}.`shift_id` AND `lw_linked_shift`.`user_id` = {$row}.`user_id` AND (`lw_linked_shift`.`client_id` <=> {$row}.`client_id`) AND (`lw_linked_shift`.`client_id` IS NULL OR (`lw_linked_client`.`site_id` IS NOT NULL AND (`lw_linked_shift`.`site_id` IS NULL OR `lw_linked_shift`.`site_id` = `lw_linked_client`.`site_id`))) AND COALESCE(`lw_linked_shift`.`site_id`, `lw_linked_client`.`site_id`) = {$site}))");
        $eligible = function (Builder $worker) use ($today, $site): void {
            $worker->staff()->whereNotNull('approved_at')->whereHas('hrEmployeeProfile', function (Builder $profile) use ($today, $site): void {
                $profile->where('is_active', true)
                    ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
                    ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                    ->whereRaw("(`hr_employee_profiles`.`primary_site_id` = {$site} OR JSON_CONTAINS(COALESCE(`hr_employee_profiles`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY({$site})) OR JSON_CONTAINS(COALESCE(`hr_employee_profiles`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY(CAST({$site} AS CHAR))))");
            });
        };
        if ($requireCurrentWorker) {
            $query->whereHas('user', $eligible);
        } else {
            $query->where(fn ($state) => $state->whereNotIn('status', ['active', 'overdue', 'emergency'])->orWhereHas('user', $eligible));
        }

        return $query;
    }
}
