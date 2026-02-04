<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\FleetMapUsageLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetMapUsageDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $rows = FleetMapUsageLog::query()
            ->selectRaw('context, COUNT(*) as total')
            ->groupBy('context')
            ->get()
            ->map(fn($r) => [
                'context' => $r->context ?? 'unknown',
                'total' => (int) $r->total,
            ])
            ->values();

        return Inertia::render('fleet-management/maps-usage', [
            'rows' => $rows,
            'reverse_geocode' => [
                'enabled' => (bool) config('fleet.maps.reverse_geocode_enabled', false),
                'rate_limit_per_minute' => (int) config('fleet.maps.reverse_geocode_rate_limit_per_minute', 30),
                'cache_ttl_days' => (int) config('fleet.maps.reverse_geocode_cache_ttl_days', 30),
            ],
        ]);
    }
}
