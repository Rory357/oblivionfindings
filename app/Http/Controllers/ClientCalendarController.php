<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\Shift;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\Medication\DoseSlots\ClientCalendarDoses;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Support\ShiftTaskSupport;
use App\Support\WorkerClock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientCalendarController extends Controller
{
    public function __construct(
        private readonly ClientProfileSectionAccess $sectionAccess,
        private readonly ClientCalendarDoses $calendarDoses,
    ) {}

    public function events(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $user = $request->user();
        abort_unless($user && ! $user->hasRole('client', 'next_of_kin'), 403);

        $access = $this->sectionAccess->for($user, $client);
        abort_unless($access['calendar'], 403);
        $canViewMedication = $access['medical']
            && $user->canDo(MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY);
        $canViewControlledMedication = $user->canDo(
            MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY,
        );

        $request->merge([
            'start' => $this->normalizeCalendarBoundaryInput($request->query('start')),
            'end' => $this->normalizeCalendarBoundaryInput($request->query('end')),
        ]);
        $boundaries = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        // UTC instants (a boundary keeps its offset when parsed, and the
        // database compares UTC); the default is the NZ month.
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $start = $this->parseCalendarBoundary($boundaries['start'] ?? null, now($timezone)->startOfMonth(), $timezone)->utc();
        $end = $this->parseCalendarBoundary($boundaries['end'] ?? null, now($timezone)->endOfMonth(), $timezone)->utc();
        $startDate = $start->copy()->timezone($timezone)->toDateString();
        $endDate = $end->copy()->timezone($timezone)->toDateString();
        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'end' => 'The calendar end must be on or after the start.',
            ]);
        }
        if ($start->diffInDays($end) > 93) {
            throw ValidationException::withMessages([
                'end' => 'The calendar range cannot exceed 93 days.',
            ]);
        }

        $events = collect();

        // 1. Shifts
        $shifts = $access['shifts']
            ? Shift::where('client_id', $client->id)
                ->whereBetween('starts_at', [$start, $end])
                ->with(['staff:id,name', 'tasks:id,shift_id,label,scheduled_time,is_completed,sort_order'])
                ->get()
            : collect();

        foreach ($shifts as $s) {
            $isRespite = (bool) $s->respite_booking_id;

            $events->push([
                'id' => 'shift-'.$s->id,
                'title' => ($s->staff?->name ?? 'Staff TBC').' — Shift',
                'start' => $s->starts_at?->toIso8601String(),
                'end' => $s->ends_at?->toIso8601String(),
                'backgroundColor' => $isRespite ? '#7c3aed' : ($s->status === 'completed' ? '#10b981' : ($s->status === 'cancelled' ? '#94a3b8' : '#3b82f6')),
                'borderColor' => 'transparent',
                'extendedProps' => [
                    'type' => 'shift',
                    'status' => $s->status,
                    'is_respite' => $isRespite,
                    'respite_booking_id' => $s->respite_booking_id,
                    'staff_name' => $s->staff?->name,
                    'notes' => $s->notes,
                    'location' => $s->location,
                    'tasks' => ShiftTaskSupport::payloadsForShift($s),
                    'timed_tasks' => ShiftTaskSupport::timedPayloadForShift($s),
                ],
            ]);
        }

        // 2. Approved family visit requests
        $visits = $access['portal_access']
            ? FamilyVisitRequest::where('client_id', $client->id)
                ->where('status', 'approved')
                ->whereBetween('requested_date', [$startDate, $endDate])
                ->with('user:id,name')
                ->get()
            : collect();

        foreach ($visits as $v) {
            // The requested date and times are NZ wall-clock.
            $startTime = Carbon::parse($v->requested_date->toDateString(), $timezone);
            if ($v->preferred_time_start) {
                [$h, $m] = explode(':', $v->preferred_time_start);
                $startTime->setTime((int) $h, (int) $m);
            }
            $endTime = $startTime->copy()->startOfDay();
            if ($v->preferred_time_end) {
                [$h, $m] = explode(':', $v->preferred_time_end);
                $endTime->setTime((int) $h, (int) $m);
            } else {
                $endTime = $startTime->copy()->addHour();
            }

            $visitTypes = ['in_person' => 'In Person', 'video_call' => 'Video Call', 'outing' => 'Outing'];
            $events->push([
                'id' => 'visit-'.$v->id,
                'title' => 'Family Visit — '.($v->user?->name ?? 'Family'),
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
                'id' => 'appt-'.$a->id,
                'title' => $a->title,
                'start' => $a->starts_at->toIso8601String(),
                'end' => $a->ends_at?->toIso8601String(),
                'allDay' => ! $a->ends_at,
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
        $familyNotes = $access['family_notes']
            ? FamilyNote::forClient($client->id)
                ->withDueDate()
                ->open()
                ->whereBetween('due_date', [$startDate, $endDate])
                ->get()
            : collect();

        foreach ($familyNotes as $fn) {
            // The due date and time are NZ wall-clock.
            $noteStart = Carbon::parse($fn->due_date->toDateString(), $timezone);
            if ($fn->due_time) {
                [$h, $m] = explode(':', $fn->due_time);
                $noteStart->setTime((int) $h, (int) $m);
            }
            $events->push([
                'id' => 'fnote-'.$fn->id,
                'title' => '📝 '.$fn->title,
                'start' => $fn->due_time ? $noteStart->toIso8601String() : $fn->due_date->toDateString(),
                'end' => $fn->due_time ? $noteStart->copy()->addHour()->toIso8601String() : null,
                'allDay' => ! $fn->due_time,
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

        // 5. Medication: recorded doses, and the projection's scheduled doses
        // with the states Meds today shows (C6i).
        if ($canViewMedication) {
            foreach ($this->calendarDoses->events($client, $start, $end, $canViewControlledMedication) as $event) {
                $events->push($event);
            }
        }

        return response()->json($events->values());
    }

    public function storeAppointment(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('calendar.create'), 403);

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
            'starts_at' => WorkerClock::toUtc($data['starts_at']),
            'ends_at' => WorkerClock::toUtc($data['ends_at'] ?? null),
            'share_with_family' => $data['share_with_family'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'appointment' => $appointment]);
    }

    public function updateAppointment(Request $request, Client $client, ClientAppointment $appointment)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('calendar.manage'), 403);
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

        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = WorkerClock::toUtc($data[$field]);
            }
        }

        $effectiveStart = $data['starts_at'] ?? $appointment->starts_at;
        $effectiveEnd = array_key_exists('ends_at', $data)
            ? $data['ends_at']
            : $appointment->ends_at;

        if ($effectiveEnd !== null && ! $effectiveEnd->gt($effectiveStart)) {
            throw ValidationException::withMessages([
                'ends_at' => 'The appointment end must be after the start.',
            ]);
        }

        $appointment->update($data);

        return response()->json(['success' => true, 'appointment' => $appointment->fresh()]);
    }

    public function destroyAppointment(Request $request, Client $client, ClientAppointment $appointment)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('calendar.manage'), 403);
        abort_unless($appointment->client_id === $client->id, 404);

        $appointment->delete();

        return response()->json(['success' => true]);
    }

    /** A boundary without an offset is NZ wall-clock. */
    private function parseCalendarBoundary(mixed $value, Carbon $fallback, string $timezone): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (! is_string($value) || trim($value) === '') {
            return $fallback->copy();
        }

        $normalized = $this->normalizeCalendarBoundaryInput($value);

        return Carbon::parse($normalized, $timezone);
    }

    private function normalizeCalendarBoundaryInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return preg_replace('/(?<=T\d{2}:\d{2}:\d{2}) (?=\d{2}:\d{2}$)/', '+', $trimmed) ?? $trimmed;
    }
}
