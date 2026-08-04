<?php

namespace App\Http\Middleware;

use App\Domain\Monitoring\Services\CollectorTransportAuthenticator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuthenticateMonitoringCollector
{
    public function __construct(private CollectorTransportAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $collector = $this->authenticator->authenticate($request);
        } catch (Throwable) {
            return new JsonResponse(['message' => 'Collector authentication failed.'], 401);
        }
        $request->attributes->set('monitoring_collector', $collector);

        return $next($request);
    }
}
