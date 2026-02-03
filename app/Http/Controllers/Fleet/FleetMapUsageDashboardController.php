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
        ]);
    }
}
