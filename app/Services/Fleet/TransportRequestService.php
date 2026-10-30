<?php

namespace App\Services\Fleet;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\ClientTransportBooking;
use App\Models\FleetKeyLog;
use App\Models\FleetVehicleBooking;
use App\Models\SiteRoom;
use App\Models\TransportRequestEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Demand and observations only. Fleet retains reservation, readiness and vehicle return authority. */
class TransportRequestService
{
    public function __construct(
        private readonly VehicleBookingAccessService $access,
        private readonly VehicleStaffDirectory $staff,
        private readonly ResidentTransportJourneyScope $journeyScope,
        private readonly ResidentTransportJourneyService $journeys,
    ) {}

    public function query(User $actor): Builder
    {
        return ClientTransportBooking::query()
            ->whereIn('client_id', $this->access->clients($actor)->pluck('id'))
            ->whereHas('client', fn (Builder $q) => $q->whereIn('site_id', $this->access->accessibleSiteIds($actor)))
            ->where(fn (Builder $q) => $q->whereNull('site_id')->orWhereHas('client',
                fn (Builder $c) => $c->whereColumn('clients.site_id', 'client_transport_bookings.site_id')))
            ->where(fn (Builder $q) => $q->whereNull('fleet_booking_id')
                ->orWhereIn('fleet_booking_id', $this->access->accessibleBookings($actor)->select('fleet_vehicle_bookings.id')));
    }

    public function find(User $actor, int $id, bool $lock = false): ClientTransportBooking
    {
        return $this->query($actor)->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function create(User $actor, array $input): ClientTransportBooking
    {
        $id = Validator::make($input, ['client_id' => 'required|integer'])->validate()['client_id'];
        $client = $this->access->client($actor, (int) $id) ?? abort(404);
        abort_unless($actor->canDo('fleet.manage') || Gate::forUser($actor)->allows('update', $client), 403);

        return DB::transaction(function () use ($actor, $client, $input) {
            $actor = $actor->fresh() ?? abort(403);
            abort_unless($this->access->lockSites($actor, [(int) $client->site_id]), 404);
            $client = $this->access->client($actor, (int) $client->id, true) ?? abort(404);
            abort_unless($actor->canDo('fleet.manage') || Gate::forUser($actor)->allows('update', $client), 403);
            $data = $this->demandData($input);
            $key = Validator::make($input, ['command_key' => 'required|uuid'])->validate()['command_key'];
            $hash = MaintenanceFingerprint::of(['actor' => $actor->id, 'client' => $client->id, 'data' => $data]);
            if ($prior = ClientTransportBooking::where('creation_key', $key)->first()) {
                abort_unless((int) $prior->created_by === (int) $actor->id && hash_equals((string) $prior->creation_fingerprint, $hash), 409);

                return $this->find($actor, (int) $prior->id);
            }
            $row = ClientTransportBooking::create([...$data, 'client_id' => $client->id,
                'site_id' => $client->site_id, 'created_by' => $actor->id, 'status' => 'requested',
                'workflow_state' => 'assessment', 'lock_version' => 1, 'creation_key' => $key, 'creation_fingerprint' => $hash]);
            $this->event($row, $actor, 'requested', $key, $hash, ['message' => 'Transport requested. Needs assessment.']);

            return $row;
        }, 3);
    }

    public function command(User $actor, int $id, array $input): ClientTransportBooking
    {
        $preview = $this->find($actor, $id);

        return DB::transaction(function () use ($actor, $id, $input, $preview) {
            // Follow the booking source lock order: Site, Client, Asset, booking, demand.
            $client = $this->access->client($actor, (int) $preview->client_id) ?? abort(404);
            abort_unless($this->access->lockSites($actor, [(int) $client->site_id]), 404);
            $client = $this->access->client($actor, (int) $client->id, true) ?? abort(404);
            $booking = null;
            if ($preview->fleet_booking_id) {
                $booking = $this->access->booking($actor, (int) $preview->fleet_booking_id) ?? abort(404);
                $this->access->vehicle($actor, (int) $booking->asset_id, true) ?? abort(404);
                $booking = $this->access->booking($actor, (int) $booking->id, true) ?? abort(404);
            }
            $row = $this->find($actor, $id, true);
            abort_unless($row->fleet_booking_id === $preview->fleet_booking_id, 409, 'This request changed. Reload before continuing.');
            $data = Validator::make($input, [
                'action' => 'required|in:assess,information,respond,cancel,note,depart,arrive,account,complete,receive_items,store_keys',
                'expected_version' => 'required|integer|min:1', 'command_key' => 'required|uuid',
                'message' => 'nullable|string|max:2000',
            ])->validate();
            $action = $data['action'];
            $actor = $actor->fresh() ?? abort(403);
            $manage = $actor->canDo('fleet.manage');
            $driver = $booking && $booking->driverUserId() === (int) $actor->id;
            $owner = (int) $row->created_by === (int) $actor->id && Gate::forUser($actor)->allows('update', $client);
            abort_unless(match ($action) {
                'respond', 'cancel', 'note' => $manage || $owner,
                'depart' => $driver,
                'arrive', 'account', 'complete' => $manage || $driver,
                default => $manage,
            }, 403);
            $hash = MaintenanceFingerprint::of(['id' => $id, 'input' => $input]);
            if ($prior = TransportRequestEvent::where('actor_id', $actor->id)->where('command_key', $data['command_key'])->first()) {
                abort_unless((int) $prior->transport_request_id === $id && hash_equals($prior->fingerprint, $hash), 409,
                    'This save key was already used for a different change. Reload before retrying.');

                return $row;
            }
            abort_unless($row->lock_version === (int) $data['expected_version'], 409,
                'Someone changed this request. Your draft is kept. Reload the record and review your changes.');
            $details = ['message' => trim($data['message'] ?? '')];
            $journey = $row->journey()->lockForUpdate()->first();
            if (in_array($action, ['assess', 'information', 'respond'], true)) {
                abort_if($booking || $journey || in_array($row->status, ['cancelled', 'completed'], true), 409, 'This transport is already allocated or closed.');
                if ($action === 'information') {
                    $this->requireMessage($details['message']);
                    $row->fill(['workflow_state' => 'information', 'information_required' => $details['message']]);
                } else {
                    $row->fill($this->demandData($input));
                    $row->fill(['site_id' => $client->site_id, 'workflow_state' => $action === 'assess' ? 'allocation' : 'assessment',
                        'assessed_by' => $action === 'assess' ? $actor->id : null,
                        'assessed_at' => $action === 'assess' ? now() : null, 'information_required' => null]);
                }
            } elseif ($action === 'cancel') {
                $this->requireMessage($details['message']);
                abort_if(in_array($row->status, ['cancelled', 'completed'], true), 409, 'This transport request is already closed.');
                abort_if($journey || ($booking && ! in_array($booking->status, ['cancelled', 'rejected'], true)), 409,
                    'Cancel the Fleet booking first. A departed journey must be returned and completed.');
                $row->fill(['workflow_state' => 'cancelled', 'status' => 'cancelled']);
            } elseif ($action === 'note') {
                $this->requireMessage($details['message']);
            } elseif ($action === 'depart') {
                abort_unless($booking?->status === 'checked_out' && ! $journey, 409,
                    'Complete the Fleet checkout and pre-use requirements before recording departure.');
                $result = $this->journeys->create($actor, ['asset_id' => $booking->asset_id, 'booking_id' => $booking->id,
                    'client_id' => $row->client_id, 'transport_type' => 'other',
                    'pickup_location' => $row->pickup_location, 'dropoff_location' => $row->destination,
                    'departed_at' => now()->toIso8601String(), 'passengers_count' => 1,
                    'request_uuid' => $data['command_key']]);
                $journey = $result['transport'];
                $journey->update(['transport_request_id' => $row->id]);
                $details['journey_id'] = $journey->id;
            } elseif (in_array($action, ['arrive', 'account', 'complete'], true)) {
                abort_unless($journey && $journey->status === 'in_progress', 409, 'No active journey is available.');
                $this->journeyScope->mutableTransportFor($actor, (int) $journey->id, true);
                if ($action === 'arrive') {
                    abort_if($journey->arrived_at, 409, 'Arrival is already recorded.');
                    $journey->update(['arrived_at' => now(), 'version' => (int) $journey->version + 1]);
                } elseif ($action === 'account') {
                    abort_unless($journey->arrived_at && ! $journey->passengers_accounted_at, 409, 'Record arrival first; passenger confirmation must be recorded once.');
                    $journey->update(['passengers_accounted_at' => now(), 'passengers_accounted_by' => $actor->id,
                        'version' => (int) $journey->version + 1]);
                } else {
                    $this->journeys->complete($actor, (int) $journey->id, ['request_uuid' => $data['command_key']]);
                    $row->fill(['status' => 'completed']);
                }
                $details['journey_id'] = $journey->id;
            } elseif ($action === 'receive_items') {
                abort_unless($booking?->returned_at, 409, 'Record the vehicle return first.');
                $items = Validator::make($input, ['items' => 'required|array|min:1', 'items.*' => 'required|string|max:120'])->validate()['items'];
                $allowed = $row->equipment_required ?? [];
                abort_if(array_diff($items, $allowed) !== [], 422, 'Choose an item required by this transport.');
                $received = $row->events()->where('action', 'receive_items')->get()->flatMap(fn ($e) => $e->details['items'] ?? [])->all();
                abort_if(array_intersect($items, $received) !== [], 409, 'An item has already been received. Reload the return.');
                $details['items'] = array_values(array_unique($items));
                $details['receiver_id'] = $actor->id;
            } elseif ($action === 'store_keys') {
                abort_unless($booking?->returned_at, 409, 'Record the vehicle return before storing its keys.');
                $roomId = Validator::make($input, ['site_room_id' => 'required|integer'])->validate()['site_room_id'];
                $room = SiteRoom::where('site_id', $client->site_id)->whereKey($roomId)->firstOrFail();
                $latest = FleetKeyLog::where('asset_id', $booking->asset_id)->where('site_id', $client->site_id)->latest('id')->lockForUpdate()->first();
                abort_unless($latest && (int) $latest->booking_id === (int) $booking->id, 409, 'Resolve the current key custody record in Fleet first.');
                abort_if($latest->site_room_id, 409, 'Keys already have a recorded storage location.');
                $key = FleetKeyLog::create(['asset_id' => $booking->asset_id, 'booking_id' => $booking->id,
                    'site_id' => $client->site_id, 'site_room_id' => $room->id, 'user_id' => $actor->id,
                    'recorded_by_user_id' => $actor->id, 'action' => 'returned', 'key_number' => $latest->key_number,
                    'location' => $room->name, 'notes' => 'Keys received and placed at the recorded site location. '.$details['message']]);
                $details += ['key_log_id' => $key->id, 'site_room_id' => $room->id, 'location' => $room->name];
            }
            $row->lock_version++;
            $row->save();
            $this->event($row, $actor, $action, $data['command_key'], $hash, $details);

            return $row->fresh();
        }, 3);
    }

    /** Called inside the canonical Fleet booking transaction, after its Site/Client/Asset locks. */
    public function allocation(User $actor, int $id, Asset $asset, array $input, Carbon $starts, Carbon $ends, ?int $bookingId = null): ClientTransportBooking
    {
        $row = $this->find($actor, $id, true);
        abort_unless($actor->canDo('fleet.manage'), 403);
        $data = Validator::make($input, ['transport_expected_version' => 'required|integer|min:1',
            'escort_user_id' => 'nullable|integer', 'key_pickup_room_id' => 'required|integer',
            'key_return_room_id' => 'required|integer', 'key_delivery_arrangement' => 'required|string|max:255'])->validate();
        abort_unless($row->lock_version === (int) $data['transport_expected_version'], 409, 'Transport requirements changed. Reload the plan.');
        abort_unless($row->assessed_at && ! in_array($row->status, ['cancelled', 'completed'], true) && ! $row->journey()->exists() && (! $row->fleet_booking_id || (int) $row->fleet_booking_id === $bookingId), 409,
            'Assess this request before allocating it. A request can have only one linked Fleet booking.');
        $siteId = (int) ($asset->site_id ?: $asset->home_site_id);
        abort_unless($siteId === (int) $row->client->site_id && (! $asset->client_id || (int) $asset->client_id === (int) $row->client_id), 404);
        foreach (['key_pickup_room_id', 'key_return_room_id'] as $field) {
            SiteRoom::where('site_id', $siteId)->whereKey($data[$field])->firstOrFail();
        }
        $driverId = (int) ($input['driver_user_id'] ?? 0);
        $escortId = (int) ($data['escort_user_id'] ?? 0);
        $this->assertPlanFits($row, $asset, $driverId, $escortId, $starts, $ends, $bookingId);
        $row->fill(['escort_user_id' => $escortId ?: null, 'key_pickup_room_id' => $data['key_pickup_room_id'],
            'key_return_room_id' => $data['key_return_room_id'], 'key_delivery_arrangement' => $data['key_delivery_arrangement']]);

        return $row;
    }

    private function assertPlanFits(ClientTransportBooking $row, Asset $asset, int $driverId, int $escortId, Carbon $starts, Carbon $ends, ?int $bookingId): void
    {
        // Serialize allocations across vehicles for shared people, then recheck
        // their current employment for the entire planned transport window.
        $people = array_values(array_unique(array_filter([$driverId, $escortId])));
        sort($people, SORT_NUMERIC);
        User::whereIn('id', $people)->orderBy('id')->lockForUpdate()->get();
        $profiles = HrEmployeeProfile::whereIn('user_id', $people)->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
        $errors = [];
        if (! $row->required_seats || ! $asset->seating_capacity || $asset->seating_capacity < $row->required_seats) {
            $errors['asset_id'] = 'Choose a vehicle with confirmed capacity for everyone, including the driver and escort.';
        }
        if ($row->wheelchair_required === null || ($row->wheelchair_required && ! $asset->has_wheelchair_ramp)) {
            $errors['asset_id'] = 'Confirm accessibility needs and choose a vehicle that meets them.';
        }
        if (! $driverId || ! $this->staff->isCandidate($asset, $driverId)) {
            $errors['driver_user_id'] = 'Choose a current driver at this site.';
        }
        if (($row->escort_required && ! $escortId) || ($escortId && ($escortId === $driverId || ! $this->staff->isCandidate($asset, $escortId)))) {
            $errors['escort_user_id'] = 'Choose a different current worker as the required escort.';
        }
        foreach ($people as $personId) {
            $profile = $profiles->get($personId);
            if (! $profile || ! $profile->is_active || ($profile->start_date && $profile->start_date->toDateString() > $starts->copy()->timezone('Pacific/Auckland')->toDateString())
                || ($profile->end_date && $profile->end_date->toDateString() < $ends->copy()->timezone('Pacific/Auckland')->toDateString())) {
                $errors[$personId === $driverId ? 'driver_user_id' : 'escort_user_id'] = 'The selected worker must remain current for the full transport window.';
            }
        }
        $clash = FleetVehicleBooking::query()->when($bookingId, fn ($q) => $q->whereKeyNot($bookingId))
            ->whereIn('status', ['pending', 'approved', 'checked_out'])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('starts_at', '<', $ends)->where('ends_at', '>', $starts))
                ->orWhere('status', 'checked_out'))
            ->where(fn ($q) => $q->whereIn(DB::raw('COALESCE(driver_user_id, user_id)'), $people)
                ->orWhereIn('id', ClientTransportBooking::whereIn('escort_user_id', $people)->select('fleet_booking_id')))->exists();
        if ($clash) {
            $errors['driver_user_id'] = 'A selected worker is already assigned or has not returned from another booking.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function link(ClientTransportBooking $row, FleetVehicleBooking $booking, User $actor): void
    {
        $row->fill(['fleet_booking_id' => $booking->id, 'workflow_state' => 'allocated', 'status' => 'confirmed',
            'driver_id' => $booking->driverUserId(), 'lock_version' => $row->lock_version + 1])->save();
        $details = ['booking_id' => $booking->id, 'message' => 'Transport plan saved with Fleet booking '.($booking->reference_number ?: 'BK-'.$booking->id).'.'];
        $this->event($row, $actor, 'allocated', (string) Str::uuid(), MaintenanceFingerprint::of($details), $details);
    }

    /** Durable retry identity for linked Fleet actions, inside their source transaction. */
    public function beforeBookingCommand(User $actor, FleetVehicleBooking $booking, Request $request, string $action): bool
    {
        $linked = ClientTransportBooking::where('fleet_booking_id', $booking->id)->first();
        if (! $linked) {
            return false;
        }
        $row = $this->find($actor, (int) $linked->id, true);
        if ($request->filled('transport_request_id')) {
            abort_unless($request->integer('transport_request_id') === (int) $row->id, 404);
        }
        $input = [...$request->all(), 'command_key' => $request->input('request_key') ?: $request->header('Idempotency-Key')];
        $data = Validator::make($input, ['command_key' => 'required|uuid', 'expected_version' => 'required|integer|min:1'])->validate();
        $hash = MaintenanceFingerprint::of(['booking' => $booking->id, 'action' => $action, 'input' => $input]);
        if ($event = TransportRequestEvent::where('actor_id', $actor->id)->where('command_key', $data['command_key'])->first()) {
            abort_unless((int) $event->transport_request_id === (int) $row->id && hash_equals($event->fingerprint, $hash), 409, 'This action was already saved with different details.');

            return true;
        }
        abort_unless((int) $booking->lock_version === (int) $data['expected_version'], 409, 'The Fleet booking changed. Reload its current version.');
        $allowed = match ($action) {
            'approve', 'reject' => ['pending'], 'checkout' => ['approved'], 'return' => ['checked_out'], 'cancel' => ['pending', 'approved'], default => [],
        };
        abort_unless(in_array($booking->status, $allowed, true), 409, 'This action is no longer available for the current booking state.');
        if (in_array($action, ['approve', 'checkout'], true)) {
            $asset = $this->access->vehicle($actor, (int) $booking->asset_id) ?? abort(404);
            $this->assertPlanFits($row, $asset, $booking->driverUserId(), (int) $row->escort_user_id, $booking->starts_at, $booking->ends_at, (int) $booking->id);
            foreach ([$row->key_pickup_room_id, $row->key_return_room_id] as $roomId) {
                SiteRoom::where('site_id', $row->client->site_id)->whereKey($roomId)->firstOrFail();
            }
        }
        if ($action === 'cancel') {
            abort_if($booking->checked_out_at || $row->journey()->exists(), 409, 'A departed vehicle must be returned, not cancelled.');
        }
        if ($action === 'checkout') {
            abort_unless($request->boolean('keys_handed'), 422, 'Confirm the planned keys have been handed to the assigned driver.');
            abort_unless($row->assessed_at && $row->key_pickup_room_id && $row->key_return_room_id, 409, 'Finish the transport and key arrangements before checkout.');
        }
        $row->lock_version++;
        $row->save();
        $message = $request->input('decision_notes') ?? $request->input('rejection_reason') ?? $request->input('checkout_notes') ?? $request->input('return_notes') ?? $request->input('reason') ?? '';
        $this->event($row, $actor, 'booking_'.$action, $data['command_key'], $hash, ['message' => (string) $message, 'booking_id' => $booking->id]);

        return false;
    }

    private function demandData(array $input): array
    {
        $data = Validator::make($input, [
            'purpose' => 'required|string|max:255', 'destination' => 'required|string|max:255',
            'pickup_location' => 'required|string|max:255', 'starts_local' => 'required|string',
            'ends_local' => 'required|string', 'starts_offset' => 'nullable|string|max:6', 'ends_offset' => 'nullable|string|max:6',
            'required_seats' => 'required|integer|min:2|max:50', 'wheelchair_required' => 'required|boolean',
            'escort_required' => 'required|boolean', 'return_trip' => 'required|boolean',
            'equipment_required' => 'present|array|max:20', 'equipment_required.*' => 'required|string|distinct|max:120',
            'operational_notes' => 'nullable|string|max:2000',
        ])->validate();
        $data['scheduled_at'] = MaintenanceLocalTime::toUtc($data['starts_local'], $data['starts_offset'] ?? null);
        $data['expected_return_at'] = MaintenanceLocalTime::toUtc($data['ends_local'], $data['ends_offset'] ?? null);
        if ($data['escort_required'] && $data['required_seats'] < 3) {
            throw ValidationException::withMessages(['required_seats' => 'Include seats for the passenger, driver and required escort.']);
        }
        if ($data['expected_return_at'] <= $data['scheduled_at']) {
            throw ValidationException::withMessages(['ends_local' => 'Expected return must be after departure.']);
        }
        if (Carbon::parse($data['scheduled_at'], 'UTC')->diffInDays(Carbon::parse($data['expected_return_at'], 'UTC')) > 31) {
            throw ValidationException::withMessages(['ends_local' => 'Choose a transport window of at most 31 days.']);
        }
        unset($data['starts_local'], $data['ends_local'], $data['starts_offset'], $data['ends_offset']);

        return $data;
    }

    private function requireMessage(string $message): void
    {
        if ($message === '') {
            throw ValidationException::withMessages(['message' => 'Add the reason or details.']);
        }
    }

    private function event(ClientTransportBooking $row, User $actor, string $action, string $key, string $hash, array $details): void
    {
        TransportRequestEvent::create(['transport_request_id' => $row->id, 'actor_id' => $actor->id,
            'action' => $action, 'command_key' => $key, 'fingerprint' => $hash, 'version' => $row->lock_version,
            'details' => $details, 'created_at' => now()]);
        AuditLogger::logOrFail('transport.request.'.$action, $row, ['actor_id' => $actor->id, 'version' => $row->lock_version, 'command_key' => $key]);
    }
}
