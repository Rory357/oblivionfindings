<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\Shift;
use App\Models\TimelineEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ClientCalendarController extends Controller
{
    public function events(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $start = Carbon::parse($request->query('start', now()->startOfMonth()));
        $end = Carbon::parse($request->query('end', now()->endOfMonth()));

        $events = collect();

        // 1. Shifts
        $shifts = Shift::where('client_id', $client->id)
            ->whereBetween('starts_at', [$start, $end])
            ->with('staff:id,name')
            ->get();

        foreach ($shifts as $s) {
            $events->push([
                'id' => 'shift-' . $s->id,
                'title' => ($s->staff?->name ?? 'Staff TBC') . ' — Shift',
                'start' => $s->starts_at?->toIso8601String(),
                'end' => $s->ends_at?->toIso8601String(),
                'backgroundColor' => $s->status === 'completed' ? '#10b981' : ($s->status === 'cancelled' ? '#94a3b8' : '#3b82f6'),
                'borderColor' => 'transparent',
                'extendedProps' => [
                    'type' => 'shift',
                    'status' => $s->status,
                    'staff_name' => $s->staff?->name,
                    'notes' => $s->notes,
                    'location' => $s->location,
                ],
            ]);
        }

        // 2. Approved family visit requests
        $visits = FamilyVisitRequest::where('client_id', $client->id)
            ->where('status', 'approved')
            ->whereBetween('requested_date', [$start->toDateString(), $end->toDateString()])
            ->with('user:id,name')
            ->get();

        foreach ($visits as $v) {
            $startTime = $v->requested_date->copy();
            if ($v->preferred_time_start) {
                [$h, $m] = explode(':', $v->preferred_time_start);
                $startTime->setTime((int) $h, (int) $m);
            }
            $endTime = $v->requested_date->copy();
            if ($v->preferred_time_end) {
                [$h, $m] = explode(':', $v->preferred_time_end);
                $endTime->setTime((int) $h, (int) $m);
            } else {
                $endTime = $startTime->copy()->addHour();
            }

            $visitTypes = ['in_person' => 'In Person', 'video_call' => 'Video Call', 'outing' => 'Outing'];
            $events->push([
                'id' => 'visit-' . $v->id,
                'title' => 'Family Visit — ' . ($v->user?->name ?? 'Family'),
                'start' => $startTime->toIso8601String(),
                'end' => $endTime->toIso8601String(),
                'backgroundColor' => '#22c55e',
                'borderColor' => 'transparent',
                'extendedProps' => [
                    'type' => 'family_visit',
                    'visit_type' => $visitTypes[$v->visit_type] ?? $v->visit_type,
                    'requester' => $v->user?->name,
                    'notes' => $v->notes,
                    'review_notes' => $v->review_notes,
                ],
            ]);
        }

        // 3. Client appointments
        $appointments = ClientAppointment::forClient($client->id)
            ->inRange($start, $end)
            ->where('status', '!=', 'cancelled')
            ->with('creator:id,name')
            ->get();

        $typeColors = [
            'gp_visit' => '#f59e0b',
            'specialist' => '#8b5cf6',
            'therapy' => '#ec4899',
            'activity' => '#06b6d4',
            'reminder' => '#6366f1',
            'other' => '#64748b',
        ];

        foreach ($appointments as $a) {
            $events->push([
                'id' => 'appt-' . $a->id,
                'title' => $a->title,
                'start' => $a->starts_at->toIso8601String(),
                'end' => $a->ends_at?->toIso8601String(),
                'allDay' => !$a->ends_at,
                'backgroundColor' => $typeColors[$a->appointment_type] ?? '#64748b',
                'borderColor' => 'transparent',
                'extendedProps' => [
                    'type' => 'appointment',
                    'appointment_type' => $a->appointment_type,
                    'status' => $a->status,
                    'location' => $a->location,
                    'provider_name' => $a->provider_name,
                    'description' => $a->description,
                    'share_with_family' => $a->share_with_family,
                    'appointment_id' => $a->id,
                ],
            ]);
        }

        // 4. Family notes with due dates
        $familyNotes = FamilyNote::forClient($client->id)
            ->withDueDate()
            ->open()
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        foreach ($familyNotes as $fn) {
            $noteStart = $fn->due_date->copy();
            if ($fn->due_time) {
                [$h, $m] = explode(':', $fn->due_time);
                $noteStart->setTime((int) $h, (int) $m);
            }
            $events->push([
                'id' => 'fnote-' . $fn->id,
                'title' => '📝 ' . $fn->title,
                'start' => $fn->due_time ? $noteStart->toIso8601String() : $fn->due_date->toDateString(),
                'end' => $fn->due_time ? $noteStart->copy()->addHour()->toIso8601String() : null,
                'allDay' => !$fn->due_time,
                'backgroundColor' => '#a78bfa',
                'borderColor' => 'transparent',
                'extendedProps' => [
                    'type' => 'family_note',
                    'note_type' => $fn->note_type,
                    'priority' => $fn->priority,
                    'description' => $fn->description,
                    'status' => $fn->status,
                ],
            ]);
        }

        return response()->json($events->values());
    }

    public function storeAppointment(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'appointment_type' => 'required|string|in:gp_visit,specialist,therapy,activity,reminder,other',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'location' => 'nullable|string|max:255',
            'provider_name' => 'nullable|string|max:255',
            'share_with_family' => 'nullable|boolean',
        ]);

        $appointment = ClientAppointment::create([
            'client_id' => $client->id,
            ...$data,
            'share_with_family' => $data['share_with_family'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        TimelineEvent::create([
            'source_type' => ClientAppointment::class,
            'source_id' => $appointment->id,
            'occurred_at' => now(),
            'type' => 'appointment_scheduled',
            'actor_user_id' => $request->user()->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Appointment scheduled: ' . $data['title'],
            'body' => $data['description'],
            'meta' => array_filter([
                'appointment_type' => $data['appointment_type'],
                'starts_at' => $data['starts_at'],
                'location' => $data['location'] ?? null,
                'provider_name' => $data['provider_name'] ?? null,
            ]),
            'visibility' => ($data['share_with_family'] ?? true) ? 'portal' : 'internal',
            'is_pinned' => false,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'appointment' => $appointment]);
    }

    public function updateAppointment(Request $request, Client $client, ClientAppointment $appointment)
    {
        $this->authorize('view', $client);
        abort_unless($appointment->client_id === $client->id, 404);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'appointment_type' => 'sometimes|string|in:gp_visit,specialist,therapy,activity,reminder,other',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'provider_name' => 'nullable|string|max:255',
            'status' => 'sometimes|string|in:scheduled,completed,cancelled,no_show',
            'share_with_family' => 'nullable|boolean',
        ]);

        $appointment->update($data);

        return response()->json(['success' => true, 'appointment' => $appointment->fresh()]);
    }

    public function destroyAppointment(Request $request, Client $client, ClientAppointment $appointment)
    {
        $this->authorize('view', $client);
        abort_unless($appointment->client_id === $client->id, 404);

        $appointment->delete();

        return response()->json(['success' => true]);
    }
}
