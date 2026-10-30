<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartCommands;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EndpointRestartCommandController extends Controller
{
    public function poll(Request $request, EndpointRestartCommands $commands): JsonResponse
    {
        abort_unless($request->query() === [], 422);

        return response()->json($commands->poll((string) $request->bearerToken()));
    }

    public function claim(Request $request, string $attempt, EndpointRestartCommands $commands): JsonResponse
    {
        abort_unless($request->query() === [], 422);
        try {
            return response()->json($commands->claim((string) $request->bearerToken(), $attempt, $request->json()->all()));
        } catch (CommandDispatchPreconditionException) {
            abort(409);
        }
    }

    public function result(Request $request, string $attempt, EndpointRestartCommands $commands): JsonResponse
    {
        return response()->json($commands->result((string) $request->bearerToken(), $attempt, $request->json()->all()));
    }

    public function cancel(Request $request, Device $device, DeviceCommandRequest $command, EndpointRestartCommands $commands): RedirectResponse
    {
        $commands->cancel($request->user(), $device, $command, $request->all());

        return back()->with('success', 'Cancellation recorded. A delivered countdown remains pending until the computer confirms it has stopped.');
    }
}
