<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointAgentIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointHeartbeat;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        return response()->json($heartbeats->record($request->bearerToken() ?? '', $request->json()->all()));
    }
}
