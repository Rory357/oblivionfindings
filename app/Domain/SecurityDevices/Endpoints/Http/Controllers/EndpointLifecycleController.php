<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointAgentIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointBootstrap;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EndpointLifecycleController extends Controller
{
    public function issue(Request $request, Device $device, EndpointAgentIdentity $identity): JsonResponse
    {
        $data = $request->validate(['platform' => ['required', 'in:windows']]);

        return response()->json($identity->issue($request->user(), $device, $data['platform']), 201);
    }

    public function options(Request $request, Device $device, EndpointBootstrap $bootstrap): JsonResponse
    {
        return response()->json($bootstrap->options($request->user(), $device));
    }

    public function bootstrap(Request $request, Device $device, EndpointBootstrap $bootstrap): JsonResponse
    {
        $data = $request->validate(['expected_context' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return response()->json($bootstrap->issue($request->user(), $device, $data['expected_context'] ?? null), 201, [
            'Content-Disposition' => 'attachment; filename="oblivion-computer-'.$device->id.'.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function revoke(Request $request, Device $device, EndpointAgentIdentity $identity): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], 'expected_agent_uuid' => ['sometimes', 'required', 'uuid']]);
        $identity->revoke($request->user(), $device, $data['reason'], $data['expected_agent_uuid'] ?? null);

        return response()->json(['state' => 'revoked']);
    }
}
