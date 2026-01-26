<?php

namespace App\Http\Controllers;

use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\Shift;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $from7 = now()->subDays(7)->startOfDay();

        $kpis = [
            'openIncidents' => ClientIncident::query()
                ->whereIn('status', ['submitted', 'reviewed'])
                ->count(),
            'missedMeds7d' => ClientMedicationAdministration::query()
                ->where('created_at', '>=', $from7)
                ->whereIn('status', ['missed', 'withheld', 'refused'])
                ->count(),
            'completedShifts7d' => Shift::query()
                ->where('starts_at', '>=', $from7)
                ->where('status', 'completed')
                ->count(),
        ];

        return inertia('reports/index', [
            'kpis' => $kpis,
        ]);
    }
}
