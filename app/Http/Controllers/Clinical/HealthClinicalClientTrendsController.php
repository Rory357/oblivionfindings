<?php

namespace App\Http\Controllers\Clinical;

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

        $this->authorize('view', $client);

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

        // Trend-building lives in ClinicalObservationService so the per-client page
        // and the module Trends tab share one implementation.
        $trendSets = $this->observationService->buildTrendSets($client, $from, $to);

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
}
