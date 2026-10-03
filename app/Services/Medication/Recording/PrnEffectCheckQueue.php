<?php

namespace App\Services\Medication\Recording;

use App\Models\ClientMedicationAdministration;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Unresolved effect checks survive the NZ day on which the dose was given. */
final class PrnEffectCheckQueue
{
    /** @return Collection<int, ClientMedicationAdministration> */
    public function unresolved(DoseSlotReaderScope $scope, CarbonInterface $now): Collection
    {
        if ($scope->clientIds === []) {
            return collect();
        }

        $query = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->whereIn('client_id', $scope->clientIds ?? [])
            ->whereNull('scheduled_for')
            ->where('status', 'given')
            ->where('administered_at', '<=', $now->copy()->utc())
            ->whereHas('medication', fn ($orders) => $orders->where('is_prn', true))
            ->whereDoesntHave('prnEffectiveness', fn ($effects) => $effects
                ->whereColumn('medication_prn_effectiveness.client_id', 'client_medication_administrations.client_id')
                ->whereColumn('medication_prn_effectiveness.client_medication_id', 'client_medication_administrations.client_medication_id'));

        $governance = app(MedicationGovernanceScopeService::class);
        $governance->scopeCanonicalClientMedicationRows($query, null, false);
        if (! $scope->canViewControlled) {
            $governance->scopeWithoutControlledMedicationRows($query);
        }

        return $query->with(['medication:id,client_id,name,controlled_drug', 'administeredBy:id,name'])
            ->orderByRaw('COALESCE(effect_check_due_at, administered_at)')
            ->orderBy('id')
            ->get();
    }

    /** @return list<array<string, mixed>> */
    public function payload(DoseSlotReaderScope $scope, CarbonInterface $now, string $timezone): array
    {
        $builder = app(MedsBoardPayloadService::class);

        return $this->unresolved($scope, $now)->map(function (ClientMedicationAdministration $dose) use ($builder, $now, $timezone): array {
            $given = $builder->rawUtcInstant($dose, 'administered_at')?->timezone($timezone);
            $due = $dose->getRawOriginal('effect_check_due_at')
                ? $builder->rawUtcInstant($dose, 'effect_check_due_at')->timezone($timezone)
                : null;

            return [
                'administration_id' => $dose->id,
                'client_id' => $dose->client_id,
                'medication_name' => $dose->medication?->name,
                'is_controlled' => (bool) $dose->medication?->controlled_drug,
                'dose_given' => $dose->dose_given,
                'given_at' => $given?->toIso8601String(),
                'given_time' => $given?->format('g:i a'),
                'given_label' => $given?->format('D j M, g:i a'),
                'check_at' => $due?->format($due->isSameDay($now->copy()->timezone($timezone)) ? 'g:i a' : 'D j M, g:i a'),
                'check_due_at' => $due?->toIso8601String(),
                'by' => $dose->administeredBy?->name,
                'effect_check_due_at' => $due?->toIso8601String(),
                'overdue' => $due?->lt($now) ?? false,
                'owner_id' => $dose->administered_by,
                'owner_name' => $dose->administeredBy?->name,
            ];
        })->all();
    }
}
