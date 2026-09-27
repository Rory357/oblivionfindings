<?php

namespace App\Services\Fleet;

use App\Models\ClientTransportBooking;
use App\Models\FleetKeyLog;
use App\Models\FleetResidentTransport;
use App\Models\FleetShiftHandover;
use App\Models\SiteRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class TransportWorkspacePresenter
{
    public function __construct(
        private readonly TransportRequestService $requests,
        private readonly VehicleBookingAccessService $access,
        private readonly ResidentTransportJourneyScope $journeys,
        private readonly VehicleCalendarService $calendar,
    ) {}

    public function records(User $actor, array $filters): array
    {
        $from = CarbonImmutable::parse($filters['from'], 'Pacific/Auckland')->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['to'], 'Pacific/Auckland')->addDay()->startOfDay()->utc();
        $query = $this->requests->query($actor)->with(['client.site', 'creator:id,name', 'escort:id,name', 'fleetBooking.asset', 'fleetBooking.driver:id,name', 'journey', 'events.actor:id,name'])
            ->where(function ($period) use ($from, $to, $filters) {
                $period->where(fn ($unallocated) => $unallocated->whereNull('fleet_booking_id')->where('scheduled_at', '>=', $from)->where('scheduled_at', '<', $to))
                    ->orWhereHas('fleetBooking', fn ($booking) => $booking->where('starts_at', '<', $to)
                        ->when($filters['calendar'] ?? false, fn ($q) => $q->where('ends_at', '>', $from), fn ($q) => $q->where('starts_at', '>=', $from)));
            })
            ->when(($filters['site'] ?? 'all') !== 'all', fn ($q) => $q->whereHas('client', fn ($c) => $c->where('site_id', (int) $filters['site'])))
            ->orderBy('scheduled_at')->orderBy('id');
        // Never present a partial graph as the total; ask for a narrower range.
        abort_if((clone $query)->count() > 1000, 422, 'Choose a smaller date range to show at most 1,000 transport requests.');
        $records = $query->get();
        $bookingIds = $records->pluck('fleet_booking_id')->filter();
        $related = [
            'keys' => FleetKeyLog::whereIn('booking_id', $bookingIds)->orderByDesc('id')->get()->unique('booking_id')->keyBy('booking_id'),
            'handovers' => FleetShiftHandover::whereIn('booking_id', $bookingIds)->with('incomingUser:id,name')->get()->groupBy('booking_id'),
            'journeys' => $this->journeys->applyTransportScope(FleetResidentTransport::query()->whereIn('transport_request_id', $records->pluck('id')), $actor)->pluck('id')->all(),
        ];
        $rows = $records->map(fn ($row) => $this->row($actor, $row, false, $related))->all();
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn ($row) => str_contains(mb_strtolower(implode(' ', [
                $row['reference'], $row['person'], $row['site']['name'], $row['purpose'], $row['pickup'], $row['destination'],
                $row['booking']['reference'] ?? '', $row['booking']['vehicle']['name'] ?? '', $row['booking']['driver']['name'] ?? '',
                $row['next_owner'], $row['stage_label'], $row['journey'] ? 'J-'.$row['journey']['id'] : '',
            ])), $search)));
        }

        return $rows;
    }

    public function row(User $actor, ClientTransportBooking $row, bool $full = false, ?array $related = null): array
    {
        $row->loadMissing(['client.site', 'creator:id,name', 'escort:id,name', 'fleetBooking.asset', 'fleetBooking.driver:id,name', 'journey', 'events.actor:id,name']);
        $booking = $row->fleetBooking;
        $journey = $row->journey;
        $journeyRestricted = false;
        if ($journey && ! ($related !== null ? in_array($journey->id, $related['journeys'], true) : $this->journeys->applyTransportScope($journey->newQuery()->whereKey($journey->id), $actor)->exists())) {
            $journey = null;
            $journeyRestricted = true;
        }
        $manage = $actor->canDo('fleet.manage');
        $owner = (int) $row->created_by === (int) $actor->id && Gate::forUser($actor)->allows('update', $row->client);
        $driver = $booking && $booking->driverUserId() === (int) $actor->id;
        $stage = match (true) {
            $row->status === 'cancelled' => 'cancelled',
            $journeyRestricted => 'allocated',
            $journey?->status === 'completed' => 'completed',
            ! $journey && $row->status === 'completed' => 'completed',
            (bool) $journey?->arrived_at => 'returned',
            (bool) $journey => 'travelling',
            $booking?->status === 'checked_out' => 'depart',
            $booking?->status === 'approved' => 'ready',
            $booking?->status === 'pending' => 'decision',
            in_array($booking?->status, ['cancelled', 'rejected'], true) => 'plan_review',
            default => $row->workflow_state,
        };
        $labels = ['assessment' => 'Needs assessment', 'information' => 'More information needed', 'allocation' => 'Ready to plan',
            'allocated' => 'Plan recorded', 'decision' => 'Booking decision due', 'ready' => 'Prepare for departure',
            'depart' => 'Ready to depart', 'travelling' => 'In progress', 'returned' => 'Returned · completion due',
            'completed' => 'Journey completed', 'cancelled' => 'Cancelled', 'plan_review' => 'Plan needs review'];
        if (! $journey && $row->status === 'completed') {
            $labels['completed'] = 'Request marked complete';
        }
        $next = match ($stage) {
            'assessment' => 'Assess transport needs', 'information' => 'Provide the requested information', 'allocation' => 'Build the transport plan',
            'decision' => 'Review the Fleet booking', 'ready' => 'Check vehicle readiness and check out', 'depart' => 'Record departure',
            'travelling' => 'Record passenger arrival', 'returned' => $journey?->passengers_accounted_at ? 'Resolve handoffs and complete journey' : 'Confirm passengers are accounted for',
            'plan_review' => 'Review the booking outcome', 'completed' => 'View the completed journey', 'cancelled' => 'View request history', default => 'Review transport',
        };
        $nextOwner = match ($stage) {
            'information' => $row->creator?->name ?? 'Requester',
            'depart', 'travelling', 'returned', 'ready' => $booking?->driver?->name ?? 'Assigned driver',
            'completed', 'cancelled' => 'No journey action due',
            'decision' => 'Authorised Fleet approver', default => 'Transport coordinator',
        };
        $keys = $booking ? ($related !== null ? $related['keys']->get($booking->id) : FleetKeyLog::where('booking_id', $booking->id)->orderByDesc('id')->first()) : null;
        $received = $row->events->where('action', 'receive_items')->flatMap(fn ($event) => $event->details['items'] ?? [])->unique()->values()->all();
        $missing = array_values(array_diff($row->equipment_required ?? [], $received));
        $handovers = $booking ? ($related !== null ? $related['handovers']->get($booking->id, collect()) : FleetShiftHandover::where('booking_id', $booking->id)->with('incomingUser:id,name')->get(['id', 'status', 'incoming_user_id', 'handed_over_at', 'accepted_at'])) : collect();
        $returnStage = match (true) {
            $handovers->contains('status', 'disputed') => 'exceptions',
            $handovers->contains('status', 'pending_acceptance') => 'handover',
            ! $booking || ! in_array($booking->status, ['checked_out', 'returned'], true) => 'not_due',
            $booking->status === 'checked_out' && $booking->ends_at->isPast() => 'overdue',
            ! $booking->returned_at => 'due',
            ! $keys || ! $keys->site_room_id => 'keys',
            $missing !== [] => 'items',
            default => 'complete',
        };
        $dto = [
            'id' => (int) $row->id, 'reference' => 'TR-'.$row->id, 'version' => $row->lock_version,
            'client_id' => (int) $row->client_id, 'person' => $row->client->full_name,
            'site' => ['id' => (int) $row->client->site_id, 'name' => $row->client->site?->name ?? 'Site'],
            'purpose' => $row->purpose, 'pickup' => $row->pickup_location ?: $row->client->site?->name,
            'destination' => $row->destination, 'start' => $row->scheduled_at?->toIso8601String(),
            'end' => $row->expected_return_at?->toIso8601String(), 'stage' => $stage, 'stage_label' => $labels[$stage] ?? 'Needs review',
            'next_action' => $next, 'next_owner' => $nextOwner, 'required_seats' => $row->required_seats,
            'wheelchair_required' => $row->wheelchair_required, 'escort_required' => (bool) $row->escort_required,
            'return_trip' => (bool) $row->return_trip, 'equipment_required' => $row->equipment_required ?? [],
            'information_required' => $row->information_required, 'operational_notes' => $row->operational_notes,
            'escort' => $row->escort ? ['id' => $row->escort->id, 'name' => $row->escort->name] : null,
            'key_pickup_room_id' => $row->key_pickup_room_id, 'key_return_room_id' => $row->key_return_room_id,
            'key_delivery_arrangement' => $row->key_delivery_arrangement,
            'booking' => $booking ? ['id' => $booking->id, 'reference' => $booking->reference_number ?: 'BK-'.$booking->id,
                'status' => $booking->status, 'version' => $booking->lock_version, 'start' => $booking->starts_at->toIso8601String(),
                'end' => $booking->ends_at->toIso8601String(), 'driver' => ['id' => $booking->driverUserId(), 'name' => $booking->driver?->name ?? $booking->user?->name],
                'vehicle' => ['id' => $booking->asset_id, 'name' => $booking->asset?->name, 'registration' => $booking->asset?->registration_number],
                'returned_at' => $booking->returned_at?->toIso8601String(), 'odometer_out' => $booking->odometer_out,
                'odometer_in' => $booking->odometer_in, 'condition' => $booking->condition_on_return,
                'approval_route' => $booking->approval_route, 'decision_reason' => $booking->rejection_reason ?: $booking->cancellation_reason,
            ] : null,
            'journey' => $journey ? ['id' => $journey->id, 'status' => $journey->status,
                'departed_at' => $journey->departed_at?->toIso8601String(), 'arrived_at' => $journey->arrived_at?->toIso8601String(),
                'accounted_at' => $journey->passengers_accounted_at?->toIso8601String()] : null,
            'return_stage' => $returnStage, 'missing_items' => $missing,
            'source_actions' => $booking ? $this->calendar->bookingDecisions($actor, $booking) : null,
            'return_next_owner' => match ($returnStage) {
                'due', 'overdue' => $booking?->driver?->name ?? 'Assigned driver',
                'handover' => $handovers->firstWhere('status', 'pending_acceptance')?->incomingUser?->name ?? 'Named incoming worker',
                'keys', 'items', 'exceptions' => 'Authorised Fleet receiver',
                default => 'No return action due',
            },
            'handovers' => $handovers->map(fn ($h) => ['id' => $h->id, 'status' => $h->status])->all(),
            'keys' => $keys ? ['action' => $keys->action, 'room_id' => $keys->site_room_id, 'location' => $keys->location,
                'at' => $keys->created_at?->toIso8601String()] : null,
            'can' => ['manage' => $manage, 'respond' => $owner || $manage, 'note' => $owner || $manage,
                'depart' => $driver && $stage === 'depart', 'observe' => ($driver || $manage) && (bool) $journey,
                'approve' => $actor->canDo('fleet.bookings.approve') || $manage],
            'links' => ['client' => '/operations/clients/'.$row->client_id.'?tab=transport',
                'site' => '/sites/'.$row->client->site_id,
                'request' => '/fleet-assets/transports/requests/'.$row->id,
                'booking' => $booking ? '/fleet-assets/bookings/'.$booking->id : null,
                'journey' => $journey ? '/fleet-assets/transports/'.$journey->id : null,
                'vehicle' => $booking ? '/fleet-assets/vehicles/'.$booking->asset_id.'?tab=trips' : null],
        ];
        if ($full) {
            $dto['history'] = $row->events->map(fn ($e) => ['id' => $e->id, 'action' => $e->action, 'at' => $e->created_at->toIso8601String(),
                'actor' => $e->actor?->name, 'message' => $e->details['message'] ?? '', 'items' => $e->details['items'] ?? []])->values()->all();
            $dto['rooms'] = SiteRoom::where('site_id', $row->client->site_id)->orderBy('name')->get(['id', 'name'])->toArray();
            $dto['source_booking'] = $booking ? $this->calendar->record($actor, $booking->asset, 'booking', (int) $booking->id) : null;
        }

        return $dto;
    }

    /** Use the same queue contract as the workspace for a filtered export. */
    public function selected(array $rows, string $view, string $queue, array $stages = []): array
    {
        return array_values(array_filter($rows, function ($row) use ($view, $queue, $stages) {
            if ($stages !== []) {
                return in_array($row['stage'], $stages, true);
            }
            if ($view === 'returns') {
                return match ($queue) {
                    'all' => $row['return_stage'] !== 'not_due',
                    'attention' => ! in_array($row['return_stage'], ['not_due', 'complete'], true),
                    'due' => ($row['booking']['status'] ?? null) === 'checked_out',
                    'items' => ($row['booking']['returned_at'] ?? null) && $row['missing_items'] !== [],
                    'keys' => ($row['booking']['returned_at'] ?? null) && ! ($row['keys']['room_id'] ?? null),
                    'handover' => collect($row['handovers'])->contains('status', 'pending_acceptance'),
                    'exceptions' => collect($row['handovers'])->contains('status', 'disputed'),
                    default => $row['return_stage'] === $queue,
                };
            }
            if ($view === 'journeys') {
                return $queue === 'all' ? (bool) $row['booking'] : $row['stage'] === $queue;
            }
            if ($view === 'planner') {
                return $queue === 'planned' ? in_array($row['booking']['status'] ?? null, ['pending', 'approved', 'rejected'], true) && $row['stage'] !== 'cancelled' : ! $row['booking'] && $row['stage'] === 'allocation';
            }
            if ($view === 'overview' && $queue === 'attention') {
                return in_array($row['return_stage'], ['overdue', 'keys', 'items', 'handover', 'exceptions'], true)
                    || in_array($row['stage'], ['assessment', 'information', 'allocation', 'decision', 'returned'], true);
            }
            if ($view === 'overview' && $queue === 'departures') {
                return ! $row['journey'] && ! in_array($row['stage'], ['completed', 'cancelled'], true);
            }

            return in_array($view, ['overview', 'calendar'], true) || $queue === 'all' || $row['stage'] === $queue;
        }));
    }
}
