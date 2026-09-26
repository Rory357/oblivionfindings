<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\User;
use App\Services\Fleet\VehicleAppointmentService;
use App\Services\Fleet\VehicleCalendarService;
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
        private readonly VehicleUnavailablePeriodService $unavailable,
        private readonly VehicleAppointmentService $appointments,
    ) {}

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
