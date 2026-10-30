<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCommands;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EndpointServiceCommandController extends Controller
{
    public function poll(Request $request, EndpointServiceCommands $commands): JsonResponse
    {
        abort_unless($request->query() === [], 422);

        return response()->json($commands->poll($request->bearerToken() ?? ''));
    }

    public function claim(Request $request, string $attempt, EndpointServiceCommands $commands): JsonResponse
    {
        return response()->json($commands->claim($request->bearerToken() ?? '', $attempt, $request->json()->all()));
    }

    public function result(Request $request, string $attempt, EndpointServiceCommands $commands): JsonResponse
    {
        return response()->json($commands->result($request->bearerToken() ?? '', $attempt, $request->json()->all()));
    }
}
