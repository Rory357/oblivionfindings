<?php

namespace App\Http\Controllers\It;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemotePolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSessions;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItRemoteSessionController extends Controller
{
    public function policy(Request $request, Device $device, EndpointRemotePolicy $policies): JsonResponse
    {
        return response()->json(['actor_id' => $request->user()->id, 'device_id' => $device->id, 'policy' => $policies->show($request->user(), $device)]);
    }

    public function savePolicy(Request $request, Device $device, EndpointRemotePolicy $policies): JsonResponse
    {
        return response()->json(['actor_id' => $request->user()->id, 'device_id' => $device->id, 'policy' => $policies->save($request->user(), $device, $request->json()->all())]);
    }

    public function options(Request $request, Device $device, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->connectionOptions($request->user(), $device));
    }

    public function store(Request $request, Device $device, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->request($request->user(), $device, $request->json()->all()), 201);
    }

    public function show(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->observe($request->user(), $session));
    }

    public function stop(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->stop($request->user(), $session));
    }

    public function offer(Request $request, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->offer($request->bearerToken() ?? ''));
    }

    public function decide(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->decide($request->bearerToken() ?? '', $session, $request->json()->all()));
    }

    public function lease(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->lease($request->bearerToken() ?? '', $session, $request->json()->all()));
    }

    public function technicianMedia(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->technicianMedia($request->user(), $session));
    }

    public function mediaAnswer(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->technicianMedia($request->user(), $session, $request->json()->all()));
    }

    public function agentMedia(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->agentMedia($request->bearerToken() ?? '', $session, $request->query()));
    }

    public function mediaOffer(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->agentMedia($request->bearerToken() ?? '', $session, $request->json()->all(), true));
    }

    public function agentRelay(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->agentRelay($request->bearerToken() ?? '', $session, $request->query()));
    }

    public function technicianRelay(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->technicianRelay($request->user(), $session));
    }

    public function agentStop(Request $request, string $session, EndpointRemoteSessions $sessions): JsonResponse
    {
        return response()->json($sessions->agentStop($request->bearerToken() ?? '', $session));
    }
}
