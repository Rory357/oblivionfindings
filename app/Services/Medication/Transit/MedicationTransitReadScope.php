<?php

namespace App\Services\Medication\Transit;

use App\Models\Client;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;

/**
 * Medicines in Fleet custody are medication records (EA-002). Fleet decides
 * which journeys a viewer may see (fleet.manage widens that across houses);
 * this decides which of their medicines the viewer may read:
 *
 * - only at houses the viewer may read medication records for, using the
 *   eMAR Site bypass (sites.viewAll / clinical.accessAllSites), never
 *   fleet.manage;
 * - only for people the viewer may open (ClientPolicy::viewMedications, the
 *   P02 per-person rule): leads keep the house, support workers keep the
 *   residents they support or cover;
 * - controlled medicines only for medications.controlled.view holders.
 */
final class MedicationTransitReadScope
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $records,
    ) {}

    public function includesControlled(User $actor): bool
    {
        return $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
    }

    /** @return array<int, int> Houses whose medication records the actor may read. */
    public function siteIds(User $actor): array
    {
        if (! $actor->canDo(MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY)) {
            return [];
        }

        return $this->scope->readerSiteIds($actor, MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY);
    }

    public function canReadClient(User $actor, ?Client $client): bool
    {
        return $client !== null
            && $client->site_id !== null
            && in_array((int) $client->site_id, $this->siteIds($actor), true)
            && Gate::forUser($actor)->allows('viewMedications', $client);
    }

    /**
     * Narrow transit logs already limited to the Fleet journey scope to the
     * people and medicines the actor may read.
     */
    public function applyToLogs(Builder $logs, User $actor): Builder
    {
        $table = $logs->getModel()->getTable();
        $siteIds = $this->siteIds($actor);
        if ($siteIds === []) {
            return $logs->whereRaw('1 = 0');
        }

        $candidates = (clone $logs)
            ->reorder()
            ->whereIn($table.'.site_id', $siteIds)
            ->distinct()
            ->pluck($table.'.client_id');
        $readable = $this->records->readableClientIds($actor, $candidates);

        $logs->whereIn($table.'.site_id', $siteIds)
            ->whereIn($table.'.client_id', $readable === [] ? [0] : $readable);

        if (! $this->includesControlled($actor)) {
            $logs->where($table.'.is_controlled_drug', false)
                ->whereNotExists(fn (QueryBuilder $medication) => $medication
                    ->selectRaw('1')
                    ->from('client_medications')
                    ->whereColumn('client_medications.id', $table.'.medication_id')
                    ->where('client_medications.controlled_drug', true));
        }

        return $logs;
    }

    /** Orders offered for packing: controlled medicines only to controlled readers. */
    public function applyToOrders(Builder $orders, User $actor): Builder
    {
        if (! $this->includesControlled($actor)) {
            $orders->where($orders->qualifyColumn('controlled_drug'), false);
        }

        return $orders;
    }
}
