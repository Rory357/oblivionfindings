<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\MedicationMarService;
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
        $today = now()->startOfDay();
        $mar = app(MedicationMarService::class);

        $cards = $clients->map(function ($c) use ($today, $mar) {
            $payload = $mar->build($c, $today);
            $due = 0; $late = 0; $missed = 0;
            foreach ($payload['rows'] as $row) {
                if ($row['scheduled_for'] === null) continue;
                if ($row['record']) continue;
                if ($row['schedule_state'] === 'due' || $row['schedule_state'] === 'due_soon') $due++;
                if ($row['schedule_state'] === 'late') $late++;
                if ($row['schedule_state'] === 'missed_auto') $missed++;
            }
            return [
                'id' => $c->id,
                'name' => trim($c->first_name . ' ' . $c->last_name),
                'status' => $c->status,
                'counts' => [
                    'due' => $due,
                    'late' => $late,
                    'missed' => $missed,
                ],
            ];
        })->values();

        return inertia('medications/index', [
            'date' => $today->toDateString(),
            'clients' => $cards,
        ]);
    }
}
