<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointAgentIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointHeartbeat;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EndpointAgentController extends Controller
{
    public function enrol(Request $request, EndpointAgentIdentity $identity): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'platform' => ['required', 'in:windows'],
        ]);

        return response()->json($identity->enrol(
            $request->bearerToken() ?? '', $data['credential'], $data['platform'],
        ), 201);
    }

    public function heartbeat(Request $request, EndpointHeartbeat $heartbeats): JsonResponse
    {
        abort_unless($request->isJson(), 415);
        abort_unless($request->getQueryString() === null, 400);
        try {
            // Validate original bytes before Laravel can collapse duplicate keys.
            // The same bounded object/array parser serves the reviewed-script wire.
            EndpointScriptContract::decode($request->getContent(), 1048576);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid endpoint observation.');
        }

        return response()->json($heartbeats->record(
            $request->bearerToken() ?? '',
            json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR),
        ));
    }
}
