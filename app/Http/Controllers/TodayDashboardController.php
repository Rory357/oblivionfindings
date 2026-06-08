<?php

namespace App\Http\Controllers;

use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\Shift;
use App\Services\MarScheduleService;
use Illuminate\Http\Request;

class TodayDashboardController extends Controller
{
    public function __invoke(Request $request, MarScheduleService $mar)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $today = $mar->dateFromInput();
        $tomorrow = $today->copy()->addDay();

        $shiftQuery = Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'client.medications:id,client_id,name,dosage,frequency,dose_times,is_prn,controlled_drug,active',
                'staff:id,name,email',
            ])
            ->whereBetween('starts_at', [$today->copy()->utc(), $tomorrow->copy()->utc()])
            ->orderBy('starts_at');

        // Staff see only their own shifts unless manageAny
        if (!$user->canDo('shifts.manageAny')) {
            $shiftQuery
                ->where('user_id', $user->id)
                ->visibleToFrontline($user->organization_id);
        }

        $shifts = $shiftQuery->get();

        // Open incidents created by the user (last 14 days)
        $incidents = ClientIncident::query()
            ->where('reported_by', $user->id)
            ->whereIn('status', ['draft', 'submitted', 'reviewed'])
            ->where('occurred_at', '>=', now()->subDays(14))
            ->with(['client:id,first_name,last_name'])
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'client' => $i->client ? ($i->client->first_name . ' ' . $i->client->last_name) : null,
                'type' => $i->type,
                'severity' => $i->severity,
                'status' => $i->status,
                'occurred_at' => optional($i->occurred_at)->toISOString(),
            ]);

        // MAR due/overdue (only for clients in today's shifts)
        $due = [];
        $now = now($mar->workerTimezone());
        $windowStart = $now->copy()->subHours(2); // show recent overdue
        $windowEnd = $now->copy()->addHours(4);   // and upcoming

        $clientIds = $shifts->pluck('client_id')->unique()->filter()->values();
        if ($clientIds->count() > 0) {
            $admins = ClientMedicationAdministration::query()
                ->whereIn('client_id', $clientIds)
                ->whereBetween('scheduled_for', [$today->copy()->utc(), $tomorrow->copy()->utc()])
                ->get()
                ->keyBy(function ($a) {
                    $rawScheduledFor = $a->getRawOriginal('scheduled_for');
                    $ts = $rawScheduledFor
                        ? \Illuminate\Support\Carbon::parse((string) $rawScheduledFor, 'UTC')->format('Y-m-d H:i')
                        : '';

                    return $a->client_id . '|' . $a->client_medication_id . '|' . $ts;
                });

            foreach ($shifts as $shift) {
                $client = $shift->client;
                if (!$client) {
                    continue;
                }

                $activeMeds = $client->medications
                    ->filter(fn ($m) => (bool) ($m->active ?? true) && !(bool) ($m->is_prn ?? false))
                    ->values();

                foreach ($activeMeds as $med) {
                    $scheduledTimes = $mar->scheduledTimesForDate($med, $today);
                    foreach ($scheduledTimes as $scheduledFor) {
                        if ($scheduledFor->lessThan($windowStart) || $scheduledFor->greaterThan($windowEnd)) {
                            continue;
                        }

                        $key = $client->id . '|' . $med->id . '|' . $scheduledFor->copy()->utc()->format('Y-m-d H:i');
                        if ($admins->has($key)) {
                            continue;
                        }

                        $state = $mar->statusForDose($now, $scheduledFor, null)['state'] ?? 'due';
                        if (!in_array($state, ['due', 'due_soon', 'overdue'], true)) {
                            continue;
                        }

                        $due[] = [
                            'client_id' => $client->id,
                            'client_name' => $client->first_name . ' ' . $client->last_name,
                            'medication_id' => $med->id,
                            'medication_name' => $med->name,
                            'scheduled_for' => $scheduledFor->toISOString(),
                            'state' => $state,
                            'shift_id' => $shift->id,
                        ];
                    }
                }
            }
        }

        $due = collect($due)
            ->sortBy('scheduled_for')
            ->take(25)
            ->values();

        return inertia('dashboard/today', [
            'date' => $today->toDateString(),
            'shifts' => $shifts,
            'dueMeds' => $due,
            'openIncidents' => $incidents,
        ]);
    }
}
