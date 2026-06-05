<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteCommunicationLog;
use App\Models\RespiteDailyNote;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteHandoverNote;
use App\Models\RespiteReferral;
use App\Models\RespiteRiskPlanActivation;
use App\Models\RespiteStay;
use App\Models\RespiteTask;
use App\Models\ServiceContext;
use App\Models\Site;
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
                'coordinator:id,name',
                'location:id,name',
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
                'booking:id,end_at,location_id',
                'booking.location:id,name',
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
            'records' => $this->records(),
            'stats' => $this->stats(),
            // Lookup data for the create / onboard pop-ups.
            'clients' => Client::query()
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']),
            'serviceContexts' => ServiceContext::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Respite-capable homes with their bed capacity (for occupancy + pickers). */
    private function homes()
    {
        return Site::query()
            ->where('offers_respite', true)
            ->orderBy('name')
            ->get(['id', 'name', 'respite_capacity'])
            ->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'capacity' => (int) ($s->respite_capacity ?? 0),
            ])
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

    /**
     * Unified stay-scoped records for the Records tab — daily notes, handovers,
     * comms logs, evidence packs and risk-plan activations, each included only
     * if the user can view that type, then merged and sorted most-recent-first.
     */
    private function records()
    {
        $user = auth()->user();
        $records = collect();

        if ($user?->canDo('respite.daily-notes.view')) {
            $records = $records->concat(
                RespiteDailyNote::query()
                    ->with('client:id,first_name,last_name')
                    ->orderByDesc('note_date')->limit(60)->get()
                    ->map(fn (RespiteDailyNote $n) => [
                        'id' => $n->id,
                        'type' => 'daily_note',
                        'typeLabel' => 'Daily note',
                        'title' => 'Daily note · '.$this->humanise($n->shift_period),
                        'subtitle' => $this->snippet($n->observations ?: $n->concerns),
                        'date' => optional($n->note_date)->toIso8601String(),
                        'status' => $n->incident_occurred ? 'incident' : null,
                        'client' => $this->clientNameFrom($n->client),
                        'stayId' => $n->stay_id,
                    ]),
            );
        }

        if ($user?->canDo('respite.handovers.view')) {
            $records = $records->concat(
                RespiteHandoverNote::query()
                    ->with(['stay:id,client_id', 'stay.client:id,first_name,last_name'])
                    ->latest()->limit(60)->get()
                    ->map(fn (RespiteHandoverNote $h) => [
                        'id' => $h->id,
                        'type' => 'handover',
                        'typeLabel' => 'Handover',
                        'title' => 'Handover · '.$this->humanise($h->handover_type),
                        'subtitle' => $this->snippet($h->notes),
                        'date' => optional($h->created_at)->toIso8601String(),
                        'status' => $h->acknowledged_at ? 'acknowledged' : 'pending',
                        'client' => $this->clientNameFrom($h->stay?->client),
                        'stayId' => $h->stay_id,
                    ]),
            );
        }

        if ($user?->canDo('respite.communications.view')) {
            $records = $records->concat(
                RespiteCommunicationLog::query()
                    ->with(['stay:id,client_id', 'stay.client:id,first_name,last_name'])
                    ->latest('occurred_at')->limit(60)->get()
                    ->map(fn (RespiteCommunicationLog $c) => [
                        'id' => $c->id,
                        'type' => 'comms',
                        'typeLabel' => 'Comms',
                        'title' => $this->humanise($c->channel).' contact',
                        'subtitle' => $this->snippet($c->summary),
                        'date' => optional($c->occurred_at)->toIso8601String(),
                        'status' => null,
                        'client' => $this->clientNameFrom($c->stay?->client),
                        'stayId' => $c->stay_id,
                    ]),
            );
        }

        if ($user?->canDo('respite.evidence.view')) {
            $records = $records->concat(
                RespiteEvidencePack::query()
                    ->with(['stay:id,client_id', 'stay.client:id,first_name,last_name'])
                    ->latest()->limit(60)->get()
                    ->map(fn (RespiteEvidencePack $e) => [
                        'id' => $e->id,
                        'type' => 'evidence',
                        'typeLabel' => 'Evidence',
                        'title' => 'Evidence pack',
                        'subtitle' => $this->snippet($e->summary),
                        'date' => optional($e->created_at)->toIso8601String(),
                        'status' => $e->status,
                        'client' => $this->clientNameFrom($e->stay?->client),
                        'stayId' => $e->stay_id,
                    ]),
            );
        }

        if ($user?->canDo('respite.risk-plans.view')) {
            $records = $records->concat(
                RespiteRiskPlanActivation::query()
                    ->with('client:id,first_name,last_name')
                    ->latest()->limit(60)->get()
                    ->map(fn (RespiteRiskPlanActivation $r) => [
                        'id' => $r->id,
                        'type' => 'risk_plan',
                        'typeLabel' => 'Risk plan',
                        'title' => $r->plan_name ?: $this->humanise($r->plan_type).' plan',
                        'subtitle' => $this->humanise($r->plan_type),
                        'date' => optional($r->created_at)->toIso8601String(),
                        'status' => $r->status,
                        'client' => $this->clientNameFrom($r->client),
                        'stayId' => $r->stay_id,
                    ]),
            );
        }

        return $records->sortByDesc('date')->take(self::LIST_CAP)->values();
    }

    private function clientNameFrom(?Client $client): ?string
    {
        if (! $client) {
            return null;
        }

        return trim(($client->first_name ?? '').' '.($client->last_name ?? '')) ?: null;
    }

    private function snippet(?string $text): ?string
    {
        if (! $text) {
            return null;
        }

        return mb_strlen($text) > 90 ? mb_substr($text, 0, 90).'…' : $text;
    }

    private function humanise(?string $value): string
    {
        return ucfirst(str_replace('_', ' ', (string) $value));
    }

    /** Headline counts for the hero + tab badges. */
    private function stats(): array
    {
        $inHouse = RespiteStay::whereIn('status', ['active', 'extended'])->count();

        return [
            'newReferrals' => RespiteReferral::where('status', 'received')->count(),
            'toTriage' => RespiteReferral::whereIn('status', ['received', 'triaged'])->count(),
            'crisisOpen' => RespiteReferral::where('urgency', 'crisis')
                ->whereNotIn('status', ['accepted', 'declined'])->count(),
            'awaitingReview' => RespiteBookingRequest::whereIn('status', ['submitted', 'under_review'])->count(),
            'confirmedUpcoming' => RespiteBooking::where('status', 'confirmed')->count(),
            'inHouse' => $inHouse,
            'bedsTotal' => (int) Site::where('offers_respite', true)->sum('respite_capacity'),
            'bedsOccupied' => $inHouse,
        ];
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
            'site' => $client?->site?->name,
            'triageNotes' => $r->triage_notes,
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
            'funding' => $rq->funding_reference,
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
            'readiness' => $this->readiness($b),
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
        ];
    }

    /**
     * A coarse pre-stay readiness % from the booking's existing readiness flags.
     * (Richer per-check breakdown is part of the clinical NZ follow-up.)
     */
    private function readiness(RespiteBooking $b): int
    {
        $checks = [
            (bool) $b->medications_reconciled,
            ! empty($b->eligibility_checks),
            ! empty($b->consent_records),
            ! empty($b->pre_arrival_checklist),
        ];

        return (int) round(count(array_filter($checks)) / count($checks) * 100);
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
}
