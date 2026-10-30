<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetServiceSchedule;
use App\Models\User;
use App\Services\Fleet\VehicleServiceScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Service schedules and recorded services from the vehicle workspace. */
class VehicleServiceScheduleController extends Controller
{
    private const FIELDS = ['name', 'interval_months', 'interval_km', 'next_due_at', 'next_due_km', 'owner_user_id',
        'reminder_days_before', 'reminder_km_before', 'is_active'];

    public function __construct(private readonly VehicleServiceScheduleService $schedules) {}

    public function store(Request $request, Asset $asset): JsonResponse
    {
        // Every create carries a request key, so a retried save can't add a second schedule.
        $schedule = $this->schedules->save($this->actor($request), (int) $asset->getKey(), null, $request->only(self::FIELDS), null,
            (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''));

        return response()->json(['schedule' => ['id' => $schedule->id, 'lock_version' => (int) $schedule->lock_version]]);
    }

    public function update(Request $request, Asset $asset, FleetServiceSchedule $schedule): JsonResponse
    {
        $saved = $this->schedules->save($this->actor($request), (int) $asset->getKey(), (int) $schedule->getKey(),
            $request->only(self::FIELDS), (int) $request->input('expected_version'));

        return response()->json(['schedule' => ['id' => $saved->id, 'lock_version' => (int) $saved->lock_version]]);
    }

    public function complete(Request $request, Asset $asset, FleetServiceSchedule $schedule): JsonResponse
    {
        $completion = $this->schedules->recordCompletion($this->actor($request), (int) $asset->getKey(), (int) $schedule->getKey(),
            $request->only(['completed_on', 'odometer_km', 'work_order_id', 'provider', 'evidence_reference', 'notes', 'next_due_at', 'next_due_km']),
            (int) $request->input('expected_version'),
            (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''));

        return response()->json(['completion' => ['id' => $completion->id, 'next_due_at' => $completion->next_due_at?->toDateString(),
            'next_due_km' => $completion->next_due_km === null ? null : (float) $completion->next_due_km]]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
