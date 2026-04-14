<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Response;

class HealthClinicalClientTrendsController extends Controller
{
    private const DEFAULT_RANGE_DAYS = 30;

    public function __construct(
        private readonly ClinicalObservationService $observationService,
    ) {}

    public function show(Request $request, Client $client): Response
    {
        $auth = $request->user();
        abort_unless(
            $auth && (
                $auth->canDo('clinical.observations.viewAny')
                || $auth->canDo('clinical.observations.viewAssigned')
            ),
            403
        );

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $to = isset($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : now()->endOfDay();
        $from = isset($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : $to->copy()->subDays(self::DEFAULT_RANGE_DAYS - 1)->startOfDay();

        $trendSets = [
            'weight' => $this->buildWeightTrend($client, $from, $to),
            'pain' => $this->buildPainTrend($client, $from, $to),
            'vitals' => $this->buildVitalsTrend($client, $from, $to),
            'fluid_intake' => $this->buildFluidTrend($client, $from, $to),
        ];

        return inertia('health-clinical/ClientTrends', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'filters' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'trend_sets' => $trendSets,
            'has_chartable_data' => collect($trendSets)->contains(
                fn (array $trend) => count($trend['points']) > 0
            ),
            'chartable_observation_count' => collect($trendSets)->sum(
                fn (array $trend) => count($trend['points'])
            ),
        ]);
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     points: array<int, array<string, mixed>>,
     *     count: int,
     *     latest: array<string, mixed>|null,
     * }
     */
    private function buildWeightTrend(Client $client, Carbon $from, Carbon $to): array
    {
        $points = $this->observationService
            ->getTrends($client, ObservationType::Weight, $from, $to)
            ->map(function (ClinicalObservation $observation) {
                $value = $observation->data['weight_kg'] ?? null;

                if (! is_numeric($value)) {
                    return null;
                }

                return [
                    'id' => $observation->id,
                    'recorded_at' => $observation->recorded_at->toISOString(),
                    'short_label' => $observation->recorded_at->format('j M'),
                    'weight_kg' => round((float) $value, 1),
                ];
            })
            ->filter()
            ->values();

        return [
            'key' => 'weight',
            'label' => 'Weight',
            'description' => 'Track body weight over time.',
            'points' => $points->all(),
            'count' => $points->count(),
            'latest' => $points->last(),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     points: array<int, array<string, mixed>>,
     *     count: int,
     *     latest: array<string, mixed>|null,
     * }
     */
    private function buildPainTrend(Client $client, Carbon $from, Carbon $to): array
    {
        $points = $this->observationService
            ->getTrends($client, ObservationType::Pain, $from, $to)
            ->map(function (ClinicalObservation $observation) {
                $score = $observation->data['score'] ?? null;

                if (! is_numeric($score)) {
                    return null;
                }

                return [
                    'id' => $observation->id,
                    'recorded_at' => $observation->recorded_at->toISOString(),
                    'short_label' => $observation->recorded_at->format('j M'),
                    'score' => (float) $score,
                    'location' => $observation->data['location'] ?? null,
                ];
            })
            ->filter()
            ->values();

        return [
            'key' => 'pain',
            'label' => 'Pain Score',
            'description' => 'Track pain score observations on the 0 to 10 scale.',
            'points' => $points->all(),
            'count' => $points->count(),
            'latest' => $points->last(),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     points: array<int, array<string, mixed>>,
     *     count: int,
     *     latest: array<string, mixed>|null,
     * }
     */
    private function buildVitalsTrend(Client $client, Carbon $from, Carbon $to): array
    {
        $points = $this->observationService
            ->getTrends($client, ObservationType::Vitals, $from, $to)
            ->map(function (ClinicalObservation $observation) {
                $systolic = $observation->data['systolic'] ?? null;
                $diastolic = $observation->data['diastolic'] ?? null;
                $pulse = $observation->data['pulse'] ?? null;

                if (! is_numeric($systolic) || ! is_numeric($diastolic) || ! is_numeric($pulse)) {
                    return null;
                }

                return [
                    'id' => $observation->id,
                    'recorded_at' => $observation->recorded_at->toISOString(),
                    'short_label' => $observation->recorded_at->format('j M'),
                    'systolic' => (float) $systolic,
                    'diastolic' => (float) $diastolic,
                    'pulse' => (float) $pulse,
                ];
            })
            ->filter()
            ->values();

        return [
            'key' => 'vitals',
            'label' => 'Vitals',
            'description' => 'Blood pressure and pulse trends.',
            'points' => $points->all(),
            'count' => $points->count(),
            'latest' => $points->last(),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     points: array<int, array<string, mixed>>,
     *     count: int,
     *     latest: array<string, mixed>|null,
     * }
     */
    private function buildFluidTrend(Client $client, Carbon $from, Carbon $to): array
    {
        $points = $this->observationService
            ->getTrends($client, ObservationType::FluidIntake, $from, $to)
            ->map(function (ClinicalObservation $observation) {
                $amount = $observation->data['amount_ml'] ?? null;

                if (! is_numeric($amount)) {
                    return null;
                }

                return [
                    'id' => $observation->id,
                    'recorded_at' => $observation->recorded_at->toISOString(),
                    'short_label' => $observation->recorded_at->format('j M'),
                    'amount_ml' => (float) $amount,
                    'fluid_type' => $observation->data['fluid_type'] ?? null,
                ];
            })
            ->filter()
            ->values();

        return [
            'key' => 'fluid_intake',
            'label' => 'Fluid Intake',
            'description' => 'Track fluid intake amounts in millilitres.',
            'points' => $points->all(),
            'count' => $points->count(),
            'latest' => $points->last(),
        ];
    }
}
