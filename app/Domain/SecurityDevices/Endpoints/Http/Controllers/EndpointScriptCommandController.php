<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptCommands;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EndpointScriptCommandController extends Controller
{
    public function poll(Request $request, EndpointScriptCommands $commands)
    {
        abort_unless($request->getQueryString() === null && $request->getContent() === '', 400);

        try {
            return response()->json($commands->poll($request->bearerToken() ?? ''));
        } catch (InvalidArgumentException) {
            abort(409);
        }
    }

    public function cancel(Request $request, Device $device, DeviceCommandRequest $command, EndpointScriptCommands $commands)
    {
        abort_unless($request->isSecure() && (string) $request->server('QUERY_STRING', '') === '', 400);
        abort_unless(strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) === 'application/json', 415);
        try {
            $input = EndpointScriptContract::decode($request->getContent(), 8192);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid cancellation request.');
        }
        try {
            $result = $commands->cancel($request->user(), $device, $command, $input);
        } catch (InvalidArgumentException) {
            abort(409);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function cancellation(Request $request, Device $device, DeviceCommandRequest $command, string $request_key, EndpointScriptCommands $commands)
    {
        abort_unless($request->isSecure() && $request->getContent() === '', 400);
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/\Aexpected_actor_id=([1-9][0-9]{0,15})\z/', $raw, $matches) === 1, 400);
        $actorId = (int) $matches[1];
        abort_unless($actorId <= 9007199254740991, 400);
        try {
            $result = $commands->cancellation($request->user(), $device, $command, $request_key, $actorId);
        } catch (InvalidArgumentException) {
            abort(409);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function output(Request $request, string $attempt, EndpointScriptCommands $commands)
    {
        abort_unless((string) $request->server('QUERY_STRING', '') === '', 400);
        abort_unless(strtolower(trim((string) $request->header('Content-Type'))) === 'application/octet-stream', 415);
        abort_if(strlen($request->getContent()) > 65536, 413);
        $encoded = (string) $request->header('X-Endpoint-Script-Output');
        $raw = base64_decode($encoded, true);
        abort_unless(strlen($encoded) <= 2048 && $raw !== false && base64_encode($raw) === $encoded, 422);
        try {
            $header = EndpointScriptContract::decode($raw, 1024);
        } catch (InvalidArgumentException) {
            abort(422);
        }
        try {
            return response()->json($commands->output($request->bearerToken() ?? '', $attempt, $header, $request->getContent()));
        } catch (InvalidArgumentException) {
            abort(409);
        }
    }

    public function outputReceipt(Request $request, Device $device, DeviceCommandRequest $command, string $attempt, EndpointScriptCommands $commands)
    {
        return $this->outputResponse($request, $device, $command, $attempt, $commands, false);
    }

    public function outputContent(Request $request, Device $device, DeviceCommandRequest $command, string $attempt, EndpointScriptCommands $commands)
    {
        return $this->outputResponse($request, $device, $command, $attempt, $commands, true);
    }

    private function outputResponse(Request $request, Device $device, DeviceCommandRequest $command, string $attempt, EndpointScriptCommands $commands, bool $content)
    {
        abort_unless($request->isSecure() && $request->getContent() === '', 400);
        abort_unless(preg_match('/\Aexpected_actor_id=([1-9][0-9]{0,15})\z/', (string) $request->server('QUERY_STRING', ''), $matches) === 1, 400);
        abort_unless((int) $matches[1] <= 9007199254740991, 400);
        try {
            $data = $commands->readOutput($request->user(), $device, $command, $attempt, (int) $matches[1], $content);
        } catch (InvalidArgumentException) {
            abort(409);
        }
        if (! $content) {
            return response()->json($data);
        }
        // The owner transaction commits content erasure before this expired response.
        abort_unless($data['content_available'], 410);

        return response($data['bytes'], 200, ['Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Length' => (string) strlen($data['bytes']), 'Content-Disposition' => 'attachment; filename="script-output.txt"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function artifact(Request $request, string $attempt, EndpointScriptCommands $commands)
    {
        abort_unless($request->getQueryString() === null && $request->getContent() === '', 400);
        try {
            $bytes = $commands->artifact($request->bearerToken() ?? '', $attempt);
        } catch (CommandDispatchPreconditionException|InvalidArgumentException) {
            abort(409);
        }

        return response($bytes, 200, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="reviewed-script.ps1"', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store']);
    }

    public function result(Request $request, string $attempt, EndpointScriptCommands $commands)
    {
        abort_unless($request->getQueryString() === null, 400);
        abort_unless($request->isJson(), 415);
        try {
            $input = EndpointScriptContract::decode($request->getContent(), 8192);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid script result.');
        }
        try {
            return response()->json($commands->result($request->bearerToken() ?? '', $attempt, $input));
        } catch (InvalidArgumentException) {
            abort(409);
        }
    }

    public function claim(Request $request, string $attempt, EndpointScriptCommands $commands)
    {
        abort_unless($request->getQueryString() === null, 400);
        abort_unless($request->isJson(), 415);
        try {
            $input = EndpointScriptContract::decode($request->getContent(), 8192);
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid script claim.');
        }
        try {
            return response()->json($commands->claim($request->bearerToken() ?? '', $attempt, $input));
        } catch (CommandDispatchPreconditionException|InvalidArgumentException) {
            abort(409);
        }
    }
}
