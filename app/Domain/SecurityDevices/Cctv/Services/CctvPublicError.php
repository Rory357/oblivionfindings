<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CctvPublicError
{
    public static function response(Throwable $exception): JsonResponse
    {
        $status = $exception instanceof ModelNotFoundException ? 404 : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
        $allowed = match ($status) {
            400 => ['invalid_request'],
            401, 403, 404 => ['unavailable'],
            409 => ['context_changed', 'active_source_exists', 'source_unavailable', 'capacity_unavailable'],
            default => ['temporarily_unavailable'],
        };
        // Only closed public codes can survive. Provider/internal exception text
        // never becomes response content, including in development mode.
        $code = $exception instanceof HttpExceptionInterface && in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : $allowed[0];

        return (new JsonResponse(['message' => $code], $status))->header('Cache-Control', 'no-store');
    }
}
