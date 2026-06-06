<?php

namespace App\Http\Controllers\Respite;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAlert;
use App\Models\MedicationAllergy;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\RespiteTask;
use App\Models\RestraintEvent;
use App\Models\SafeguardingAlert;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Support\Respite\RespiteFundingSource;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The single Respite workspace — collapses the former Referrals / Booking
 * Requests / Approved Bookings / Calendar / Stays pages into one tabbed page.
 * Returns every pipeline list + derived counts + lookup data for the pop-ups
 * in one payload (records are normalised to a flat, frontend-friendly shape so
 * the panes never touch raw DB column names).
 */
class RespiteWorkspaceController extends Controller
{
    /** Soft cap on each list — respite volumes are small; panes filter client-side. */
    private const LIST_CAP = 200;

    /** @var array<int,array<int,array{type:string,label:string,detail:?string,severity:string,requiresAcknowledgement:bool}>> */
    private array $criticalAlertCache = [];

    public function index(): Response
    {
        $referrals = RespiteReferral::query()
            ->with(['client:id,first_name,last_name,date_of_birth,site_id', 'client.site:id,name'])
            ->orderByDesc('received_at')
            ->limit(self::LIST_CAP)
            ->get()
            ->map(fn (RespiteReferral $r) => $this->mapReferral($r))
            ->values();

        $requestModels = RespiteBookingRequest::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'serviceContext:id,name',
                'approvedBy:id,name',
                'serviceAgreement:id,client_id,title,reference_number,status,ends_at,signed_at,signed_date,review_due_date,total_budget,budget_used,total_hours,hours_used',
            ])
            ->orderByDesc('requested_start')
            ->limit(self::LIST_CAP)
            ->get();

        // Bookings already spawned from these requests (drives the onboard state).
        $bookingByRequest = RespiteBooking::query()
            ->whereIn('booking_request_id', $requestModels->pluck('id'))
            ->get(['id', 'booking_request_id', 'status'])
            ->keyBy('booking_request_id');

        $requests = $requestModels
            ->map(fn (RespiteBookingRequest $rq) => $this->mapRequest($rq, $bookingByRequest->get($rq->id)))
            ->values();

        $bookings = RespiteBooking::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'client.medications' => fn ($q) => $q->active()->select('id', 'client_id'),
                'coordinator:id,name',
                'location:id,name',
                'serviceAgreement:id,client_id,title,reference_number,status,ends_at,signed_at,signed_date,review_due_date,total_budget,budget_used,total_hours,hours_used',
            ])
            ->withCount('stays')
            ->orderByDesc('start_at')
            ->limit(self::LIST_CAP)
            ->get()
            ->map(fn (RespiteBooking $b) => $this->mapBooking($b))
            ->values();

        $stays = RespiteStay::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'client.medications' => fn ($q) => $q->active()->select('id', 'client_id'),
                'booking:id,end_at,location_id',
                'booking.location:id,name',
                'medicationReconciliations:id,stay_id,type,status',
            ])
            ->withCount([
                'restraintEvents as unreviewed_restraint_count' => fn ($q) => $q->whereNull('reviewed_at'),
                'incidents as open_incident_count' => fn ($q) => $q->whereNotIn('status', ['reviewed', 'closed']),
            ])
            ->orderByDesc('actual_start')
            ->orderByDesc('id')
            ->limit(self::LIST_CAP)
            ->get()
            ->map(fn (RespiteStay $s) => $this->mapStay($s))
            ->values();

        return Inertia::render('respite/index', [
            'referrals' => $referrals,
            'requests' => $requests,
            'bookings' => $bookings,
            'stays' => $stays,
            'homes' => $this->homes(),
            'tasks' => auth()->user()?->canDo('respite.tasks.view') ? $this->tasks() : [],
            'stats' => $this->stats(),
            // Lookup data for the create / onboard pop-ups.
            'clients' => Client::query()
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']),
            'serviceContexts' => ServiceContext::query()->orderBy('name')->get(['id', 'name']),
            'fundingSources' => RespiteFundingSource::options(),
        ]);
    }

    /** Respite-capable homes with their bed capacity (for occupancy + pickers). */
    private function homes()
    {
        $occupiedBySite = $this->currentOccupancyBySite();

        return Site::query()
            ->where('offers_respite', true)
            ->orderBy('name')
            ->get(['id', 'name', 'respite_capacity'])
            ->map(function (Site $s) use ($occupiedBySite) {
                $capacity = (int) ($s->respite_capacity ?? 0);
                $occupied = (int) ($occupiedBySite[$s->id] ?? 0);

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'capacity' => $capacity,
                    'occupied' => $occupied,
                    'available' => $capacity > 0 ? max(0, $capacity - $occupied) : null,
                    'full' => $capacity > 0 && $occupied >= $capacity,
                ];
            })
            ->values();
    }

    /** Respite tasks (operational work) for the Tasks tab. */
    private function tasks()
    {
        return RespiteTask::query()
            ->with('assignedTo:id,name')
            ->orderByDesc('overdue')
            ->orderByDesc('id')
            ->limit(self::LIST_CAP)
            ->get()
            ->map(fn (RespiteTask $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'type' => $t->task_type,
                'status' => $t->status,
                'priority' => $t->priority,
                'assignee' => $t->assignedTo?->name,
                'dueAt' => optional($t->due_at)->toIso8601String(),
                'overdue' => (bool) $t->overdue,
                'stopGate' => (bool) $t->is_stop_gate,
                'requiresApproval' => (bool) $t->requires_approval,
            ])
            ->values();
    }

    /** Headline counts for the hero + tab badges. */
    private function stats(): array
    {
        $inHouse = RespiteStay::whereIn('status', ['active', 'extended'])->count();
        $respiteIncidentIds = ClientIncident::query()
            ->whereNotNull('respite_stay_id')
            ->pluck('id');
        $pendingNotifiableCount = $respiteIncidentIds->isEmpty()
            ? 0
            : NotifiableIncident::whereIn('related_incident_id', $respiteIncidentIds)
                ->where('status', 'pending')
                ->count();
        $fundingAttention = RespiteBooking::query()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($query) {
                $query->whereNotIn('funding_status', ['approved', 'not_required'])
                    ->orWhereHas('serviceAgreement', function ($agreement) {
                        $agreement
                            ->whereColumn('ends_at', '<', 'respite_bookings.end_at')
                            ->orWhereColumn('review_due_date', '<', 'respite_bookings.end_at');
                    });
            })
            ->count();
        $fullHomes = $this->homes()->where('full', true)->count();

        return [
            'newReferrals' => RespiteReferral::where('status', 'received')->count(),
            'toTriage' => RespiteReferral::whereIn('status', ['received', 'triaged'])->count(),
            'crisisOpen' => RespiteReferral::where('urgency', 'crisis')
                ->whereNotIn('status', ['accepted', 'declined'])->count(),
            'carerCrisisAttention' => RespiteReferral::where('carer_breakdown_flag', true)
                ->whereNotIn('status', ['accepted', 'declined'])->count(),
            'awaitingReview' => RespiteBookingRequest::whereIn('status', ['submitted', 'under_review'])->count(),
            'waitlisted' => RespiteBookingRequest::where('status', 'waitlisted')->count(),
            'confirmedUpcoming' => RespiteBooking::where('status', 'confirmed')->count(),
            'inHouse' => $inHouse,
            'bedsTotal' => (int) Site::where('offers_respite', true)->sum('respite_capacity'),
            'bedsOccupied' => $inHouse,
            'fullHomes' => $fullHomes,
            'fundingAttention' => $fundingAttention,
            'complianceAttention' => RestraintEvent::whereNull('reviewed_at')->whereNotNull('stay_id')->count()
                + ClientIncident::whereNotNull('respite_stay_id')->whereNotIn('status', ['reviewed', 'closed'])->count()
                + $pendingNotifiableCount,
        ];
    }

    private function currentOccupancyBySite()
    {
        return RespiteStay::query()
            ->with(['client:id,site_id', 'booking:id,location_id'])
            ->whereIn('status', ['active', 'extended'])
            ->get()
            ->mapToGroups(fn (RespiteStay $stay) => [
                $stay->booking?->location_id ?: $stay->client?->site_id => $stay->id,
            ])
            ->filter(fn ($items, $siteId) => ! empty($siteId))
            ->map(fn ($items) => $items->count());
    }

    private function mapReferral(RespiteReferral $r): array
    {
        $client = $r->client;

        return [
            'id' => $r->id,
            'ref' => 'R-'.$r->id,
            'client' => $this->clientName($client),
            'clientId' => $r->client_id,
            'age' => $this->age($client?->date_of_birth),
            'referrer' => $r->referrer_name,
            'referrerType' => $r->referrer_type,
            'contact' => $r->referrer_contact,
            'urgency' => $r->urgency,
            'status' => $r->status,
            'received' => optional($r->received_at)->toIso8601String(),
            'reason' => $r->referral_reason,
            'riskLevel' => $r->risk_level,
            'funding' => RespiteFundingSource::label($r->funding_source),
            'site' => $client?->site?->name,
            'triageNotes' => $r->triage_notes,
            'isMaori' => (bool) $r->is_maori,
            'iwi' => $r->iwi,
            'hapu' => $r->hapu,
            'marae' => $r->marae,
            'interpreterRequired' => (bool) $r->interpreter_required,
            'interpreterLanguage' => $r->interpreter_language,
            'interpreterArranged' => (bool) $r->interpreter_arranged,
            'carerStrainLevel' => $r->carer_strain_level,
            'carerBreakdown' => (bool) $r->carer_breakdown_flag,
        ];
    }

    private function mapRequest(RespiteBookingRequest $rq, ?RespiteBooking $booking): array
    {
        $client = $rq->client;
        $confirmed = $booking !== null
            && in_array($booking->status, ['confirmed', 'in_progress', 'completed'], true);

        return [
            'id' => $rq->id,
            'ref' => 'BR-'.$rq->id,
            'client' => $this->clientName($client),
            'clientId' => $rq->client_id,
            'referralId' => $rq->referral_id,
            'referralRef' => $rq->referral_id ? 'R-'.$rq->referral_id : null,
            'status' => $rq->status,
            'start' => optional($rq->requested_start)->toIso8601String(),
            'end' => optional($rq->requested_end)->toIso8601String(),
            'nights' => $this->nights($rq->requested_start, $rq->requested_end),
            'funding' => $this->fundingLabel($rq->funding_source, $rq->funding_reference),
            'fundingSource' => $rq->funding_source,
            'fundingReference' => $rq->funding_reference,
            'fundingStatus' => $rq->funding_status ?: ($rq->funding_source ? 'pending_approval' : 'not_required'),
            'serviceAgreement' => $this->serviceAgreementSummary($rq->serviceAgreement),
            'priority' => $rq->priority,
            'waitlistPosition' => $rq->waitlist_position,
            'expectedAvailabilityDate' => optional($rq->expected_availability_date)->toDateString(),
            'isEmergency' => (bool) $rq->is_emergency,
            'fastTracked' => (bool) $rq->fast_tracked,
            'carer' => $rq->intake_snapshot['carer'] ?? null,
            'cultural' => $rq->intake_snapshot['cultural'] ?? null,
            'site' => $client?->site?->name,
            'serviceContext' => $rq->serviceContext?->name,
            'reviewer' => $rq->approvedBy?->name,
            'submitted' => optional($rq->created_at)->toIso8601String(),
            'note' => $rq->preference_notes,
            'hasBooking' => $booking !== null,
            'bookingId' => $booking?->id,
            // "Onboarded" simply means the spawned booking has been confirmed —
            // it then surfaces in Bookings, the Calendar and Stays.
            'onboarded' => $confirmed,
        ];
    }

    private function mapBooking(RespiteBooking $b): array
    {
        $client = $b->client;
        $readiness = $b->readiness();

        return [
            'id' => $b->id,
            'ref' => 'BK-'.$b->id,
            'client' => $this->clientName($client),
            'clientId' => $b->client_id,
            'requestId' => $b->booking_request_id,
            'status' => $b->status,
            'start' => optional($b->start_at)->toIso8601String(),
            'end' => optional($b->end_at)->toIso8601String(),
            'nights' => $this->nights($b->start_at, $b->end_at),
            'site' => $b->location?->name ?? $client?->site?->name,
            'coordinator' => $b->coordinator?->name,
            'funding' => $this->fundingLabel($b->funding_source, $b->funding_reference),
            'fundingSource' => $b->funding_source,
            'fundingReference' => $b->funding_reference,
            'fundingStatus' => $b->funding_status ?: ($b->funding_source ? 'pending_approval' : 'not_required'),
            'serviceAgreement' => $this->serviceAgreementSummary($b->serviceAgreement),
            'agreementStatus' => $b->agreement_status,
            'consentAuthority' => $b->consent_authority,
            'culturalSnapshot' => $b->cultural_snapshot,
            'interpreterArranged' => (bool) $b->interpreter_arranged,
            'copaymentAmount' => $b->copayment_amount !== null ? (float) $b->copayment_amount : null,
            'copaymentStatus' => $b->copayment_status,
            'recurrenceRule' => $b->recurrence_rule,
            'criticalAlerts' => $this->criticalAlerts($client),
            'readiness' => $readiness['score'],
            'readinessSegments' => $readiness['segments'],
            'hasStay' => $b->stays_count > 0,
        ];
    }

    private function mapStay(RespiteStay $s): array
    {
        $client = $s->client;

        return [
            'id' => $s->id,
            'ref' => 'ST-'.$s->id,
            'client' => $this->clientName($client),
            'clientId' => $s->client_id,
            'bookingId' => $s->booking_id,
            'status' => $s->status,
            'live' => in_array($s->status, ['active', 'extended'], true),
            'site' => $s->booking?->location?->name ?? $client?->site?->name,
            'actualStart' => optional($s->actual_start)->toIso8601String(),
            'actualEnd' => optional($s->actual_end)->toIso8601String(),
            'plannedEnd' => optional($s->booking?->end_at)->toIso8601String(),
            'dischargeReason' => $s->discharge_reason,
            'unreviewedRestraints' => (int) ($s->unreviewed_restraint_count ?? 0),
            'openIncidents' => (int) ($s->open_incident_count ?? 0),
            'criticalAlerts' => $this->criticalAlerts($client),
            'requiresAdmissionMedRec' => $this->hasLoadedActiveMedications($client),
            'admissionMedRecStatus' => $s->medicationReconciliations
                ->firstWhere('type', 'admission')
                ?->status,
        ];
    }

    private function clientName(?Client $client): string
    {
        if (! $client) {
            return 'Unknown client';
        }

        return trim(($client->first_name ?? '').' '.($client->last_name ?? '')) ?: 'Unnamed client';
    }

    private function age($dob): ?int
    {
        return $dob ? Carbon::parse($dob)->age : null;
    }

    private function nights($start, $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }

        return Carbon::parse($start)->startOfDay()->diffInDays(Carbon::parse($end)->startOfDay());
    }

    private function fundingLabel(?string $source, ?string $reference): ?string
    {
        $label = RespiteFundingSource::label($source);

        if ($label && $reference) {
            return $label.' · '.$reference;
        }

        return $label ?: $reference;
    }

    private function serviceAgreementSummary(?ServiceAgreement $agreement): ?array
    {
        if (! $agreement) {
            return null;
        }

        return [
            'id' => $agreement->id,
            'title' => $agreement->title,
            'referenceNumber' => $agreement->reference_number,
            'status' => $agreement->status,
            'endsAt' => optional($agreement->ends_at)->toDateString(),
            'signedAt' => optional($agreement->signed_at)->toIso8601String(),
            'signedDate' => optional($agreement->signed_date)->toDateString(),
            'reviewDueDate' => optional($agreement->review_due_date)->toDateString(),
            'budgetRemaining' => (float) $agreement->budget_remaining,
            'hoursRemaining' => (float) $agreement->hours_remaining,
        ];
    }

    private function hasLoadedActiveMedications(?Client $client): bool
    {
        return $client?->relationLoaded('medications') && $client->medications->isNotEmpty();
    }

    private function criticalAlerts(?Client $client): array
    {
        if (! $client) {
            return [];
        }

        if (isset($this->criticalAlertCache[$client->id])) {
            return $this->criticalAlertCache[$client->id];
        }

        $allergies = MedicationAllergy::query()
            ->where('client_id', $client->id)
            ->severe()
            ->get(['id', 'allergen', 'reaction', 'severity'])
            ->map(fn (MedicationAllergy $allergy) => [
                'type' => 'allergy',
                'label' => $allergy->allergen,
                'detail' => $allergy->reaction,
                'severity' => $allergy->severity === 'life_threatening' ? 'critical' : 'high',
                'requiresAcknowledgement' => $allergy->severity === 'life_threatening',
            ]);

        $medicationAlerts = ClientMedicationAlert::query()
            ->where('client_id', $client->id)
            ->enabled()
            ->unresolved()
            ->get(['id', 'title', 'detail', 'prompt_on_open'])
            ->map(fn (ClientMedicationAlert $alert) => [
                'type' => 'medication_alert',
                'label' => $alert->title,
                'detail' => $alert->detail,
                'severity' => $alert->prompt_on_open ? 'high' : 'medium',
                'requiresAcknowledgement' => (bool) $alert->prompt_on_open,
            ]);

        $safeguardingAlerts = SafeguardingAlert::query()
            ->where('alertable_type', Client::class)
            ->where('alertable_id', $client->id)
            ->active()
            ->get(['id', 'alert_summary', 'alert_details', 'severity', 'active', 'expires_at'])
            ->map(fn (SafeguardingAlert $alert) => [
                'type' => 'safeguarding',
                'label' => $alert->alert_summary,
                'detail' => $alert->alert_details,
                'severity' => $alert->severity,
                'requiresAcknowledgement' => in_array($alert->severity, ['high', 'critical'], true),
            ]);

        return $this->criticalAlertCache[$client->id] = $allergies
            ->concat($medicationAlerts)
            ->concat($safeguardingAlerts)
            ->values()
            ->all();
    }
}
