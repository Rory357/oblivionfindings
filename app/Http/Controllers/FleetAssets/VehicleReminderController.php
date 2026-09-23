<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetVehicleReminder;
use App\Models\User;
use App\Services\Fleet\VehicleReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Vehicle follow-ups: create, reschedule, acknowledge, complete, pause and resume. */
class VehicleReminderController extends Controller
{
    private const FIELDS = ['title', 'action_text', 'source_type', 'source_id', 'remind_local', 'remind_offset',
        'repeat_months', 'owner_user_id', 'backup_user_id', 'reason'];

    public function __construct(private readonly VehicleReminderService $reminders) {}

    public function store(Request $request, Asset $asset): JsonResponse
    {
        $reminder = $this->reminders->create($this->actor($request), (int) $asset->getKey(), $request->only(self::FIELDS), $this->key($request));

        return response()->json($this->result($reminder));
    }

    public function update(Request $request, Asset $asset, FleetVehicleReminder $reminder): JsonResponse
    {
        $updated = $this->reminders->update($this->actor($request), (int) $asset->getKey(), (int) $reminder->getKey(),
            $request->only(self::FIELDS), (int) $request->input('expected_version'), $this->key($request));

        return response()->json($this->result($updated));
    }

    public function act(Request $request, Asset $asset, FleetVehicleReminder $reminder, string $action): JsonResponse
    {
        $updated = $this->reminders->act($this->actor($request), (int) $asset->getKey(), (int) $reminder->getKey(), $action,
            (string) $request->input('note', ''), (int) $request->input('expected_version'), $this->key($request));

        return response()->json($this->result($updated));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function key(Request $request): string
    {
        return (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: '');
    }

    /** @return array<string,mixed> */
    private function result(FleetVehicleReminder $reminder): array
    {
        return ['reminder' => [
            'id' => $reminder->id, 'state' => $reminder->state, 'lock_version' => $reminder->lock_version,
            'due_at' => $reminder->due_at?->toIso8601String(),
        ]];
    }
}
