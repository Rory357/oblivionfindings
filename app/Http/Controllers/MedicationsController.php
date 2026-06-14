<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\EnhancedMarService;
use App\Services\MarScheduleService;
use App\Services\MedicationAlertService;
use Illuminate\Http\Request;

class MedicationsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.view'), 403);

        $q = Client::query()->orderBy('last_name');

        // Support workers see assigned clients only
        if ($user->hasRole('support_worker') || $user->canDo('clients.viewAssigned')) {
            if (!$user->canDo('clients.viewAny')) {
                $q->whereHas('supportWorkers', fn ($sq) => $sq->whereKey($user->id));
            }
        }

        $clients = $q->get(['id', 'first_name', 'last_name', 'status']);

        // For a central "run-the-day" view, show today's due counts.
        $scheduleService = app(MarScheduleService::class);
        $today = $scheduleService->dateFromInput();
        $mar = app(EnhancedMarService::class);
        $alertService = app(MedicationAlertService::class);

        $cards = $clients->map(function ($c) use ($today, $mar, $alertService) {
            $payload = $mar->build($c, $today);
            // Use stats from EnhancedMarService (single source of truth)
            $stats = $payload['stats']['scheduled'];
            $due = $stats['due'] + $stats['upcoming']; // Include 'due_soon' in due count
            $late = $stats['late'];
            $missed = $stats['missed'];
            
            // Get alerts and discrepancies for this client
            $alerts = $alertService->getActiveAlertsForClient($c->id);
            $discrepancies = \App\Models\ClientControlledDrugDiscrepancy::query()
                ->where('client_id', $c->id)
                ->whereIn('status', ['open', 'under_review'])
                ->count();
            
            return [
                'id' => $c->id,
                'name' => trim($c->first_name . ' ' . $c->last_name),
                'status' => $c->status,
                'counts' => [
                    'due' => $due,
                    'late' => $late,
                    'missed' => $missed,
                ],
                'has_alerts' => count($alerts) > 0,
                'has_critical_alerts' => collect($alerts)->contains(fn($a) => $a['severity'] === 'critical'),
                'discrepancy_count' => $discrepancies,
            ];
        })->values();

        return inertia('medications/index', [
            'date' => $today->toDateString(),
            'clients' => $cards,
        ]);
    }
}
