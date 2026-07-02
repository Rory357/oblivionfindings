<?php

namespace App\Http\Controllers\Respite;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Events\Respite\RespiteEvent;
use App\Http\Controllers\Controller;
use App\Models\BehaviourSupportPlan;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\DataBreachLog;
use App\Models\MedicationAllergy;
use App\Models\RespiteBooking;
use App\Models\RespiteComplaint;
use App\Models\RespiteMedicationReconciliation;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Services\Respite\RespiteShiftSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RespiteStayController extends Controller
{
    public function index(): Response
    {
        $stays = RespiteStay::with(['client', 'booking'])
            ->latest()
            ->paginate(25);

        return Inertia::render('respite/stays/index', [
            'stays' => $stays,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:respite_bookings,id',
            'client_id' => 'required|exists:clients,id',
        ]);

        $booking = RespiteBooking::with('client')->findOrFail($validated['booking_id']);
        $this->authorize('view', $booking->client);

        if ((int) $booking->client_id !== (int) $validated['client_id']) {
            throw ValidationException::withMessages([
                'client_id' => 'The stay client must match the respite booking client.',
            ]);
        }

        $validated['status'] = 'admitted';
        $validated['created_by'] = auth()->id();
        $validated['actual_start'] = now();

        $stay = RespiteStay::create($validated);

        event(new RespiteEvent('respite.stay.created', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return redirect()
            ->route('respite.stays.show', $stay)
            ->with('success', 'Respite stay created.');
    }

    public function show(RespiteStay $stay): Response
    {
        $stay->load([
            'client',
            'booking.coordinator',
            'evidencePack',
            'handovers',
            'communications',
            'dailyNotes',
            'riskPlanActivations',
            'createdByUser',
        ]);
        $this->authorize('view', $stay->client);

        return Inertia::render('respite/stays/show', [
            'stay' => $stay,
        ]);
    }

    public function checkIn(RespiteStay $stay): RedirectResponse
    {
        $validated = request()->validate([
            'med_rec_override_reason' => 'nullable|string|max:500',
            'anaphylaxis_acknowledged' => 'nullable|boolean',
            'epipen_location' => 'nullable|string|max:500',
            'anaphylaxis_escalation_note' => 'nullable|string|max:1000',
        ]);

        $stay->loadMissing('client');
        $this->authorize('view', $stay->client);

        $this->guardAnaphylaxisAcknowledgement($stay, $validated);
        $this->guardAdmissionMedicationReconciliation($stay, $validated['med_rec_override_reason'] ?? null);

        $stay->update([
            'status' => 'active',
            'actual_start' => $stay->actual_start ?? now(),
            'updated_by' => auth()->id(),
        ]);

        app(RespiteShiftSync::class)->checkInStay($stay, $stay->actual_start, auth()->id());

        event(new RespiteEvent('respite.stay.checked_in', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay checked in.');
    }

    public function extend(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client', 'booking');
        $this->authorize('view', $stay->client);

        $validated = $request->validate([
            'new_end' => 'required|date',
        ]);

        $newEnd = Carbon::parse($validated['new_end']);
        $actualStart = $stay->actual_start ?: $stay->booking?->start_at;

        if ($actualStart && $newEnd->lte($actualStart)) {
            throw ValidationException::withMessages([
                'new_end' => 'The new end must be after the stay start.',
            ]);
        }

        $stay->update([
            'status' => 'extended',
            'actual_end' => $newEnd,
            'updated_by' => auth()->id(),
        ]);

        app(RespiteShiftSync::class)->extendStay($stay, $newEnd);

        event(new RespiteEvent('respite.stay.extended', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay extended.');
    }

    public function recordBedHold(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client');
        $this->authorize('view', $stay->client);

        $validated = $request->validate([
            'bed_hold_status' => 'required|in:held,released,cancelled',
            'bed_hold_reason' => 'nullable|in:cultural_leave,whanau_visit,hospital_transfer,home_visit,other',
            'bed_hold_until' => 'nullable|date',
            'absence_record' => 'nullable|array',
        ]);

        $absenceRecords = $stay->absence_records ?? [];
        if (! empty($validated['absence_record'])) {
            $absenceRecords[] = [
                ...$validated['absence_record'],
                'bed_hold_status' => $validated['bed_hold_status'],
                'bed_hold_reason' => $validated['bed_hold_reason'] ?? null,
                'recorded_by' => auth()->id(),
                'recorded_at' => now()->toIso8601String(),
            ];
        }

        $stay->update([
            'bed_hold_status' => $validated['bed_hold_status'],
            'bed_hold_reason' => $validated['bed_hold_reason'] ?? null,
            'bed_hold_until' => $validated['bed_hold_until'] ?? null,
            'absence_records' => $absenceRecords,
            'updated_by' => auth()->id(),
        ]);

        event(new RespiteEvent('respite.stay.bed_hold_recorded', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $validated['bed_hold_status'],
        ]));

        return back()->with('success', 'Bed hold updated.');
    }

    public function discharge(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client');
        $this->authorize('view', $stay->client);
        $alreadyDischarged = $stay->status === 'discharged' && $stay->actual_end !== null;

        $validated = $request->validate([
            'discharge_summary' => 'required|string',
            'discharge_reason' => 'nullable|in:planned,early_by_family,clinical,incident,transferred_to_hospital',
            'discharge_medication_reconciliation' => 'nullable|array',
            'discharge_medication_reconciliation.medicines_returned_to' => 'nullable|string|max:255',
            'discharge_medication_reconciliation.count' => 'nullable|integer|min:0',
            'discharge_medication_reconciliation.received_by' => 'nullable|string|max:255',
            'discharge_medication_reconciliation.changed_during_stay' => 'nullable|boolean',
            'discharge_medication_reconciliation.gp_pharmacy_handover_sent' => 'nullable|boolean',
            'discharge_medication_reconciliation.whanau_briefing_acknowledged' => 'nullable|boolean',
        ]);

        $this->guardDischargeMedicationReconciliation($stay, $validated['discharge_medication_reconciliation'] ?? null);
        $this->guardDischargeCompliance($stay);

        $stay->update([
            'status' => 'discharged',
            'actual_end' => now(),
            'discharge_summary' => $validated['discharge_summary'],
            'discharge_reason' => $validated['discharge_reason'] ?? 'planned',
            'discharge_medication_reconciliation' => $validated['discharge_medication_reconciliation'] ?? $stay->discharge_medication_reconciliation,
            'updated_by' => auth()->id(),
        ]);

        app(RespiteShiftSync::class)->dischargeStay($stay, $validated['discharge_summary'], $stay->actual_end, auth()->id());

        if (! $alreadyDischarged) {
            $this->postFundingConsumption($stay->fresh('booking.serviceAgreement'));
        }

        event(new RespiteEvent('respite.stay.discharged', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay discharged.');
    }

    public function storeMedicationReconciliation(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client', 'booking');
        $this->authorize('view', $stay->client);

        $validated = $request->validate([
            'type' => 'nullable|in:admission,discharge',
            'status' => 'required|in:not_started,in_progress,completed,overridden',
            'source' => 'nullable|string|max:255',
            'count_received' => 'nullable|integer|min:0',
            'discrepancies' => 'nullable|array',
            'first_dose_due_at' => 'nullable|date',
            'override_reason' => 'nullable|string|max:1000',
        ]);

        $type = $validated['type'] ?? 'admission';

        $reconciliation = RespiteMedicationReconciliation::updateOrCreate(
            ['stay_id' => $stay->id, 'type' => $type],
            [
                'status' => $validated['status'],
                'source' => $validated['source'] ?? null,
                'count_received' => $validated['count_received'] ?? null,
                'discrepancies' => $validated['discrepancies'] ?? null,
                'first_dose_due_at' => $validated['first_dose_due_at'] ?? null,
                'override_reason' => $validated['override_reason'] ?? null,
                'reconciled_by_user_id' => auth()->id(),
                'reconciled_at' => in_array($validated['status'], ['completed', 'overridden'], true) ? now() : null,
                'updated_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]
        );

        if ($type === 'admission' && $reconciliation->status === 'completed') {
            $stay->booking?->update([
                'medications_reconciled' => true,
                'medications_reconciled_at' => $reconciliation->reconciled_at ?? now(),
                'medications_reconciled_by' => auth()->id(),
            ]);
        }

        event(new RespiteEvent('respite.stay.medication_reconciliation_recorded', [
            'id' => $reconciliation->id,
            'stay_id' => $stay->id,
            'client_id' => $stay->client_id,
            'type' => $type,
            'status' => $reconciliation->status,
        ]));

        return back()->with('success', 'Medication reconciliation recorded.');
    }

    public function recordRestraint(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client', 'booking');
        $this->authorize('view', $stay->client);

        $validated = $request->validate([
            'behaviour_support_plan_id' => 'nullable|exists:behaviour_support_plans,id',
            'started_at' => 'required|date',
            'ended_at' => 'nullable|date|after_or_equal:started_at',
            'duration_minutes' => 'nullable|integer|min:0',
            'restraint_type' => 'required|string|max:255',
            'severity' => 'required|in:low,medium,high,critical',
            'trigger_description' => 'required|string',
            'de_escalation_attempted' => 'required|string',
            'restraint_description' => 'required|string',
            'staff_involved' => 'nullable|array',
            'person_response' => 'nullable|string',
            'post_incident_support' => 'nullable|string',
            'injury_occurred' => 'nullable|boolean',
            'injury_details' => 'nullable|string',
            'within_support_plan' => 'nullable|boolean',
            'deviation_reason' => 'nullable|string',
            'authorised_by' => 'nullable|exists:users,id',
            'related_incident_id' => 'nullable|exists:client_incidents,id',
        ]);

        $started = Carbon::parse($validated['started_at']);
        $ended = isset($validated['ended_at']) ? Carbon::parse($validated['ended_at']) : null;
        $withinSupportPlan = (bool) ($validated['within_support_plan'] ?? true);
        $behaviourSupportPlanId = $validated['behaviour_support_plan_id'] ?? null;

        if ($withinSupportPlan && ! $behaviourSupportPlanId) {
            $behaviourSupportPlanId = $this->activeBehaviourSupportPlanId($stay);
        }

        $event = RestraintEvent::create([
            ...$validated,
            'behaviour_support_plan_id' => $behaviourSupportPlanId,
            'stay_id' => $stay->id,
            'client_id' => $stay->client_id,
            'site_id' => $stay->booking?->location_id ?: $stay->client?->site_id,
            'duration_minutes' => $validated['duration_minutes'] ?? ($ended ? $started->diffInMinutes($ended) : null),
            'injury_occurred' => (bool) ($validated['injury_occurred'] ?? false),
            'within_support_plan' => $withinSupportPlan,
            'created_by' => auth()->id(),
        ]);

        event(new RespiteEvent('respite.stay.restraint_recorded', [
            'id' => $event->id,
            'stay_id' => $stay->id,
            'client_id' => $stay->client_id,
        ]));

        return back()->with('success', 'Restraint event recorded.');
    }

    public function recordIncident(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client');
        $this->authorize('view', $stay->client);

        $validated = $request->validate([
            'type' => 'required|string|max:255',
            'severity' => 'required|in:low,medium,high,critical',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'occurred_at' => 'nullable|date',
            'immediate_action_taken' => 'nullable|string',
            'witnesses' => 'nullable|string',
            'is_notifiable' => 'nullable|boolean',
            'notification_authority' => 'nullable|in:worksafe,health_nz,privacy_commissioner,charities_services',
            'incident_type' => 'nullable|in:death,serious_harm,serious_injury,health_safety,privacy_breach',
        ]);

        $incident = ClientIncident::create([
            'client_id' => $stay->client_id,
            'reported_by' => auth()->id(),
            'respite_stay_id' => $stay->id,
            'type' => $validated['type'],
            'severity' => $validated['severity'],
            'status' => 'submitted',
            'submitted_at' => now(),
            'occurred_at' => $validated['occurred_at'] ?? now(),
            'title' => $validated['title'],
            'description' => $validated['description'],
            'requires_followup' => in_array($validated['severity'], ['high', 'critical'], true),
            'immediate_action_taken' => $validated['immediate_action_taken'] ?? null,
            'witnesses' => $validated['witnesses'] ?? null,
            'is_notifiable' => (bool) ($validated['is_notifiable'] ?? false),
            'metadata' => [
                'source' => 'respite_stay',
                'stay_id' => $stay->id,
            ],
        ]);

        if ($incident->is_notifiable) {
            NotifiableIncident::create([
                'incident_type' => $validated['incident_type'] ?? 'health_safety',
                'notification_authority' => $validated['notification_authority'] ?? 'health_nz',
                'title' => $validated['title'],
                'description' => $validated['description'],
                'related_incident_id' => $incident->id,
                'severity' => $validated['severity'],
                'status' => 'pending',
                'occurred_at' => $incident->occurred_at,
                'discovered_at' => now(),
                'notification_deadline' => now()->addDay(),
                'submitted_by' => auth()->id(),
                'evidence' => [
                    ['type' => 'respite_stay', 'id' => $stay->id],
                ],
            ]);
        }

        if (($validated['incident_type'] ?? null) === 'privacy_breach') {
            DataBreachLog::create([
                'breach_reference' => $this->nextBreachReference(),
                'breach_type' => 'respite_stay',
                'severity' => $validated['severity'],
                'discovered_at' => $incident->occurred_at ?? now(),
                'discovered_by_user_id' => auth()->id(),
                'nature_of_breach' => $validated['title']."\n\n".$validated['description'],
                'affected_data_categories' => ['health_information', 'respite_record'],
                'approximate_individuals_affected' => 1,
                'likely_consequences' => '',
                'measures_taken' => $validated['immediate_action_taken'] ?? '',
                'requires_authority_notification' => ($validated['notification_authority'] ?? null) === 'privacy_commissioner' || (bool) ($validated['is_notifiable'] ?? false),
                'requires_subject_notification' => false,
                'status' => 'discovered',
                'created_by' => auth()->id(),
            ]);
        }

        event(new RespiteEvent('respite.stay.incident_recorded', [
            'id' => $incident->id,
            'stay_id' => $stay->id,
            'client_id' => $stay->client_id,
        ]));

        return back()->with('success', 'Incident recorded.');
    }

    public function recordComplaint(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client');
        $this->authorize('view', $stay->client);

        $validated = $request->validate([
            'source' => 'required|in:client,whanau,staff,advocate,external,other',
            'received_at' => 'required|date',
            'nature' => 'required|string|max:255',
            'details' => 'nullable|string|max:5000',
            'acknowledged_at' => 'nullable|date',
            'resolution' => 'nullable|string|max:5000',
            'escalated_to_hdc' => 'nullable|in:no,offered,requested,submitted',
            'status' => 'nullable|in:open,acknowledged,resolved,escalated',
        ]);

        $complaint = RespiteComplaint::create([
            ...$validated,
            'stay_id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $validated['status'] ?? (isset($validated['resolution']) ? 'resolved' : 'open'),
            'created_by' => auth()->id(),
        ]);

        event(new RespiteEvent('respite.stay.complaint_recorded', [
            'id' => $complaint->id,
            'stay_id' => $stay->id,
            'client_id' => $stay->client_id,
        ]));

        return back()->with('success', 'Complaint recorded.');
    }

    private function postFundingConsumption(?RespiteStay $stay): void
    {
        $booking = $stay?->booking;
        $agreement = $booking?->serviceAgreement;

        if (! $booking || ! $agreement) {
            return;
        }

        $start = $stay->actual_start ?: $booking->start_at;
        $end = $stay->actual_end ?: now();

        if (! $start || ! $end) {
            return;
        }

        $nights = max(1, Carbon::parse($start)->startOfDay()->diffInDays(Carbon::parse($end)->startOfDay()));
        $hours = $nights * 24;
        $budget = 0.0;

        if ((float) $agreement->daily_rate > 0) {
            $budget = $nights * (float) $agreement->daily_rate;
        } elseif ((float) $agreement->hourly_rate > 0) {
            $budget = $hours * (float) $agreement->hourly_rate;
        }

        $updates = [
            'hours_used' => (float) ($agreement->hours_used ?? 0) + $hours,
            'budget_used' => (float) ($agreement->budget_used ?? 0) + $budget,
        ];

        if ($agreement->agreement_type === 'carer_support' || $agreement->funding_type === 'carer_support') {
            $updates['carer_support_days_used'] = (int) ($agreement->carer_support_days_used ?? 0) + $nights;
        }

        $agreement->forceFill($updates)->save();
    }

    private function nextBreachReference(): string
    {
        return app(\App\Services\References\ReferenceNumberGenerator::class)->next('BR');
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function guardAnaphylaxisAcknowledgement(RespiteStay $stay, array $validated): void
    {
        $allergies = MedicationAllergy::query()
            ->where('client_id', $stay->client_id)
            ->where('severity', 'life_threatening')
            ->get(['id', 'allergen', 'reaction', 'severity']);

        if ($allergies->isEmpty()) {
            return;
        }

        if (! (bool) ($validated['anaphylaxis_acknowledged'] ?? false)) {
            throw ValidationException::withMessages([
                'anaphylaxis_acknowledgement' => 'Acknowledge life-threatening allergy controls, EpiPen location and escalation plan before check-in.',
            ]);
        }

        if (blank($validated['epipen_location'] ?? null)) {
            throw ValidationException::withMessages([
                'epipen_location' => 'Record where the EpiPen or emergency medication is stored before check-in.',
            ]);
        }

        if (blank($validated['anaphylaxis_escalation_note'] ?? null)) {
            throw ValidationException::withMessages([
                'anaphylaxis_escalation_note' => 'Record the escalation plan for a life-threatening allergy before check-in.',
            ]);
        }

        $riskScreen = $stay->admission_risk_screen ?? [];
        $riskScreen['anaphylaxis_acknowledgement'] = [
            'acknowledged' => true,
            'epipen_location' => $validated['epipen_location'],
            'escalation_note' => $validated['anaphylaxis_escalation_note'],
            'allergies' => $allergies
                ->map(fn (MedicationAllergy $allergy) => [
                    'id' => $allergy->id,
                    'allergen' => $allergy->allergen,
                    'reaction' => $allergy->reaction,
                    'severity' => $allergy->severity,
                ])
                ->values()
                ->all(),
            'recorded_by' => auth()->id(),
            'recorded_at' => now()->toIso8601String(),
        ];

        $stay->forceFill([
            'admission_risk_screen' => $riskScreen,
            'updated_by' => auth()->id(),
        ])->save();
    }

    private function guardAdmissionMedicationReconciliation(RespiteStay $stay, ?string $overrideReason): void
    {
        if (! $this->clientHasActiveMedications($stay->client_id)) {
            return;
        }

        $hasCompletedAdmissionMedRec = $stay->medicationReconciliations()
            ->where('type', 'admission')
            ->where('status', 'completed')
            ->exists();

        if ($hasCompletedAdmissionMedRec) {
            $stay->booking?->update([
                'medications_reconciled' => true,
                'medications_reconciled_at' => now(),
                'medications_reconciled_by' => auth()->id(),
            ]);

            return;
        }

        if (blank($overrideReason)) {
            throw ValidationException::withMessages([
                'medication_reconciliation' => 'Complete admission medication reconciliation or record an override reason before check-in.',
            ]);
        }

        RespiteMedicationReconciliation::updateOrCreate(
            ['stay_id' => $stay->id, 'type' => 'admission'],
            [
                'status' => 'overridden',
                'override_reason' => $overrideReason,
                'reconciled_by_user_id' => auth()->id(),
                'reconciled_at' => now(),
                'updated_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]
        );
    }

    private function guardDischargeMedicationReconciliation(RespiteStay $stay, ?array $medRec): void
    {
        if (! $this->clientHasActiveMedications($stay->client_id)) {
            return;
        }

        if (! empty($medRec) && ! empty($medRec['medicines_returned_to']) && array_key_exists('count', $medRec)) {
            return;
        }

        throw ValidationException::withMessages([
            'discharge_medication_reconciliation' => 'Complete discharge medication reconciliation before transferring care back to whanau or another provider.',
        ]);
    }

    private function guardDischargeCompliance(RespiteStay $stay): void
    {
        $unreviewedRestraints = $stay->restraintEvents()
            ->whereNull('reviewed_at')
            ->exists();

        $openIncidents = $stay->incidents()
            ->whereNotIn('status', ['reviewed', 'closed'])
            ->exists();

        $incidentIds = $stay->incidents()->pluck('id');
        $pendingNotifiables = $incidentIds->isNotEmpty()
            && NotifiableIncident::whereIn('related_incident_id', $incidentIds)
                ->where('status', 'pending')
                ->exists();

        if ($unreviewedRestraints || $openIncidents || $pendingNotifiables) {
            throw ValidationException::withMessages([
                'compliance' => 'Resolve open incidents, unreviewed restraint, and pending notifiable-event notifications before discharge.',
            ]);
        }
    }

    private function clientHasActiveMedications(int $clientId): bool
    {
        return ClientMedication::query()
            ->where('client_id', $clientId)
            ->active()
            ->exists();
    }

    private function activeBehaviourSupportPlanId(RespiteStay $stay): ?int
    {
        return BehaviourSupportPlan::query()
            ->where('client_id', $stay->client_id)
            ->where('status', 'active')
            ->orderByRaw('review_date is null')
            ->orderBy('review_date')
            ->value('id');
    }
}
