<?php

namespace App\Http\Controllers\It;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItEndpointActionPolicyController extends Controller
{
    public function show(Request $request, Device $device, EndpointActionPolicy $policies): JsonResponse
    {
        return response()->json($policies->show($request->user(), $device, $request->query()), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function update(Request $request, Device $device, EndpointActionPolicy $policies): JsonResponse
    {
        return response()->json($policies->save($request->user(), $device, $request->json()->all()), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
