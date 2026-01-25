<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMedicationAdministration;
use App\Services\MarScheduleService;
use Illuminate\Http\Request;

class MedicationsModuleController extends Controller
{
    public function index(Request $request, MarScheduleService $mar)
    {
        $user = $request->user();

        // Permission gate for the module. Support workers will have this permission in seeded RBAC.
        abort_unless(($user?->canDo('medications.viewAny') ?? false) || ($user?->canDo('clients.update') ?? false), 403);

        $date = $request->query('date')
            ? \Carbon\Carbon::parse($request->query('date'))->startOfDay()
            : now()->startOfDay();

        $q = Client::query()
            ->with([
                'site:id,name',
                'serviceContext:id,type,name',
                'supportWorkers:id,name,email',
                'medications',
            ])
            ->orderBy('last_name')
            ->orderBy('first_name');

        // Support workers only see assigned clients.
        if ($user && $user->hasRole('support_worker')) {
            $q->whereHas('supportWorkers', fn ($qq) => $qq->whereKey($user->id));
        }

        $clients = $q->get(['id', 'site_id', 'service_context_id', 'first_name', 'last_name', 'status']);

        // Pull administrations for the selected day for all visible clients so "due" counts are accurate.
        $adminRows = ClientMedicationAdministration::query()
            ->whereIn('client_id', $clients->pluck('id'))
            ->whereBetween('scheduled_for', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->get(['id', 'client_id', 'client_medication_id', 'scheduled_for', 'administered_at', 'status']);

        $adminByKey = $adminRows->groupBy(fn ($a) => $a->client_id)
            ->map(fn ($group) => $group->keyBy(fn ($a) => $a->client_medication_id . '|' . optional($a->scheduled_for)->format('Y-m-d H:i')));

        $cards = $clients->map(function (Client $c) use ($mar, $date, $adminByKey) {
            $due = 0;
            $late = 0;
            $upcoming = 0;

            $clientAdmins = $adminByKey->get($c->id) ?? collect();

            foreach ($c->medications as $m) {
                $times = $mar->scheduledTimesForDate($m, $date);
                foreach ($times as $scheduledFor) {
                    $key = $m->id . '|' . $scheduledFor->format('Y-m-d H:i');
                    $existing = $clientAdmins->get($key);
                    $adminPayload = $existing ? ['administered_at' => $existing->administered_at, 'scheduled_for' => $existing->scheduled_for] : null;
                    $state = $mar->statusForDose(now(), $scheduledFor, $adminPayload)['state'];
                    if ($state === 'due') $due++;
                    if ($state === 'late') $late++;
                    if ($state === 'due_soon' || $state === 'upcoming') $upcoming++;
                }
            }

            return [
                'id' => $c->id,
                'name' => trim("{$c->first_name} {$c->last_name}"),
                'status' => $c->status,
                'site' => $c->site ? ['id' => $c->site->id, 'name' => $c->site->name] : null,
                'service_context' => $c->serviceContext ? [
                    'id' => $c->serviceContext->id,
                    'type' => $c->serviceContext->type?->value,
                    'name' => $c->serviceContext->name,
                ] : null,
                'counts' => [
                    'due' => $due,
                    'late' => $late,
                    'upcoming' => $upcoming,
                ],
            ];
        })->values();

        return inertia('medications/index', [
            'date' => $date->toDateString(),
            'clients' => $cards,
        ]);
    }
}
