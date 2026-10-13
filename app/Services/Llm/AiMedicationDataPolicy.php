<?php

namespace App\Services\Llm;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationTimelineVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What medication data AI search (ClientRagIndexer) and timeline summaries
 * (GenerateSummaryJob, SummaryController) may use — decision D5, 9 Oct 2026.
 *
 * Off (llm.include_medication_data = false, the default): no medicines, no
 * allergies and no medication timeline events. On: only for people the
 * reader may open (ClientPolicy::viewMedications), current orders only, and
 * never a controlled medicine.
 */
final class AiMedicationDataPolicy
{
    public function __construct(
        private readonly MedicationTimelineVisibilityService $timeline,
        private readonly MedicationRecordAccess $records,
        private readonly ClientAllergyRecordService $allergies,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('llm.include_medication_data', false);
    }

    public function canUseFor(?User $reader, Client $client): bool
    {
        return $this->enabled()
            && $reader !== null
            && Gate::forUser($reader)->allows('viewMedications', $client);
    }

    /** @return Collection<int, ClientMedication> Current, non-controlled orders only. */
    public function medicines(?User $reader, Client $client): Collection
    {
        if (! $this->canUseFor($reader, $client)) {
            return collect();
        }

        return ClientMedication::query()
            ->active()
            ->where('client_id', $client->id)
            ->where('controlled_drug', false)
            ->orderBy('name')
            ->get();
    }

    /** @return list<string> Canonical allergy labels, or none. */
    public function allergyLabels(?User $reader, Client $client): array
    {
        if (! $this->canUseFor($reader, $client)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (array $entry): string => (string) $entry['allergen'],
            $this->allergies->forClient($client),
        )));
    }

    /**
     * Narrow a timeline-event query. Off: non-medication events only. On:
     * non-medication events plus non-controlled medication events for people
     * the reader may open.
     */
    public function scopeTimeline(Builder $events, ?User $reader): Builder
    {
        if (! $this->enabled() || $reader === null) {
            return $this->timeline->applyVisibleScope($events, $reader, includeMedication: false);
        }

        $table = $events->getModel()->getTable();
        $ordinary = $this->timeline->applyVisibleScope(
            TimelineEvent::query()->select($table.'.id'),
            null,
            includeMedication: false,
        );
        $readable = $this->records->readableClientIds(
            $reader,
            (clone $events)->reorder()->whereNotNull($table.'.client_id')->distinct()->pluck($table.'.client_id'),
        );

        return $this->timeline
            ->applyVisibleScope($events, $reader, includeMedication: true, includeControlled: false)
            ->where(fn (Builder $kept) => $kept
                ->whereIn($table.'.id', $ordinary)
                ->orWhereIn($table.'.client_id', $readable === [] ? [0] : $readable));
    }

    /**
     * Ids of medication events among $eventIds (anything the non-medication
     * scope would drop).
     *
     * @param  array<int, int>  $eventIds
     * @return array<int, int>
     */
    public function medicationEventIds(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $ordinary = $this->timeline->applyVisibleScope(
            TimelineEvent::query()->whereIn('id', $eventIds),
            null,
            includeMedication: false,
        )->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_diff(array_map('intval', $eventIds), $ordinary));
    }

    /**
     * May this reader see a stored summary built from $eventIds? One stored
     * summary is shared by every reader, so medication content is shown only
     * while the switch is on, and only when every medication event in it is
     * one this reader could see.
     *
     * @param  array<int, int>  $eventIds
     */
    public function canShowStoredSummary(?User $reader, array $eventIds): bool
    {
        $medicationIds = $this->medicationEventIds($eventIds);
        if ($medicationIds === []) {
            return true;
        }
        if (! $this->enabled() || $reader === null) {
            return false;
        }

        $visible = $this->scopeTimeline(TimelineEvent::query()->whereIn('id', $medicationIds), $reader)->count();

        return $visible === count($medicationIds);
    }
}
