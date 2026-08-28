<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\FamilyNote;
use App\Models\FamilyVisitRequest;
use App\Models\Shift;
use App\Services\Clients\ClientProfileSectionAccess;
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
        private readonly MedicationGovernanceScopeService $medicationGovernance,
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
                ->whereBetween('requested_date', [$start->toDateString(), $end->toDateString()])
                ->with('user:id,name')
                ->get()
            : collect();

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
                    'status' => $fn->status,
                ],
            ]);
        }

        // 5. Medication administrations (scheduled doses)
        if ($canViewMedication) {
            $medAdminsQuery = ClientMedicationAdministration::query()
                ->effectiveClinicalEvidence()
                ->where('client_id', $client->id)
                ->whereBetween('scheduled_for', [$start, $end])
                ->with('medication:id,name,dosage,route,form');
            $this->medicationGovernance->scopeCanonicalClientMedicationRows(
                $medAdminsQuery,
                [(int) $client->site_id],
                false,
            );
            if (! $canViewControlledMedication) {
                $this->medicationGovernance->scopeWithoutControlledMedicationRows($medAdminsQuery);
            }
            $medAdmins = $medAdminsQuery->get();
        } else {
            $medAdmins = collect();
        }

        foreach ($medAdmins as $ma) {
            $medName = $ma->medication?->name ?? 'Medication';
            $statusLabel = match ($ma->status) {
                'given' => 'Given',
                'refused' => 'Refused',
                'withheld' => 'Withheld',
                'missed' => 'Missed',
                default => 'Scheduled',
            };
            $statusColor = match ($ma->status) {
                'given' => '#10b981',
                'refused' => '#f97316',
                'withheld' => '#eab308',
                'missed' => '#ef4444',
                default => '#ec4899',
            };
            $events->push([
                'id' => 'med-'.$ma->id,
                'title' => $medName.' — '.$statusLabel,
                'start' => $ma->scheduled_for?->toIso8601String() ?? $ma->administered_at?->toIso8601String(),
                'end' => null,
                'allDay' => false,
                'backgroundColor' => $statusColor,
                'borderColor' => 'transparent',
                'extendedProps' => [
                    'type' => 'medication',
                    'status' => $ma->status ?? 'scheduled',
                    'medication_name' => $medName,
                    'dosage' => $ma->medication?->dosage,
                    'route' => $ma->medication?->route,
                    'notes' => $ma->notes,
                    'administered_at' => $ma->administered_at?->toIso8601String(),
                ],
            ]);
        }

        // 6. Scheduled medication doses — only show ± 3 days from today to avoid clutter
        $medStart = $start->greaterThan(now()->subDays(3)->startOfDay())
            ? $start->copy()
            : now()->subDays(3)->startOfDay();
        $medEnd = $end->lessThan(now()->addDays(3)->endOfDay())
            ? $end->copy()
            : now()->addDays(3)->endOfDay();
        $activeMeds = $canViewMedication
            ? ClientMedication::where('client_id', $client->id)
                ->whereHas('client', fn ($query) => $query->whereKey($client->id)
                    ->where('site_id', $client->site_id))
                ->active()
                ->whereNull('ceased_at')
                ->where('is_prn', false)
                ->when(
                    ! $canViewControlledMedication,
                    fn ($query) => $query->where('controlled_drug', false),
                )
                ->get()
            : collect();

        foreach ($activeMeds as $med) {
            $times = $this->parseFrequencyTimes($med->frequency);
            if (empty($times)) {
                continue;
            }

            $current = $medStart->copy();
            while ($current->lte($medEnd)) {
                foreach ($times as $time) {
                    $scheduledAt = $current->copy()->setTimeFromTimeString($time);
                    // Check if there's already an administration record for this slot
                    $alreadyRecorded = $medAdmins->contains(function ($ma) use ($med, $scheduledAt) {
                        return $ma->client_medication_id === $med->id
                            && $ma->scheduled_for
                            && $ma->scheduled_for->format('Y-m-d H:i') === $scheduledAt->format('Y-m-d H:i');
                    });
                    if (! $alreadyRecorded && $scheduledAt->gte($start) && $scheduledAt->lte($end)) {
                        $isPast = $scheduledAt->lt(now());
                        $events->push([
                            'id' => 'medsched-'.$med->id.'-'.$scheduledAt->format('YmdHi'),
                            'title' => $med->name.($isPast ? ' — Overdue' : ' — Due'),
                            'start' => $scheduledAt->toIso8601String(),
                            'end' => null,
                            'allDay' => false,
                            'backgroundColor' => $isPast ? '#ef4444' : '#ec4899',
                            'borderColor' => 'transparent',
                            'extendedProps' => [
                                'type' => 'medication',
                                'status' => $isPast ? 'overdue' : 'scheduled',
                                'medication_name' => $med->name,
                                'dosage' => $med->dosage,
                                'route' => $med->route,
                            ],
                        ]);
                    }
                }
                $current->addDay();
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

    /**
     * Parse common medication frequency strings into scheduled times.
     */
    private function parseFrequencyTimes(?string $frequency): array
    {
        if (! $frequency) {
            return [];
        }

        $freq = strtolower(trim($frequency));

        // Check for explicit times like "08:00, 20:00" or "8am, 8pm"
        if (preg_match_all('/(\d{1,2}):(\d{2})/', $freq, $matches, PREG_SET_ORDER)) {
            return array_map(fn ($m) => sprintf('%02d:%02d', $m[1], $m[2]), $matches);
        }

        // Common frequency keywords
        return match (true) {
            str_contains($freq, 'once daily'), str_contains($freq, 'od'), str_contains($freq, 'daily'), str_contains($freq, 'nocte'), str_contains($freq, 'mane') => ['08:00'],
            str_contains($freq, 'twice daily'), str_contains($freq, 'bd'), str_contains($freq, 'bid') => ['08:00', '20:00'],
            str_contains($freq, 'three times'), str_contains($freq, 'tds'), str_contains($freq, 'tid') => ['08:00', '14:00', '20:00'],
            str_contains($freq, 'four times'), str_contains($freq, 'qds'), str_contains($freq, 'qid') => ['08:00', '12:00', '16:00', '20:00'],
            str_contains($freq, 'morning') => ['08:00'],
            str_contains($freq, 'evening'), str_contains($freq, 'night') => ['20:00'],
            default => [],
        };
    }
}
