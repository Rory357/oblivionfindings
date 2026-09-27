<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\User;
use App\Services\Fleet\VehicleAppointmentService;
use App\Services\Fleet\VehicleCalendarService;
use App\Services\Fleet\VehicleLocationService;
use App\Services\Fleet\VehicleUnavailablePeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The vehicle calendar: a JSON feed for the visible period, one custody
 * record, unavailable periods (with undo of a cancellation), and service
 * appointments scheduled from a calendar slot or a due item.
 */
class VehicleCalendarController extends Controller
{
    /** The widest window one feed request may cover. */
    private const MAX_RANGE_DAYS = 400;

    public function __construct(
        private readonly SecurityDevicesAccessService $vehicles,
        private readonly VehicleCalendarService $calendar,
        private readonly VehicleLocationService $location,
        private readonly VehicleUnavailablePeriodService $unavailable,
        private readonly VehicleAppointmentService $appointments,
    ) {}

    /** One bounded, permission-projected feed for the complete fleet calendar. */
    public function fleetEvents(Request $request): JsonResponse
    {
        $viewer = $this->actor($request);
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);
        $start = CarbonImmutable::parse($data['start'], VehicleLocationService::zone())->utc();
        $end = CarbonImmutable::parse($data['end'], VehicleLocationService::zone())->utc();
        // Six local calendar weeks may include a 25-hour daylight-saving day.
        abort_if($end->greaterThan($start->setTimezone(VehicleLocationService::zone())->addDays(42)),
            422, 'Choose a shorter fleet calendar period.');

        $events = [];
        // The service owns source visibility, including the busy-only booking
        // projection. This is one browser request, independent of list pages.
        foreach ($this->vehicles->accessibleVehiclesForFleet($viewer)->orderBy('id')->cursor() as $vehicle) {
            foreach ($this->calendar->events($viewer, $vehicle, $start, $end) as $item) {
                $busy = $item['kind'] === 'busy';
                // Busy entries may not carry a source record identifier, even
                // in the DOM or client-side search corpus.
                if ($busy) {
                    $item['id'] = 'busy:'.hash_hmac('sha256', $vehicle->id.'|'.$item['id'].'|'.$item['start'].'|'.$item['end'], config('app.key'));
                    $item['ref'] = null;
                    $item['recordId'] = null;
                    $item['link'] = null;
                    $item['editable'] = false;
                }
                // Source IDs are unique only inside a vehicle calendar (for
                // example check-due and compliance:wof). Shared calendar keys
                // must remain unique when several vehicles have the same due date.
                $item['id'] = 'vehicle:'.$vehicle->id.':'.$item['id'];
                $item['vehicleId'] = (int) $vehicle->id;
                $events[] = $item;
            }
        }

        return response()->json(['events' => $events, 'as_of' => now()->toIso8601String(), 'timezone' => VehicleLocationService::zone()]);
    }

    /** Every permitted vehicle, with coordinates projected by the location owner. */
    public function fleetMap(Request $request): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicles = $this->vehicles->accessibleVehiclesForFleet($viewer)
            ->with([
                'homeSite:id,name,latitude,longitude', 'site:id,name,latitude,longitude',
                'fleetState.lastEvent:id,occurred_at,received_at,external_power,event_type',
                'fleetState.lastTrip:id,is_personal,consent_blocked,started_at,ended_at',
            ])
            ->orderBy('name')->orderBy('id')->get();
        $siteIds = $this->vehicles->accessibleSiteIds($viewer);
        $positionIds = $this->location->visibleVehicleIds($viewer, $vehicles->pluck('id')->all());

        return response()->json([
            'as_of' => now()->toIso8601String(),
            'timezone' => VehicleLocationService::zone(),
            'fresh_minutes' => VehicleLocationService::FRESH_MINUTES,
            'vehicles' => $vehicles->map(function (Asset $vehicle) use ($viewer, $siteIds, $positionIds): array {
                $home = $vehicle->homeSite ?? $vehicle->site;
                $showHomeCoordinates = $home && in_array((int) $home->id, $siteIds, true);
                return [
                    'id' => (int) $vehicle->id,
                    'name' => (string) $vehicle->name,
                    'asset_tag' => $vehicle->asset_tag,
                    'registration_number' => $vehicle->registration_number,
                    'status' => $vehicle->status,
                    'home_site' => $home ? [
                        'id' => (int) $home->id, 'name' => (string) $home->name,
                        'lat' => $showHomeCoordinates && $home->latitude !== null ? (float) $home->latitude : null,
                        'lng' => $showHomeCoordinates && $home->longitude !== null ? (float) $home->longitude : null,
                    ] : null,
                    ...$this->location->fleetMapState($viewer, $vehicle, in_array((int) $vehicle->id, $positionIds, true)),
                ];
            })->values(),
        ]);
    }

    public function events(Request $request, Asset $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->vehicles->fleetVehicle($viewer, (int) $asset->getKey()) ?? abort(404);
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);
        $start = CarbonImmutable::parse($data['start'])->utc();
        $end = CarbonImmutable::parse($data['end'])->utc();
        abort_if($start->diffInDays($end) > self::MAX_RANGE_DAYS, 422, 'Choose a shorter calendar period.');

        return response()->json(['events' => $this->calendar->events($viewer, $vehicle, $start, $end)]);
    }

    public function summary(Request $request, Asset $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->vehicles->fleetVehicle($viewer, (int) $asset->getKey()) ?? abort(404);

        return response()->json($this->calendar->summary($viewer, $vehicle));
    }

    /**
     * One booking or unavailable period as the summary lists it, for records
     * outside the summary's capped lists. Bookings keep their own Site rule;
     * anything else, foreign or missing answers 404.
     */
    public function record(Request $request, Asset $asset, string $kind, int $id): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->vehicles->fleetVehicle($viewer, (int) $asset->getKey()) ?? abort(404);

        return response()->json(['row' => $this->calendar->record($viewer, $vehicle, $kind, $id) ?? abort(404)]);
    }

    public function storeUnavailable(Request $request, Asset $asset): JsonResponse
    {
        $period = $this->unavailable->create($this->actor($request), (int) $asset->getKey(),
            $request->only(['starts_local', 'ends_local', 'starts_offset', 'ends_offset', 'reason']), $this->key($request));

        return response()->json(['period' => $this->periodResult($period), 'message' => 'Unavailable period recorded.']);
    }

    public function updateUnavailable(Request $request, Asset $asset, FleetVehicleUnavailablePeriod $period): JsonResponse
    {
        $updated = $this->unavailable->update($this->actor($request), (int) $asset->getKey(), (int) $period->getKey(),
            $request->only(['starts_local', 'ends_local', 'starts_offset', 'ends_offset', 'reason', 'change_reason']),
            (int) $request->input('expected_version'));

        return response()->json(['period' => $this->periodResult($updated), 'message' => 'Unavailable period changed.']);
    }

    public function cancelUnavailable(Request $request, Asset $asset, FleetVehicleUnavailablePeriod $period): JsonResponse
    {
        $cancelled = $this->unavailable->cancel($this->actor($request), (int) $asset->getKey(), (int) $period->getKey(),
            (string) $request->input('reason', ''), (int) $request->input('expected_version'));

        return response()->json(['period' => $this->periodResult($cancelled), 'message' => 'Unavailable period cancelled.']);
    }

    /** Undo a cancellation (a period recorded on the calendar, not an appointment's hold). */
    public function restoreUnavailable(Request $request, Asset $asset, FleetVehicleUnavailablePeriod $period): JsonResponse
    {
        $restored = $this->unavailable->restore($this->actor($request), (int) $asset->getKey(), (int) $period->getKey(),
            (int) $request->input('expected_version'), $this->key($request));

        return response()->json(['period' => $this->periodResult($restored), 'message' => 'Unavailable period restored.']);
    }

    public function scheduleAppointment(Request $request, Asset $asset): JsonResponse
    {
        $files = array_values(array_filter((array) $request->file('files', []), fn (mixed $file): bool => $file instanceof UploadedFile));
        $result = $this->appointments->schedule($this->actor($request), (int) $asset->getKey(),
            $request->only(['operation', 'change_reason', 'work_order_id', 'title', 'provider_name', 'starts_local',
                'ends_local', 'starts_offset', 'ends_offset', 'unavailable', 'provider_reference', 'notes',
                'source_type', 'source_id', 'expected_version']),
            $this->key($request), $files);
        $order = $result['work_order'];

        return response()->json([
            'work_order_id' => (int) $order->id,
            'work_order' => ['id' => $order->id, 'reference' => $order->reference_number, 'version' => (int) $order->version],
            'unavailable_period' => $result['unavailable_period'] ? $this->periodResult($result['unavailable_period']) : null,
            'undo' => $result['undo'] ?? null,
            'message' => match ($request->input('operation')) {
                'cancel' => 'Appointment cancelled on the work order. The vehicle is no longer held for it; the work stays open in Maintenance.',
                'overrun' => 'Overrun recorded on the work order and the vehicle calendar.',
                default => 'Appointment saved in Maintenance and on the vehicle calendar.',
            },
        ]);
    }

    public function undoAppointment(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate(['command_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1']]);
        $result = $this->appointments->undo($this->actor($request), (int) $asset->getKey(),
            (int) $data['command_id'], (int) $data['expected_version'], $this->key($request));

        return response()->json(['work_order_id' => (int) $result['work_order']->id,
            'message' => 'Previous internal appointment restored. Provider confirmation remains a separate action.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function key(Request $request): string
    {
        return mb_substr((string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''), 0, 100);
    }

    /** @return array<string,mixed> */
    private function periodResult(FleetVehicleUnavailablePeriod $period): array
    {
        return [
            'id' => $period->id, 'state' => $period->state, 'lock_version' => (int) $period->lock_version,
            'starts_at' => $period->starts_at?->toIso8601String(), 'ends_at' => $period->ends_at?->toIso8601String(),
        ];
    }
}
