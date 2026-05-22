<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Events\ObservationRecorded;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ClinicalObservationService
{
    public const TIMELINE_TYPE_OBSERVATION = 'clinical_observation';

    /**
     * Record a clinical observation.
     *
     * @param  array{
     *     observation_type: ObservationType|string,
     *     data: array,
     *     notes?: string|null,
     *     recorded_at?: \DateTimeInterface|string|null,
     *     protocol_schedule_id?: int|null,
     * } $input
     */
    public function record(
        Client $client,
        User $recorder,
        array $input,
        ?Shift $shift = null,
    ): ClinicalObservation {
        $type = $input['observation_type'] instanceof ObservationType
            ? $input['observation_type']
            : ObservationType::from($input['observation_type']);

        $this->validateDataForType($type, $input['data']);

        $observation = ClinicalObservation::create([
            'client_id' => $client->id,
            'shift_id' => $shift?->id,
            'site_id' => $shift?->site_id ?? $client->site_id,
            'recorded_by' => $recorder->id,
            'observation_type' => $type,
            'recorded_at' => $input['recorded_at'] ?? now(),
            'data' => $input['data'],
            'notes' => $input['notes'] ?? null,
            'protocol_schedule_id' => $input['protocol_schedule_id'] ?? null,
        ]);

        $this->createTimelineEvent($observation, $recorder);

        if (! empty($input['protocol_schedule_id'])) {
            $this->completeProtocolSchedule(
                $input['protocol_schedule_id'],
                $recorder->id,
                $observation->id,
            );
        }

        ObservationRecorded::dispatch($observation);

        Log::info('ClinicalObservationService: observation recorded', [
            'observation_id' => $observation->id,
            'type' => $type->value,
            'client_id' => $client->id,
            'shift_id' => $shift?->id,
        ]);

        return $observation;
    }

    /**
     * Get the latest observations for a client, optionally filtered by type.
     */
    public function getLatest(Client $client, ?ObservationType $type = null, int $limit = 10): Collection
    {
        return ClinicalObservation::query()
            ->forClient($client->id)
            ->when($type, fn ($q) => $q->ofType($type))
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get observation trend data for a client and type over a date range.
     *
     * Returns an array of {recorded_at, data} pairs for charting.
     */
    public function getTrends(
        Client $client,
        ObservationType $type,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): Collection {
        return ClinicalObservation::query()
            ->forClient($client->id)
            ->ofType($type)
            ->recordedBetween($from, $to)
            ->orderBy('recorded_at')
            ->select(['id', 'recorded_at', 'data'])
            ->get();
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * Validate that the data array has the required fields for the observation type.
     *
     * @throws ValidationException
     */
    protected function validateDataForType(ObservationType $type, array $data): void
    {
        $rules = $this->requiredFieldsForType($type);

        $missing = array_diff($rules, array_keys($data));

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'data' => "Missing required fields for {$type->value} observation: " . implode(', ', $missing),
            ]);
        }
    }

    /**
     * Required top-level keys in the data JSON for each observation type.
     *
     * @return array<int, string>
     */
    protected function requiredFieldsForType(ObservationType $type): array
    {
        return match ($type) {
            ObservationType::Vitals => ['systolic', 'diastolic', 'pulse'],
            ObservationType::Weight => ['weight_kg'],
            ObservationType::Bowel => ['bristol_type'],
            ObservationType::Sleep => ['bed_time', 'wake_time', 'quality'],
            ObservationType::FluidIntake => ['amount_ml', 'fluid_type'],
            ObservationType::Pain => ['score', 'location'],
            ObservationType::General => [],
        };
    }

    // ── Timeline ─────────────────────────────────────────────────────────

    protected function createTimelineEvent(ClinicalObservation $observation, User $recorder): TimelineEvent
    {
        return app(\App\Services\Timeline\TimelineEmitter::class)->record([
            'type' => self::TIMELINE_TYPE_OBSERVATION,
            'source_type' => ClinicalObservation::class,
            'source_id' => $observation->id,
            'occurred_at' => $observation->recorded_at,
            'actor_user_id' => $recorder->id,
            'client_id' => $observation->client_id,
            'shift_id' => $observation->shift_id,
            'site_id' => $observation->site_id,
            'subject' => $observation->observation_type->label() . ' recorded',
            'body' => $this->buildTimelineBody($observation),
            'meta' => [
                'observation_id' => $observation->id,
                'observation_type' => $observation->observation_type->value,
                'data_summary' => $this->summariseData($observation),
            ],
            'visibility' => 'internal',
            'created_by' => $recorder->id,
        ]);
    }

    protected function buildTimelineBody(ClinicalObservation $observation): string
    {
        $summary = $this->summariseData($observation);

        $parts = [$observation->observation_type->label()];

        if ($summary) {
            $parts[] = $summary;
        }

        if ($observation->notes) {
            $parts[] = $observation->notes;
        }

        return implode(' · ', $parts);
    }

    /**
     * Build a short human-readable summary of the observation data.
     */
    protected function summariseData(ClinicalObservation $observation): string
    {
        $data = $observation->data;

        return match ($observation->observation_type) {
            ObservationType::Vitals => implode(', ', array_filter([
                isset($data['systolic'], $data['diastolic']) ? "BP {$data['systolic']}/{$data['diastolic']}" : null,
                isset($data['pulse']) ? "Pulse {$data['pulse']}" : null,
                isset($data['temperature']) ? "Temp {$data['temperature']}°C" : null,
                isset($data['o2_saturation']) ? "O₂ {$data['o2_saturation']}%" : null,
            ])),
            ObservationType::Weight => isset($data['weight_kg']) ? "{$data['weight_kg']} kg" : '',
            ObservationType::Bowel => isset($data['bristol_type']) ? "Bristol type {$data['bristol_type']}" : '',
            ObservationType::Sleep => implode(', ', array_filter([
                isset($data['quality']) ? ucfirst($data['quality']) . ' sleep' : null,
                isset($data['interruptions']) && $data['interruptions'] > 0 ? "{$data['interruptions']} interruptions" : null,
            ])),
            ObservationType::FluidIntake => implode(', ', array_filter([
                isset($data['amount_ml']) ? "{$data['amount_ml']}ml" : null,
                isset($data['fluid_type']) ? $data['fluid_type'] : null,
            ])),
            ObservationType::Pain => implode(', ', array_filter([
                isset($data['score']) ? "Pain {$data['score']}/10" : null,
                isset($data['location']) ? $data['location'] : null,
            ])),
            ObservationType::General => '',
        };
    }

    // ── Protocol schedule completion ─────────────────────────────────────

    protected function completeProtocolSchedule(int $scheduleId, int $userId, int $observationId): void
    {
        $schedule = ClinicalProtocolSchedule::find($scheduleId);

        if ($schedule && $schedule->isPending()) {
            $schedule->markCompleted($userId, $observationId);
        }
    }
}
