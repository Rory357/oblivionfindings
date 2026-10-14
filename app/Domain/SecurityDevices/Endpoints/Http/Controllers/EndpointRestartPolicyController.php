<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartPolicy;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EndpointRestartPolicyController extends Controller
{
    public function show(Request $request, Device $device, EndpointRestartPolicy $policies): JsonResponse
    {
        return response()->json($policies->show($request->user(), $device, $request->query()))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Device $device, EndpointRestartPolicy $policies): JsonResponse
    {
        return response()->json($policies->save($request->user(), $device, $request->all()))->header('Cache-Control', 'private, no-store');
    }
}
