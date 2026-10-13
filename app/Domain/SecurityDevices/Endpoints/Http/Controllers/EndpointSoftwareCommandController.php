<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareCommands;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EndpointSoftwareCommandController extends Controller
{
    public function poll(Request $request, EndpointSoftwareCommands $commands): JsonResponse
    {
        abort_unless($request->query() === [], 422);

        return response()->json($commands->poll((string) $request->bearerToken()));
    }

    public function claim(Request $request, string $attempt, EndpointSoftwareCommands $commands): JsonResponse
    {
        abort_unless($request->query() === [], 422);
        try {
            return response()->json($commands->claim((string) $request->bearerToken(), $attempt, $request->json()->all()));
        } catch (CommandDispatchPreconditionException) {
            abort(409);
        }
    }

    public function result(Request $request, string $attempt, EndpointSoftwareCommands $commands): JsonResponse
    {
        return response()->json($commands->result((string) $request->bearerToken(), $attempt, $request->json()->all()));
    }

    public function artifact(Request $request, string $attempt, EndpointSoftwareCommands $commands): StreamedResponse
    {
        abort_unless($request->query() === [], 422);
        $download = $commands->artifact($request->bearerToken() ?? '', $attempt);
        $stream = $download['stream'];

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'approved-package.msi', ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $download['size_bytes'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
