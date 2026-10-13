<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Services\MonitoringRecoveryRequestService;
use App\Domain\Monitoring\Services\MonitoringReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class MonitoringDeadLetterController extends Controller
{
    public function replay(
        Request $request,
        MonitoringDeadLetter $deadLetter,
        MonitoringReplayService $replay,
    ): RedirectResponse|JsonResponse {
        if ($request->has('request_uuid')) {
            return $this->recordedDecision($request, $deadLetter, 'replay');
        }
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $replay->replay($request->user(), $deadLetter, $validated['reason']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'reason' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Monitoring replay queued from the original signed evidence.');
    }

    public function discard(
        Request $request,
        MonitoringDeadLetter $deadLetter,
        MonitoringReplayService $replay,
    ): RedirectResponse|JsonResponse {
        if ($request->has('request_uuid')) {
            return $this->recordedDecision($request, $deadLetter, 'discard');
        }
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $replay->discard($request->user(), $deadLetter, $validated['reason']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'reason' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Monitoring evidence discarded from processing and retained for audit.');
    }

    public function requests(Request $request, MonitoringRecoveryRequestService $service): JsonResponse
    {
        abort_if(array_diff(array_keys($request->query()), ['expected_actor_id', 'before_id']) !== [], 400, 'invalid_request');
        $data = $request->validate(['expected_actor_id' => ['required', 'integer', 'min:1'], 'before_id' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($service->history($request->user(), (int) $data['expected_actor_id'], isset($data['before_id']) ? (int) $data['before_id'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function recover(Request $request, string $requestUuid, MonitoringRecoveryRequestService $service): JsonResponse
    {
        abort_if($request->query() !== [] || array_diff(array_keys($request->except('_token')), ['expected_actor_id', 'expected_site_id']) !== [], 400, 'invalid_request');
        $data = $request->validate(['expected_actor_id' => ['required', 'integer', 'min:1'], 'expected_site_id' => ['present', 'nullable', 'integer', 'min:1']]);
        $data['expected_actor_id'] = (int) $data['expected_actor_id'];
        $data['expected_site_id'] = $data['expected_site_id'] === null ? null : (int) $data['expected_site_id'];

        return response()->json($service->recover($request->user(), $requestUuid, $data))->header('Cache-Control', 'private, no-store');
    }

    private function recordedDecision(Request $request, MonitoringDeadLetter $deadLetter, string $operation): JsonResponse
    {
        abort_if($request->query() !== [] || array_diff(array_keys($request->except('_token')), ['request_uuid', 'expected_actor_id', 'expected_site_id', 'review_token', 'reason']) !== [], 400, 'invalid_request');
        $data = $request->validate([
            'request_uuid' => ['required', 'uuid', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD'],
            'expected_actor_id' => ['required', 'integer', 'min:1'], 'expected_site_id' => ['present', 'nullable', 'integer', 'min:1'],
            'review_token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'], 'reason' => ['required', 'string', 'min:3', 'max:500', 'not_regex:/[\\x00-\\x1F\\x7F]/'],
        ]);
        $data['expected_actor_id'] = (int) $data['expected_actor_id'];
        $data['expected_site_id'] = $data['expected_site_id'] === null ? null : (int) $data['expected_site_id'];
        try {
            $result = app(MonitoringRecoveryRequestService::class)->execute($request->user(), (int) $deadLetter->id, $operation, $data);
        } catch (UnexpectedValueException $error) {
            throw ValidationException::withMessages(['reason' => $error->getMessage()]);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
