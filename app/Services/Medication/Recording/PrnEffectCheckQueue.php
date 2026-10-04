<?php

namespace App\Services\Medication\Recording;

use App\Models\ClientMedicationAdministration;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Unresolved effect checks survive the NZ day on which the dose was given. */
final class PrnEffectCheckQueue
{
    /** @return Collection<int, ClientMedicationAdministration> */
    public function unresolved(DoseSlotReaderScope $scope, CarbonInterface $now): Collection
    {
        $canonical = DB::table('medication_followups')->where('type', 'effect')
            ->whereColumn('administration_id', 'client_medication_administrations.id')
            ->whereColumn('medication_followups.client_id', 'client_medication_administrations.client_id')
            ->whereColumn('client_medication_id', 'client_medication_administrations.client_medication_id')
            ->orderBy('medication_followups.id')->limit(1);

        return $this->unresolvedQuery($scope, $now)
            ->select('client_medication_administrations.*')->addSelect([
                'workflow_id' => (clone $canonical)->select('medication_followups.id'),
                'workflow_due_at' => (clone $canonical)->select('due_at'),
                'workflow_owner_id' => (clone $canonical)->select('owner_id'),
                'workflow_owner_name' => (clone $canonical)->leftJoin('users', 'users.id', '=', 'medication_followups.owner_id')->select('users.name'),
            ])
            ->with(['medication:id,client_id,name,controlled_drug', 'administeredBy:id,name'])
            ->orderByRaw('COALESCE(effect_check_due_at, administered_at)')->orderBy('id')->get();
    }

    /** One read-only source scope for workers, legacy readiness and Tasks. */
    public function unresolvedQuery(DoseSlotReaderScope $scope, CarbonInterface $now, bool $missingOnly = false): Builder
    {
        $query = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->when($scope->clientIds !== null, fn ($q) => $q->whereIn('client_id', $scope->clientIds))
            ->where('status', 'given')
            ->where('administered_at', '<=', $now->copy()->utc())
            ->whereHas('medication', fn ($orders) => $orders->where('is_prn', true))
            ->whereDoesntHave('prnEffectiveness', fn ($effects) => $effects
                ->whereColumn('medication_prn_effectiveness.client_id', 'client_medication_administrations.client_id')
                ->whereColumn('medication_prn_effectiveness.client_medication_id', 'client_medication_administrations.client_medication_id'))
            ->whereNotExists(function ($work) use ($missingOnly): void {
                $work->selectRaw('1')->from('medication_followups')
                    ->where('medication_followups.type', 'effect')
                    ->whereColumn('medication_followups.administration_id', 'client_medication_administrations.id')
                    ->whereColumn('medication_followups.client_id', 'client_medication_administrations.client_id')
                    ->whereColumn('medication_followups.client_medication_id', 'client_medication_administrations.client_medication_id');
                if (! $missingOnly) {
                    $work->whereNotNull('medication_followups.completed_at');
                }
            });

        $governance = app(MedicationGovernanceScopeService::class);
        $governance->scopeCanonicalClientMedicationRows($query, null, false);
        if (! $scope->canViewControlled) {
            $governance->scopeWithoutControlledMedicationRows($query);
        }

        return $query;
    }

    /** @return list<array<string, mixed>> */
    public function payload(DoseSlotReaderScope $scope, CarbonInterface $now, string $timezone): array
    {
        $builder = app(MedsBoardPayloadService::class);
        $doses = $this->unresolved($scope, $now);

        return $doses->map(function (ClientMedicationAdministration $dose) use ($builder, $now, $timezone): array {
            $prepared = $dose->workflow_id !== null;
            $given = $builder->rawUtcInstant($dose, 'administered_at')?->timezone($timezone);
            $due = $prepared ? ($dose->getRawOriginal('workflow_due_at') ? $builder->rawUtcInstant($dose, 'workflow_due_at')->timezone($timezone) : null)
                : ($dose->getRawOriginal('effect_check_due_at') ? $builder->rawUtcInstant($dose, 'effect_check_due_at')->timezone($timezone) : null);

            return [
                'followup_id' => $prepared ? (int) $dose->workflow_id : null,
                'source_key' => 'effect:'.$dose->id,
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
                'owner_id' => $prepared ? ($dose->workflow_owner_id !== null ? (int) $dose->workflow_owner_id : null) : $dose->administered_by,
                'owner_name' => $prepared ? $dose->workflow_owner_name : $dose->administeredBy?->name,
            ];
        })->sortBy(fn ($row) => $row['check_due_at'] ?? $row['given_at'])->values()->all();
    }
}
