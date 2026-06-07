<?php

namespace App\Http\Controllers\Respite;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAlert;
use App\Models\MedicationAllergy;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteComplaint;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\RespiteTask;
use App\Models\RestraintEvent;
use App\Models\SafeguardingAlert;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
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
            ->with([
                'client' => fn ($query) => $query
                    ->select($this->clientProfileColumns())
                    ->with(['site:id,name', 'medicalProfile', 'conditions', 'emergencyContacts'])
                    ->withCount('emergencyContacts'),
            ])
            ->withCount('bookingRequests')
            ->orderByDesc('received_at')
            ->limit(self::LIST_CAP)
            ->get()
            ->map(fn (RespiteReferral $r) => $this->mapReferral($r))
            ->values();

        $requestModels = RespiteBookingRequest::query()
            ->with([
                'client' => fn ($query) => $query
                    ->select($this->clientProfileColumns())
                    ->with(['site:id,name', 'medicalProfile', 'conditions', 'emergencyContacts'])
                    ->withCount('emergencyContacts'),
                'serviceContext:id,name',
                'approvedBy:id,name',
                'serviceAgreement:id,client_id,title,reference_number,status,ends_at,signed_at,signed_date,review_due_date,total_budget,budget_used,total_hours,hours_used,carer_support_days_allocated,carer_support_days_used,carer_support_entitlement_year',
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
                'serviceAgreement:id,client_id,title,reference_number,status,ends_at,signed_at,signed_date,review_due_date,total_budget,budget_used,total_hours,hours_used,carer_support_days_allocated,carer_support_days_used,carer_support_entitlement_year',
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
                'complaints as open_complaint_count' => fn ($q) => $q->whereNotIn('status', ['resolved']),
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
                ->with('site:id,name')
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'nhi_number', 'site_id'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                    'date_of_birth' => $client->date_of_birth?->toDateString(),
                    'nhi_number' => $client->nhi_number,
                    'site' => $client->site?->name,
                ])
                ->values(),
            'serviceContexts' => ServiceContext::query()->orderBy('name')->get(['id', 'name']),
            'serviceAgreements' => ServiceAgreement::query()
                ->where('status', 'active')
                ->orderBy('title')
                ->get(['id', 'client_id', 'title', 'reference_number', 'status', 'ends_at', 'signed_at', 'signed_date', 'review_due_date', 'total_budget', 'budget_used', 'total_hours', 'hours_used', 'carer_support_days_allocated', 'carer_support_days_used', 'carer_support_entitlement_year'])
                ->map(fn (ServiceAgreement $agreement) => [
                    'clientId' => $agreement->client_id,
                    ...$this->serviceAgreementSummary($agreement),
                ])
                ->values(),
            'fundingSources' => RespiteFundingSource::options(),
            'clientProfileOptions' => $this->clientProfileOptions(),
        ]);
    }

    private function clientProfileOptions(): array
    {
        return [
            'sites' => Site::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'serviceContexts' => ServiceContext::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'type', 'name']),
            'keyWorkers' => User::staff()
                ->orderBy('name')
                ->get(['id', 'name']),
            'geofences' => AssetGeofence::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'defaultServiceContextId' => ServiceContext::defaultId(),
        ];
    }

    private function clientProfileColumns(): array
    {
        return [
            'id',
            'site_id',
            'service_context_id',
            'nhi_number',
            'first_name',
            'last_name',
            'preferred_name',
            'date_of_birth',
            'gender',
            'preferred_pronouns',
            'status',
            'phone',
            'email',
            'address_line_1',
            'address_line_2',
            'suburb',
            'city',
            'postcode',
            'funding_type',
            'funding_notes',
            'ethnicity',
            'iwi',
            'hapu',
            'marae',
            'languages',
            'religion',
            'mobility_needs',
            'sensory_needs',
            'cognitive_needs',
            'dietary_requirements',
            'cultural_dietary_needs',
            'sleep_preferences',
            'transport_needs',
            'transport_notes',
            'fluid_intake_min_ml',
            'fluid_intake_max_ml',
            'seizure_duration_escalation_seconds',
            'interests_hobbies',
            'strengths_abilities',
            'life_story',
            'education_level',
            'employment_status',
            'service_start_date',
            'key_worker_id',
            'risk_level',
            'safeguarding_flag',
            'house_geofence_id',
        ];
    }

    private function clientProfileComplete(?Client $client): bool
    {
        if (! $client) {
            return false;
        }

        return filled($client->first_name)
            && filled($client->last_name)
            && filled($client->date_of_birth)
            && filled($client->site_id)
            && filled($client->service_context_id)
            && (int) ($client->emergency_contacts_count ?? $client->emergencyContacts()->count()) > 0;
    }

    private function clientProfilePrefill(?Client $client, array $context = []): ?array
    {
        if (! $client) {
            return null;
        }

        $cultural = $context['cultural'] ?? [];
        $carer = $context['carer'] ?? [];
        $languages = $this->stringList($client->languages);
        $interpreterLanguage = data_get($cultural, 'interpreter_language');
        if ($languages === [] && filled($interpreterLanguage)) {
            $languages = [(string) $interpreterLanguage];
        }

        $emergencyContacts = $client->emergencyContacts
            ->sortBy('contact_order')
            ->map(fn ($contact) => [
                'name' => $contact->name ?? '',
                'relationship' => $contact->relationship ?? '',
                'phone' => $contact->phone ?? '',
                'alternate_phone' => $contact->alternate_phone ?? '',
                'email' => $contact->email ?? '',
                'address' => $contact->address ?? '',
                'preferred_method' => $contact->preferred_method ?: 'phone',
                'availability' => $contact->availability ?? '',
                'notes' => $contact->notes ?? '',
                'can_view_medical' => (bool) $contact->can_view_medical,
                'can_view_medications' => (bool) $contact->can_view_medications,
                'can_view_incidents' => (bool) $contact->can_view_incidents,
                'can_receive_updates' => (bool) $contact->can_receive_updates,
            ])
            ->values()
            ->all();

        if ($emergencyContacts === [] && filled(data_get($carer, 'primary_carer_name'))) {
            $emergencyContacts[] = [
                'name' => (string) data_get($carer, 'primary_carer_name'),
                'relationship' => (string) (data_get($carer, 'primary_carer_relationship') ?: 'Primary carer'),
                'phone' => (string) (data_get($carer, 'primary_carer_contact') ?: ''),
                'alternate_phone' => '',
                'email' => '',
                'address' => '',
                'preferred_method' => 'phone',
                'availability' => '',
                'notes' => 'Primary carer from respite referral.',
                'can_view_medical' => false,
                'can_view_medications' => false,
                'can_view_incidents' => false,
                'can_receive_updates' => true,
            ];
        }

        $medicalProfile = $client->medicalProfile;

        return [
            '_modal' => true,
            'site_id' => $this->stringValue($client->site_id),
            'service_context_id' => $this->stringValue($client->service_context_id),
            'status' => $client->status ?: 'active',
            'first_name' => $client->first_name ?? '',
            'last_name' => $client->last_name ?? '',
            'preferred_name' => $client->preferred_name ?? '',
            'date_of_birth' => $client->date_of_birth?->toDateString() ?? '',
            'gender' => $client->gender ?? '',
            'preferred_pronouns' => $client->preferred_pronouns ?? '',
            'nhi_number' => $client->nhi_number ?? '',
            'phone' => $client->phone ?? '',
            'email' => $client->email ?? '',
            'address_line_1' => $client->address_line_1 ?? '',
            'address_line_2' => $client->address_line_2 ?? '',
            'suburb' => $client->suburb ?? '',
            'city' => $client->city ?? '',
            'postcode' => $client->postcode ?? '',
            'create_client_portal_user' => false,
            'ethnicity' => $client->ethnicity
                ?: (string) (data_get($cultural, 'ethnicity') ?: ((bool) data_get($cultural, 'is_maori') ? 'Maori' : '')),
            'languages' => $languages,
            'religion' => $client->religion ?? '',
            'mobility_needs' => $client->mobility_needs ?? '',
            'sensory_needs' => $client->sensory_needs ?? '',
            'cognitive_needs' => $client->cognitive_needs ?? '',
            'dietary_requirements' => $client->dietary_requirements
                ?: $client->cultural_dietary_needs
                ?: (string) (data_get($cultural, 'cultural_dietary_needs') ?: ''),
            'sleep_preferences' => $client->sleep_preferences ?? '',
            'transport_needs' => $this->stringList($client->transport_needs),
            'transport_notes' => $client->transport_notes ?? '',
            'fluid_intake_min_ml' => $this->stringValue($client->fluid_intake_min_ml),
            'fluid_intake_max_ml' => $this->stringValue($client->fluid_intake_max_ml),
            'seizure_duration_escalation_seconds' => $this->stringValue($client->seizure_duration_escalation_seconds),
            'interests_hobbies' => $client->interests_hobbies ?? '',
            'strengths_abilities' => $client->strengths_abilities ?? '',
            'life_story' => $client->life_story ?? '',
            'education_level' => $client->education_level ?? '',
            'employment_status' => $client->employment_status ?? '',
            'medical' => [
                'gp_name' => $medicalProfile?->gp_name ?? '',
                'gp_practice' => $medicalProfile?->gp_practice ?? '',
                'gp_phone' => $medicalProfile?->gp_phone ?? '',
                'hospital_preference' => $medicalProfile?->hospital_preference ?? '',
                'blood_type' => $medicalProfile?->blood_type ?? '',
                'organ_donor' => (bool) ($medicalProfile?->organ_donor ?? false),
                'allergies' => $this->stringList($medicalProfile?->allergies),
                'disabilities' => $this->stringList($medicalProfile?->disabilities),
                'medical_history' => $medicalProfile?->medical_history ?? '',
                'mental_health_history' => $medicalProfile?->mental_health_history ?? '',
                'surgical_history' => $medicalProfile?->surgical_history ?? '',
                'immunisation_notes' => $medicalProfile?->immunisation_notes ?? '',
                'notes' => $medicalProfile?->notes ?? '',
            ],
            'conditions' => $client->conditions
                ->map(fn ($condition) => [
                    'label' => $condition->label ?? '',
                    'severity' => $condition->severity ?: 'Mild',
                    'notes' => $condition->notes ?? '',
                ])
                ->values()
                ->all(),
            'service_start_date' => $client->service_start_date?->toDateString() ?? '',
            'key_worker_id' => $this->stringValue($client->key_worker_id),
            'risk_level' => $client->risk_level ?: 'low',
            'safeguarding_flag' => (bool) $client->safeguarding_flag,
            'house_geofence_id' => $this->stringValue($client->house_geofence_id),
            'funding_type' => $client->funding_type ?? '',
            'funding_notes' => $client->funding_notes ?? '',
            'emergency_contacts' => $emergencyContacts,
        ];
    }

    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item) => is_scalar($item) ? (string) $item : null, $value),
            fn ($item) => filled($item),
        ));
    }

    private function stringValue(mixed $value): string
    {
        return filled($value) ? (string) $value : '';
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
        $notifiablePastDeadline = $respiteIncidentIds->isEmpty()
            ? 0
            : NotifiableIncident::whereIn('related_incident_id', $respiteIncidentIds)
                ->where('status', 'pending')
                ->where('notification_deadline', '<', now())
                ->count();
        $notifiableDueSoon = $respiteIncidentIds->isEmpty()
            ? 0
            : NotifiableIncident::whereIn('related_incident_id', $respiteIncidentIds)
                ->where('status', 'pending')
                ->whereBetween('notification_deadline', [now(), now()->addDay()])
                ->count();
        $restraintsAwaitingReview = RestraintEvent::whereNull('reviewed_at')->whereNotNull('stay_id')->count();
        $bspAwaitingLink = RestraintEvent::whereNotNull('stay_id')
            ->where('within_support_plan', true)
            ->whereNull('behaviour_support_plan_id')
            ->count();
        $missingConsentRights = RespiteBooking::query()
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($query) {
                $query->whereNull('consent_authority')
                    ->orWhereNull('code_of_rights_provided')
                    ->orWhere('code_of_rights_provided', '!=', true)
                    ->orWhereNull('consent_to_respite')
                    ->orWhere('consent_to_respite', '!=', true)
                    ->orWhereNull('advocate_offered')
                    ->orWhereNull('consent_capacity_basis')
                    ->orWhereNull('rights_format_provided')
                    ->orWhereNull('rights_recorded_at');
            })
            ->count();
        $openComplaints = RespiteComplaint::whereNotIn('status', ['resolved'])->count();
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
            'complianceAttention' => $restraintsAwaitingReview
                + ClientIncident::whereNotNull('respite_stay_id')->whereNotIn('status', ['reviewed', 'closed'])->count()
                + $pendingNotifiableCount
                + $bspAwaitingLink
                + $missingConsentRights
                + $openComplaints,
            'compliance' => [
                'notifiablePastDeadline' => $notifiablePastDeadline,
                'notifiableDueSoon' => $notifiableDueSoon,
                'restraintsAwaitingReview' => $restraintsAwaitingReview,
                'bspAwaitingLink' => $bspAwaitingLink,
                'missingConsentRights' => $missingConsentRights,
                'openComplaints' => $openComplaints,
            ],
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
            'fundingSource' => $r->funding_source,
            'fundingReference' => $r->funding_reference,
            'site' => $client?->site?->name,
            'triageNotes' => $r->triage_notes,
            // Drives the "Create booking request" action — hidden once the
            // referral already has a request (column link or any related row).
            'hasRequest' => ($r->booking_requests_count ?? 0) > 0
                || $r->linked_booking_request_id !== null,
            'linkedRequestId' => $r->linked_booking_request_id,
            'isMaori' => (bool) $r->is_maori,
            'iwi' => $r->iwi,
            'hapu' => $r->hapu,
            'marae' => $r->marae,
            'interpreterRequired' => (bool) $r->interpreter_required,
            'interpreterLanguage' => $r->interpreter_language,
            'interpreterArranged' => (bool) $r->interpreter_arranged,
            'carerStrainLevel' => $r->carer_strain_level,
            'carerBreakdown' => (bool) $r->carer_breakdown_flag,
            'clientProfileComplete' => $this->clientProfileComplete($client),
            'clientProfilePrefill' => $this->clientProfilePrefill($client, [
                'cultural' => [
                    'is_maori' => (bool) $r->is_maori,
                    'ethnicity' => $r->ethnicity,
                    'iwi' => $r->iwi,
                    'hapu' => $r->hapu,
                    'marae' => $r->marae,
                    'interpreter_language' => $r->interpreter_language,
                    'cultural_dietary_needs' => $r->cultural_dietary_needs,
                ],
                'carer' => [
                    'primary_carer_name' => $r->primary_carer_name,
                    'primary_carer_relationship' => $r->primary_carer_relationship,
                    'primary_carer_contact' => $r->primary_carer_contact,
                ],
            ]),
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
            'seriesId' => $rq->series_id,
            'recurrenceRule' => $rq->recurrence_rule,
            'allocatedDays' => $rq->allocated_days,
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
            'clientProfileComplete' => $this->clientProfileComplete($client),
            'clientProfilePrefill' => $this->clientProfilePrefill($client, [
                'cultural' => $rq->intake_snapshot['cultural'] ?? [],
                'carer' => $rq->intake_snapshot['carer'] ?? [],
            ]),
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
            'consentAuthorityName' => $b->consent_authority_name,
            'consentAuthorityContact' => $b->consent_authority_contact,
            'codeOfRightsProvided' => (bool) $b->code_of_rights_provided,
            'consentToRespite' => (bool) $b->consent_to_respite,
            'consentCapacityBasis' => $b->consent_capacity_basis,
            'advocateOffered' => $b->advocate_offered,
            'rightsFormatProvided' => $b->rights_format_provided,
            'rightsRecordedAt' => optional($b->rights_recorded_at)->toIso8601String(),
            'culturalSnapshot' => $b->cultural_snapshot,
            'culturalPlacementCheck' => $b->cultural_placement_check,
            'settingRestriction' => $b->setting_restriction,
            'interpreterArranged' => (bool) $b->interpreter_arranged,
            'copaymentAmount' => $b->copayment_amount !== null ? (float) $b->copayment_amount : null,
            'copaymentBasis' => $b->copayment_basis,
            'privatePayPortion' => $b->private_pay_portion !== null ? (float) $b->private_pay_portion : null,
            'copaymentStatus' => $b->copayment_status,
            'recurrenceRule' => $b->recurrence_rule,
            'seriesId' => $b->series_id,
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
            'bedHoldStatus' => $s->bed_hold_status,
            'bedHoldReason' => $s->bed_hold_reason,
            'bedHoldUntil' => optional($s->bed_hold_until)->toIso8601String(),
            'unreviewedRestraints' => (int) ($s->unreviewed_restraint_count ?? 0),
            'openIncidents' => (int) ($s->open_incident_count ?? 0),
            'openComplaints' => (int) ($s->open_complaint_count ?? 0),
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
            'carerSupportDaysAllocated' => $agreement->carer_support_days_allocated,
            'carerSupportDaysUsed' => $agreement->carer_support_days_used,
            'carerSupportDaysRemaining' => $agreement->carer_support_days_remaining,
            'carerSupportEntitlementYear' => $agreement->carer_support_entitlement_year,
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
