<?php

namespace App\Http\Middleware;

use App\Domain\It\Services\ItTicketRequestTrace;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Run before session/auth/CSRF, with an immediate bypass for unrelated traffic. */
final class TraceItTicketCreation
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('it/tickets')) {
            return $next($request);
        }

        $trace = new ItTicketRequestTrace($this->logger);
        $request->attributes->set(ItTicketRequestTrace::class, $trace);

        try {
            return $trace->finish($next($request));
        } catch (Throwable $exception) {
            $trace->interrupted($exception);

            throw $exception;
        }
    }
}
