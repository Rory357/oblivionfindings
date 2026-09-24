<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetObligationReminder;
use App\Models\User;
use App\Services\Fleet\VehicleObligationReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Acknowledge or retry an obligation reminder from the vehicle's Reminders view. */
class VehicleObligationReminderController extends Controller
{
    public function __construct(private readonly VehicleObligationReminderService $reminders) {}

    public function acknowledge(Request $request, Asset $asset, string $sourceType, int $sourceId): JsonResponse
    {
        $reminder = $this->reminders->acknowledge($this->actor($request), (int) $asset->getKey(), $sourceType, $sourceId,
            (string) $request->input('note', ''), $this->key($request));

        return response()->json(['reminder' => $this->result($reminder),
            'message' => 'Reminder acknowledged. The obligation itself is unchanged.']);
    }

    public function retry(Request $request, Asset $asset, string $sourceType, int $sourceId): JsonResponse
    {
        $reminder = $this->reminders->retry($this->actor($request), (int) $asset->getKey(), $sourceType, $sourceId,
            $this->key($request));

        return response()->json(['reminder' => $this->result($reminder),
            'message' => $reminder->state === FleetObligationReminder::STATE_SENT
                ? 'Reminder delivered to the owner.'
                : 'Delivery failed again: '.$reminder->last_error]);
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
    private function result(FleetObligationReminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'state' => $reminder->state,
            'status_label' => VehicleObligationReminderService::STATUS_LABELS[$reminder->state] ?? $reminder->state,
            'last_error' => $reminder->last_error,
            'lock_version' => $reminder->lock_version,
        ];
    }
}
