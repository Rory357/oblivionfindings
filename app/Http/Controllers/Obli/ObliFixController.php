<?php

namespace App\Http\Controllers\Obli;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\Obli\Fixes\ObliFixEngine;
use App\Domain\Obli\Fixes\ObliOfflineReports;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * S&D Phase I: the tray's fix-session calls (sd-obli-fix-protocol.md), with the person's Obli
 * token. Every answer is no-store. The engine checks the session, the computer and the limits.
 */
final class ObliFixController
{
    public function store(Request $request, ObliFixEngine $engine): JsonResponse
    {
        return $this->answer(function () use ($engine, $request): array {
            try {
                return $engine->start($request->bearerToken(), $request->json()->all());
            } catch (QueryException $error) {
                // Two starts with the same request_uuid at once: the loser's insert clashes (or
                // deadlocks). The first one's session now exists, so asking again returns it.
                if (! in_array((string) $error->getCode(), ['23000', '40001'], true)) {
                    throw $error;
                }

                return $engine->start($request->bearerToken(), $request->json()->all());
            }
        }, 201);
    }

    public function show(Request $request, string $uuid, ObliFixEngine $engine): JsonResponse
    {
        return $this->answer(fn () => $engine->show($request->bearerToken(), $uuid));
    }

    public function approve(Request $request, string $uuid, ObliFixEngine $engine): JsonResponse
    {
        return $this->answer(fn () => $engine->approve($request->bearerToken(), $uuid, $request->json()->all()));
    }

    public function turn(Request $request, string $uuid, ObliFixEngine $engine): JsonResponse
    {
        return $this->answer(fn () => $engine->turn($request->bearerToken(), $uuid, $request->json()->all()));
    }

    public function stop(Request $request, string $uuid, ObliFixEngine $engine): JsonResponse
    {
        return $this->answer(fn () => $engine->stop($request->bearerToken(), $uuid, $request->json()->all()));
    }

    public function resume(Request $request, string $uuid, ObliFixEngine $engine): JsonResponse
    {
        return $this->answer(fn () => $engine->resume($request->bearerToken(), $uuid));
    }

    public function ticket(Request $request, string $uuid, ObliFixEngine $engine): JsonResponse
    {
        return $this->answer(fn () => $engine->ticket($request->bearerToken(), $uuid));
    }

    public function cancelRequest(Request $request, string $uuid, ObliFixEngine $engine): JsonResponse
    {
        return $this->answer(fn () => $engine->cancelRequest($request->bearerToken(), $uuid));
    }

    public function offlineReport(Request $request, ObliOfflineReports $reports): JsonResponse
    {
        return $this->answer(fn () => $reports->store($request->bearerToken(), $request->json()->all()), 201);
    }

    private function answer(Closure $operation, int $status = 200): JsonResponse
    {
        try {
            $data = $operation();
        } catch (ValidationException) {
            return $this->json(['code' => 'invalid_request', 'message' => 'Review the request before sending it again.'], 422);
        } catch (ItTicketCommandConflict) {
            return $this->json(['code' => 'conflict', 'message' => 'Keep the original request identity.'], 409);
        } catch (HttpExceptionInterface $error) {
            $code = $error->getStatusCode();
            $words = [401 => 'Sign in to Obli again.', 403 => 'You can’t ask Obli to fix things.', 404 => 'Not found.', 409 => 'This fix changed on the server.', 410 => 'Gone.', 422 => 'Review the request before sending it again.', 429 => 'Too many requests.', 503 => 'Obli’s fixes aren’t available right now.'];

            return $this->json(['code' => 'http_'.$code, 'message' => $words[$code] ?? 'Not confirmed.'], $code, $error->getHeaders());
        }

        return $this->json($data, $status);
    }

    private function json(array $data, int $status, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, [...$headers, 'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
