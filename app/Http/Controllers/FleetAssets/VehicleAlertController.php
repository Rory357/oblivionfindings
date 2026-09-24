<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fleet\VehicleAlertPlanService;
use App\Services\Fleet\VehicleAlertResponseService;
use App\Services\Fleet\VehicleAlertRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PKG-02B vehicle profile › Map › Alerts & Control Room: the vehicle's
 * Control Room responses, the lifecycle actions Control Room allows, the
 * Maintenance and reminder follow-up linked to a response, recorded events
 * sent to Control Room, delivery retries and the draft response plan. A
 * foreign or missing vehicle or response answers 404. Responses carry
 * locations and driver evidence: they are never cached.
 */
class VehicleAlertController extends Controller
{
    private const MESSAGES = [
        'acknowledge' => 'Response acknowledged in Control Room.',
        'triage' => 'Triage decision recorded in Control Room.',
        'escalate' => 'Response escalated in Control Room.',
        'resolve' => 'Assessment recorded and the response resolved. The vehicle is not released and linked work stays open.',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $vehicles,
        private readonly VehicleAlertResponseService $responses,
        private readonly VehicleAlertRoutingService $routing,
        private readonly VehicleAlertPlanService $plans,
    ) {}

    public function index(Request $request, int $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->vehicles->assignableVehicle($viewer, $asset) ?? abort(404);
        $data = $request->validate(['status' => ['nullable', 'in:open,all,resolved']]);

        return $this->private(response()->json($this->responses->queue($viewer, $vehicle, (string) ($data['status'] ?? 'open'))));
    }

    public function show(Request $request, int $asset, int $alert): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->vehicles->assignableVehicle($viewer, $asset) ?? abort(404);

        return $this->private(response()->json($this->responses->detail($viewer, $vehicle, $alert)));
    }

    public function act(Request $request, int $asset, int $alert, string $action): JsonResponse
    {
        $response = $this->responses->act($this->actor($request), $asset, $alert, $action,
            $request->only(['note', 'decision', 'outcome', 'expected_version']), $this->key($request));

        return $this->private(response()->json(['response' => $response, 'message' => self::MESSAGES[$action]]));
    }

    public function maintenance(Request $request, int $asset, int $alert): JsonResponse
    {
        $response = $this->responses->maintenance($this->actor($request), $asset, $alert,
            $request->only(['mode', 'title', 'description', 'work_order_id', 'reason', 'expected_version']), $this->key($request));

        return $this->private(response()->json([
            'response' => $response,
            'message' => ($request->input('mode') === 'link' ? 'Existing Maintenance work linked' : 'Maintenance assessment created')
                .'. The site Coordinator assesses it; the response and the work keep their own outcomes.',
        ]));
    }

    public function followUp(Request $request, int $asset, int $alert): JsonResponse
    {
        $response = $this->responses->followUp($this->actor($request), $asset, $alert, $request->only([
            'title', 'action_text', 'remind_local', 'remind_offset', 'owner_user_id', 'backup_user_id', 'repeat_months',
        ]), $this->key($request));

        return $this->private(response()->json([
            'response' => $response,
            'message' => 'Follow-up reminder created. It shows in this vehicle\'s reminders and in All Tasks for its owner.',
        ]));
    }

    public function route(Request $request, int $asset): JsonResponse
    {
        return $this->private(response()->json($this->routing->route($this->actor($request), $asset,
            $request->only(['kind', 'trip_id', 'event_key', 'event_id', 'evaluation']), $this->key($request))));
    }

    public function retry(Request $request, int $asset, int $signal): JsonResponse
    {
        $data = $request->validate(['expected_attempts' => ['required', 'integer', 'min:0']]);

        return $this->private(response()->json($this->routing->retry($this->actor($request), $asset, $signal,
            (int) $data['expected_attempts'], $this->key($request))));
    }

    public function savePlan(Request $request, int $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $plan = $this->plans->save($actor, $asset, $request->only([
            'owner_user_id', 'backup_user_id', 'speed_threshold_kph', 'speed_tolerance_kph', 'speed_duration_s',
            'speed_cooldown_s', 'offline_minutes', 'low_voltage_v', 'low_voltage_minutes', 'notes', 'reason',
            'expected_version',
        ]), $this->key($request));
        $vehicle = $this->vehicles->assignableVehicle($actor, $asset) ?? abort(404);

        return $this->private(response()->json([
            'plan' => $this->plans->present($this->plans->current($vehicle)),
            'message' => 'Draft response plan saved as version '.$plan->version.'. Activation stays pending; no device setting changed.',
        ]));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function key(Request $request): string
    {
        return mb_substr((string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''), 0, 90);
    }

    /** Responses can include positions and driver evidence: never cache them. */
    private function private(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
