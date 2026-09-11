<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItControlRoomHandoffService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\ControlRoomHandoffRequest;
use App\Models\ControlRoomAlert;
use Illuminate\Http\JsonResponse;

final class ItControlRoomHandoffController extends Controller
{
    public function __construct(private readonly ItControlRoomHandoffService $handoffs) {}

    public function preview(ControlRoomHandoffRequest $request, ControlRoomAlert $alert): JsonResponse
    {
        return response()->json(['data' => $this->handoffs->preview($alert, $request->user(),
            (string) $request->input('search', ''))], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function store(ControlRoomHandoffRequest $request, ControlRoomAlert $alert): JsonResponse
    {
        $result = $this->handoffs->execute($alert, $request->user(), $request->only([
            'viewer_user_id', 'request_uuid', 'alert_version', 'action', 'reason', 'ticket_id', 'ticket_version',
            'title', 'description', 'category', 'impact', 'urgency', 'it_service_id',
        ]));

        return response()->json($result, 200, ['Cache-Control' => 'no-store, private']);
    }

    public function recover(ControlRoomHandoffRequest $request, ControlRoomAlert $alert, string $requestUuid): JsonResponse
    {
        return response()->json($this->handoffs->recover($alert, $request->user(),
            ['viewer_user_id' => $request->integer('viewer_user_id'), 'request_uuid' => $requestUuid],
            $request->isMethod('post')), 200, ['Cache-Control' => 'no-store, private']);
    }
}
