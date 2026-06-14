<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\Calendar\FinanceCalendarAggregator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Finance obligation calendar — a read-only month view of upcoming money
 * obligations (invoice/bill due dates, scheduled payment runs, GST deadlines)
 * aggregated from the Finance ledgers. Gated by finance.dashboard.
 */
class FinanceCalendarController extends Controller
{
    public function __construct(
        private FinanceCalendarAggregator $aggregator,
    ) {}

    /**
     * JSON event feed consumed by the calendar grid, scoped to [start, end].
     */
    public function events(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $start = $request->date('start') ?? Carbon::now()->startOfMonth();
        $end = $request->date('end') ?? Carbon::now()->endOfMonth();

        $sources = $request->filled('sources')
            ? array_values(array_filter(explode(',', $request->string('sources')->toString())))
            : null;

        $events = $this->aggregator->arrayForRange(
            $orgId,
            Carbon::parse($start),
            Carbon::parse($end),
            ['sources' => $sources],
        );

        return response()->json([
            'events' => $events,
            'sources' => $this->aggregator->sources(),
        ]);
    }
}
