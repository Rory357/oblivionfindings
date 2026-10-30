<?php

namespace App\Services\Medication\Transit;

use App\Models\FleetMedicationTransitLog;
use App\Models\User;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\Reporting\MedicationReportDataset;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Rows for the P09 "Medicines in transit" CSV (type "transit"). Replaces the
 * Fleet register's unguarded ?export=csv (EA-002): the export controller has
 * already applied the purpose step and the reporting Site scope; this applies
 * the person rule and controlled concealment, and keeps timestamps as UTC
 * instants so the shared writer prints them in NZ time (EA-032).
 */
final class MedicationTransitExport
{
    public function __construct(private readonly MedicationReportAccess $access) {}

    /**
     * @param  array<int, int>  $sites
     * @return list<array<string, mixed>>
     */
    public function rows(User $actor, MedicationReportPeriod $period, array $sites, ?int $clientId): array
    {
        [$from, $to] = $period->bounds();
        $people = $this->access->clientIds($actor, $sites);
        $query = FleetMedicationTransitLog::query()
            ->with(['client:id,first_name,last_name', 'packedBy:id,name', 'packedWitness:id,name', 'administeredBy:id,name', 'witnessedBy:id,name', 'returnedBy:id,name'])
            ->whereIn('site_id', $sites)
            ->whereIn('client_id', $people === [] ? [0] : $people)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->whereBetween('packed_at', [$from, $to]);
        if (! $actor->canDo('medications.controlled.view')) {
            $query->where('is_controlled_drug', false)
                ->whereNotExists(fn (QueryBuilder $medication) => $medication
                    ->selectRaw('1')
                    ->from('client_medications')
                    ->whereColumn('client_medications.id', 'fleet_medication_transit_logs.medication_id')
                    ->where('client_medications.controlled_drug', true));
        }
        abort_if((clone $query)->count() > MedicationReportDataset::MAX_ROWS, 422, 'Choose a shorter period; no partial result was created.');

        return $query->orderBy('packed_at')->orderBy('id')->get()->map(fn (FleetMedicationTransitLog $log): array => [
            'reference' => 'MT-'.$log->id,
            'person' => trim(($log->client?->first_name ?? '').' '.($log->client?->last_name ?? '')),
            'medicine' => $log->medication_name,
            'controlled' => $log->is_controlled_drug ? 'Yes' : 'No',
            'packed_at' => $log->packed_at?->toIso8601String(),
            'packed_by' => $log->packedBy?->name,
            'packing_witness' => $log->packedWitness?->name ?? ($log->packed_witness_name ? '[legacy label] '.$log->packed_witness_name : null),
            'packing_attested_at' => $log->packed_witnessed_at?->toIso8601String(),
            'administered_at' => $log->administered_at?->toIso8601String(),
            'administered_by' => $log->administeredBy?->name,
            'witnessed_by' => $log->witnessedBy?->name,
            'returned_at' => $log->returned_to_house_at?->toIso8601String(),
            'returned_by' => $log->returnedBy?->name,
            'status' => $log->status,
            'notes' => $log->notes,
            'client_id' => (int) $log->client_id,
        ])->all();
    }
}
