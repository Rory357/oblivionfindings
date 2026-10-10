<?php

namespace App\Services\Medication\Transit;

use App\Models\Client;
use App\Models\FleetMedicationTransitLog;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Medication-transit rows on the client profile's Transport tab (EA-022).
 * They are medication records, so the tab shows them only to readers who
 * pass the Medical-section gate (the eMAR person rule), and controlled
 * medicines only to controlled readers — the Medical tab's own rule.
 *
 * Called from one line in ClientController::buildTransportData so the H&S
 * lane's copy of that controller rebases cleanly.
 */
final class ClientTransportMedicationLogs
{
    public function __construct(private readonly MedicationTransitReadScope $read) {}

    /** @return Collection<int, array<string, mixed>> */
    public function forViewer(Client $client, ?User $viewer, int $limit = 20): Collection
    {
        if ($viewer === null || ! $this->read->canReadClient($viewer, $client)) {
            return collect();
        }

        $query = FleetMedicationTransitLog::query()
            ->where('client_id', $client->id)
            ->with(['packedBy:id,name', 'administeredBy:id,name', 'witnessedBy:id,name'])
            ->latest('packed_at')
            ->limit($limit);
        if (! $this->read->includesControlled($viewer)) {
            $query->where('is_controlled_drug', false)
                ->whereNotExists(fn (QueryBuilder $medication) => $medication
                    ->selectRaw('1')
                    ->from('client_medications')
                    ->whereColumn('client_medications.id', 'fleet_medication_transit_logs.medication_id')
                    ->where('client_medications.controlled_drug', true));
        }

        return $query->get()
            ->map(fn (FleetMedicationTransitLog $m): array => [
                'id' => $m->id,
                'medication_name' => $m->medication_name,
                'is_controlled_drug' => $m->is_controlled_drug,
                'packed_at' => optional($m->packed_at)->toISOString(),
                'packed_by' => $m->packedBy?->name,
                'administered_at' => optional($m->administered_at)->toISOString(),
                'administered_by' => $m->administeredBy?->name,
                'witnessed_by' => $m->witnessedBy?->name,
                'returned_to_house_at' => optional($m->returned_to_house_at)->toISOString(),
                'status' => $m->status,
            ])
            ->values();
    }
}
