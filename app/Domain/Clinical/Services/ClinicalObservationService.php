<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\Acvpu;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Events\ObservationRecorded;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Support\WorkerClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ClinicalObservationService
{
    public const TIMELINE_TYPE_OBSERVATION = 'clinical_observation';

    public function __construct(
        protected News2Scorer $news2Scorer,
        protected ClinicalSignalService $signalService,
    ) {}

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

        $input['data'] = $this->validateDataForType($type, $input['data']);

        // NEWS2 is computed on write for vitals so registers/trends/the watchlist
        // can read the stored score + band without recomputation.
        $news2 = $type === ObservationType::Vitals
            ? $this->news2Scorer->score($input['data'])
            : null;

        $observation = ClinicalObservation::create([
            'client_id' => $client->id,
            'shift_id' => $shift?->id,
            'site_id' => $shift?->site_id ?? $client->site_id,
            'recorded_by' => $recorder->id,
            'observation_type' => $type,
            'recorded_at' => WorkerClock::toUtc($input['recorded_at'] ?? null) ?? now(),
            'data' => $input['data'],
            'news2_score' => $news2?->score,
            'news2_band' => $news2?->band,
            'notes' => $input['notes'] ?? null,
            'is_flagged' => $isFlagged = (bool) ($input['is_flagged'] ?? false),
            'flagged_reason' => $isFlagged ? ($input['flagged_reason'] ?? null) : null,
            'flagged_by' => $isFlagged ? $recorder->id : null,
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

        // Deterioration escalation — Medium/High NEWS2 raises a clinical signal.
        if ($news2 && $news2->band->isOnWatch()) {
            $this->signalService->emitForDeterioration($observation, $news2);
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
            ->select(['id', 'recorded_at', 'data', 'news2_score', 'news2_band'])
            ->get();
    }

    /**
     * Build the chartable trend sets (weight / pain / vitals / fluid, plus NEWS2
     * when requested) for a client over a date range. Shared by the per-client
     * Trends page and the module Trends tab so the two never drift.
     *
     * @return array<string, array{key: string, label: string, description: string, points: array<int, array<string, mixed>>, count: int, latest: array<string, mixed>|null}>
     */
    public function buildTrendSets(Client $client, \DateTimeInterface $from, \DateTimeInterface $to, bool $includeNews2 = false): array
    {
        $sets = [
            'weight' => $this->trendSet('weight', 'Weight', 'Track body weight over time.', $client, ObservationType::Weight, $from, $to,
                fn (ClinicalObservation $o) => is_numeric($o->data['weight_kg'] ?? null) ? ['weight_kg' => round((float) $o->data['weight_kg'], 1)] : null),
            'pain' => $this->trendSet('pain', 'Pain Score', 'Track pain score observations on the 0 to 10 scale.', $client, ObservationType::Pain, $from, $to,
                fn (ClinicalObservation $o) => is_numeric($o->data['score'] ?? null) ? ['score' => (float) $o->data['score'], 'location' => $o->data['location'] ?? null] : null),
            'vitals' => $this->trendSet('vitals', 'Vitals', 'Blood pressure and pulse trends.', $client, ObservationType::Vitals, $from, $to,
                fn (ClinicalObservation $o) => (is_numeric($o->data['systolic'] ?? null) && is_numeric($o->data['diastolic'] ?? null) && is_numeric($o->data['pulse'] ?? null))
                    ? ['systolic' => (float) $o->data['systolic'], 'diastolic' => (float) $o->data['diastolic'], 'pulse' => (float) $o->data['pulse']] : null),
            'fluid_intake' => $this->trendSet('fluid_intake', 'Fluid Intake', 'Track fluid intake amounts in millilitres.', $client, ObservationType::FluidIntake, $from, $to,
                fn (ClinicalObservation $o) => is_numeric($o->data['amount_ml'] ?? null) ? ['amount_ml' => (float) $o->data['amount_ml'], 'fluid_type' => $o->data['fluid_type'] ?? null] : null),
        ];

        if ($includeNews2) {
            $sets['news2'] = $this->trendSet('news2', 'NEWS2', 'Early-warning score from recorded vitals.', $client, ObservationType::Vitals, $from, $to,
                fn (ClinicalObservation $o) => $o->news2_score === null ? null : ['score' => (int) $o->news2_score, 'band' => $o->news2_band?->value]);
        }

        return $sets;
    }

    /**
     * @param  callable(ClinicalObservation): (array<string, mixed>|null)  $mapPoint
     * @return array{key: string, label: string, description: string, points: array<int, array<string, mixed>>, count: int, latest: array<string, mixed>|null}
     */
    private function trendSet(string $key, string $label, string $description, Client $client, ObservationType $type, \DateTimeInterface $from, \DateTimeInterface $to, callable $mapPoint): array
    {
        $points = $this->getTrends($client, $type, $from, $to)
            ->map(function (ClinicalObservation $observation) use ($mapPoint) {
                $extra = $mapPoint($observation);
                if ($extra === null) {
                    return null;
                }

                return array_merge([
                    'id' => $observation->id,
                    'recorded_at' => $observation->recorded_at->toISOString(),
                    'short_label' => $observation->recorded_at->format('j M'),
                ], $extra);
            })
            ->filter()
            ->values();

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'points' => $points->all(),
            'count' => $points->count(),
            'latest' => $points->last(),
        ];
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * Validate that the data array has the required fields for the observation type.
     *
     * @throws ValidationException
     */
    protected function validateDataForType(ObservationType $type, array $data): array
    {
        $rules = $this->requiredFieldsForType($type);
        $errors = [];

        foreach ($rules as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $errors["data.{$field}"] = "The {$this->fieldLabel($field)} field is required.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $normalized = $data;

        match ($type) {
            ObservationType::Vitals => $this->validateVitals($normalized, $errors),
            ObservationType::Weight => $this->validateWeight($normalized, $errors),
            ObservationType::Bowel => $this->validateBowel($normalized, $errors),
            ObservationType::Sleep => $this->validateSleep($normalized, $errors),
            ObservationType::FluidIntake => $this->validateFluidIntake($normalized, $errors),
            ObservationType::Pain => $this->validatePain($normalized, $errors),
            ObservationType::General => null,
        };

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
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

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function validateVitals(array &$data, array &$errors): void
    {
        $this->numericField($data, $errors, 'systolic', 50, 260);
        $this->numericField($data, $errors, 'diastolic', 30, 160);
        $this->numericField($data, $errors, 'pulse', 30, 220);
        $this->numericField($data, $errors, 'temperature', 30, 45, required: false);
        $this->numericField($data, $errors, 'respiration_rate', 4, 60, required: false);
        $this->numericField($data, $errors, 'o2_saturation', 50, 100, required: false);

        // NEWS2 inputs (optional — present when a full early-warning set is recorded).
        if ($this->hasValue($data, 'consciousness')) {
            $level = (string) $data['consciousness'];
            if (Acvpu::tryFrom($level) === null) {
                $errors['data.consciousness'] = 'Choose a valid level of consciousness (ACVPU).';
            } else {
                $data['consciousness'] = $level;
            }
        }

        if (array_key_exists('on_oxygen', $data) && $data['on_oxygen'] !== null && $data['on_oxygen'] !== '') {
            $data['on_oxygen'] = filter_var($data['on_oxygen'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->hasValue($data, 'spo2_scale')) {
            $scale = (int) $data['spo2_scale'];
            if (! in_array($scale, [1, 2], true)) {
                $errors['data.spo2_scale'] = 'SpO₂ scale must be 1 or 2.';
            } else {
                $data['spo2_scale'] = $scale;
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function validateWeight(array &$data, array &$errors): void
    {
        $this->numericField($data, $errors, 'weight_kg', 1, 500);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function validateBowel(array &$data, array &$errors): void
    {
        $this->integerField($data, $errors, 'bristol_type', 1, 7);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function validateSleep(array &$data, array &$errors): void
    {
        $this->timeField($data, $errors, 'bed_time');
        $this->timeField($data, $errors, 'wake_time');
        $this->enumField($data, $errors, 'quality', ['good', 'fair', 'poor']);
        $this->integerField($data, $errors, 'interruptions', 0, 50, required: false);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function validateFluidIntake(array &$data, array &$errors): void
    {
        $this->numericField($data, $errors, 'amount_ml', 1, 5000);
        $this->enumField($data, $errors, 'fluid_type', ['water', 'tea', 'coffee', 'juice', 'milk', 'other']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function validatePain(array &$data, array &$errors): void
    {
        $this->integerField($data, $errors, 'score', 0, 10);
        $this->stringField($data, $errors, 'location', max: 120);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function numericField(
        array &$data,
        array &$errors,
        string $field,
        float $min,
        float $max,
        bool $required = true,
    ): void {
        if (! $this->hasValue($data, $field)) {
            if ($required) {
                $errors["data.{$field}"] = "The {$this->fieldLabel($field)} field is required.";
            }

            return;
        }

        if (! is_numeric($data[$field])) {
            $errors["data.{$field}"] = "The {$this->fieldLabel($field)} must be a number.";

            return;
        }

        $value = (float) $data[$field];
        if ($value < $min || $value > $max) {
            $errors["data.{$field}"] = sprintf(
                'The %s must be between %s and %s.',
                $this->fieldLabel($field),
                $this->formatRangeNumber($min),
                $this->formatRangeNumber($max),
            );

            return;
        }

        $data[$field] = $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function integerField(
        array &$data,
        array &$errors,
        string $field,
        int $min,
        int $max,
        bool $required = true,
    ): void {
        if (! $this->hasValue($data, $field)) {
            if ($required) {
                $errors["data.{$field}"] = "The {$this->fieldLabel($field)} field is required.";
            }

            return;
        }

        $value = filter_var($data[$field], FILTER_VALIDATE_INT);
        if ($value === false) {
            $errors["data.{$field}"] = "The {$this->fieldLabel($field)} must be a whole number.";

            return;
        }

        if ($value < $min || $value > $max) {
            $errors["data.{$field}"] = "The {$this->fieldLabel($field)} must be between {$min} and {$max}.";

            return;
        }

        $data[$field] = $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     * @param array<int, string> $allowed
     */
    protected function enumField(array &$data, array &$errors, string $field, array $allowed): void
    {
        if (! $this->hasValue($data, $field)) {
            $errors["data.{$field}"] = "The {$this->fieldLabel($field)} field is required.";

            return;
        }

        $value = (string) $data[$field];
        if (! in_array($value, $allowed, true)) {
            $errors["data.{$field}"] = "Choose a valid {$this->fieldLabel($field)}.";

            return;
        }

        $data[$field] = $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function timeField(array &$data, array &$errors, string $field): void
    {
        if (! $this->hasValue($data, $field)) {
            $errors["data.{$field}"] = "The {$this->fieldLabel($field)} field is required.";

            return;
        }

        $value = (string) $data[$field];
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            $errors["data.{$field}"] = "Enter a valid {$this->fieldLabel($field)}.";

            return;
        }

        $data[$field] = $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    protected function stringField(array &$data, array &$errors, string $field, int $max): void
    {
        if (! $this->hasValue($data, $field)) {
            $errors["data.{$field}"] = "The {$this->fieldLabel($field)} field is required.";

            return;
        }

        $value = trim((string) $data[$field]);
        if (mb_strlen($value) > $max) {
            $errors["data.{$field}"] = "The {$this->fieldLabel($field)} must be {$max} characters or fewer.";

            return;
        }

        $data[$field] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function hasValue(array $data, string $field): bool
    {
        return array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';
    }

    protected function fieldLabel(string $field): string
    {
        return str_replace('_', ' ', $field);
    }

    protected function formatRangeNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
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
