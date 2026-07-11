<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\RespiteBooking;
use App\Models\Shift;
use App\Services\Portal\PortalClientSectionAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortalCalendarController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $visitRequests = FamilyVisitRequest::where('user_id', $user->id)
            ->where('client_id', $client->id)
            ->upcoming()
            ->orderBy('requested_date')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'requested_date' => $v->requested_date?->toDateString(),
                'preferred_time_start' => $v->preferred_time_start,
                'preferred_time_end' => $v->preferred_time_end,
                'visit_type' => $v->visit_type,
                'notes' => $v->notes,
                'status' => $v->status,
                'review_notes' => $v->review_notes,
            ]);

        return inertia('portal/calendar', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
            ],
            'visitRequests' => $visitRequests->values(),
        ]);
    }

    public function events(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $sectionAccess = app(PortalClientSectionAccess::class)->for($user, $client);
        $showShiftSchedule = $sectionAccess['show_shift_schedule'];
        $showRespite = $sectionAccess['show_respite'];
        $showCareNotes = $sectionAccess['show_care_notes'];
        $canViewSharedCare = $sectionAccess['has_family_information_consent'];

        $request->merge([
            'start' => $this->normalizeCalendarBoundaryInput($request->query('start')),
            'end' => $this->normalizeCalendarBoundaryInput($request->query('end')),
        ]);
        $boundaries = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ]);
        $start = $this->parseCalendarBoundary($boundaries['start'] ?? null, now()->startOfMonth());
        $end = $this->parseCalendarBoundary($boundaries['end'] ?? null, now()->endOfMonth());
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

        // 1. Shifts (staff visits)
        if ($showShiftSchedule) {
            $shifts = Shift::where('client_id', $client->id)
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->whereIn('status', ['scheduled', 'in_progress', 'completed'])
                ->with(['staff:id,name', 'serviceContext:id,name'])
                ->get();

            foreach ($shifts as $s) {
                $isRespite = (bool) $s->respite_booking_id;

                $events->push([
                    'id' => 'shift-'.$s->id,
                    'title' => ($s->staff?->name ?? 'Support Worker').' — '.ucfirst(str_replace('_', ' ', $s->shift_type ?? 'support')),
                    'start' => $s->starts_at?->toIso8601String(),
                    'end' => $s->ends_at?->toIso8601String(),
                    'backgroundColor' => $isRespite ? '#7c3aed' : ($s->status === 'completed' ? '#10b981' : '#3b82f6'),
                    'borderColor' => 'transparent',
                    'extendedProps' => [
                        'type' => 'shift',
                        'status' => $s->status,
                        'is_respite' => $isRespite,
                        'respite_booking_id' => $s->respite_booking_id,
                        'staff_name' => $s->staff?->name,
                        'shift_type' => $s->shift_type ?? 'standard',
                        'service_context' => $s->serviceContext?->name,
                        'location' => $s->location,
                        'is_sleepover' => (bool) $s->is_sleepover,
                        'is_on_call' => (bool) $s->is_on_call,
                        'expected_break_minutes' => $s->expected_break_minutes,
                    ],
                ]);
            }
        }

        if ($showRespite) {
            $respiteBookings = RespiteBooking::query()
                ->where('client_id', $client->id)
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->where('start_at', '<', $end)
                ->where('end_at', '>', $start)
                ->with(['stays' => fn ($query) => $query->latest('actual_start')])
                ->get();

            foreach ($respiteBookings as $booking) {
                $events->push([
                    'id' => 'respite-'.$booking->id,
                    'title' => 'Respite stay',
                    'start' => $booking->start_at?->toIso8601String(),
                    'end' => $booking->end_at?->toIso8601String(),
                    'backgroundColor' => '#7c3aed',
                    'borderColor' => 'transparent',
                    'extendedProps' => [
                        'type' => 'respite_stay',
                        'booking_status' => $booking->status,
                        'stay_status' => $booking->stays->first()?->status,
                        'respite_booking_id' => $booking->id,
                    ],
                ]);
            }
        }

        // 2. Approved family visit requests
        $visits = FamilyVisitRequest::where('client_id', $client->id)
            ->where('status', 'approved')
            ->whereBetween('requested_date', [$start->toDateString(), $end->toDateString()])
            ->when(! $canViewSharedCare, fn ($query) => $query->where('user_id', $user->id))
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
                'id' => 'visit-'.$v->id,
                'title' => 'Family Visit — '.($visitTypes[$v->visit_type] ?? $v->visit_type),
                'start' => $startTime->toIso8601String(),
                'end' => $endTime->toIso8601String(),
                'backgroundColor' => '#22c55e',
                'borderColor' => 'transparent',
                'extendedProps' => [
                    'type' => 'family_visit',
                    'visit_type' => $visitTypes[$v->visit_type] ?? $v->visit_type,
                    'notes' => $v->notes,
                ],
            ]);
        }

        // 3. Appointments shared with family
        $appointments = $canViewSharedCare
            ? ClientAppointment::forClient($client->id)
                ->inRange($start, $end)
                ->sharedWithFamily()
                ->where('status', '!=', 'cancelled')
                ->get()
            : collect();

        $typeColors = [
            'gp_visit' => '#f59e0b',
            'specialist' => '#8b5cf6',
            'therapy' => '#ec4899',
            'activity' => '#06b6d4',
            'reminder' => '#6366f1',
            'other' => '#64748b',
        ];

        $typeLabels = [
            'gp_visit' => 'GP Visit',
            'specialist' => 'Specialist',
            'therapy' => 'Therapy',
            'activity' => 'Activity',
            'reminder' => 'Reminder',
            'other' => 'Appointment',
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
                    'appointment_type' => $typeLabels[$a->appointment_type] ?? $a->appointment_type,
                    'location' => $a->location,
                    'provider_name' => $a->provider_name,
                    'description' => $a->description,
                ],
            ]);
        }

        // 4. Family notes with due dates
        $familyNotes = $showCareNotes
            ? FamilyNote::forClient($client->id)
                ->withDueDate()
                ->open()
                ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
                ->get()
            : collect();

        foreach ($familyNotes as $fn) {
            $noteStart = $fn->due_date->copy();
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
                ],
            ]);
        }

        return response()->json($events->values());
    }

    private function parseCalendarBoundary(mixed $value, Carbon $fallback): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (! is_string($value) || trim($value) === '') {
            return $fallback->copy();
        }

        $normalized = $this->normalizeCalendarBoundaryInput($value);

        return Carbon::parse($normalized);
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
