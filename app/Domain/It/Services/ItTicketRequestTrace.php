<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Request-local, content-free timing evidence for browser ticket intake. */
final class ItTicketRequestTrace
{
    public const HEADER = 'X-IT-Request-ID';

    private const STAGES = [
        'validated', 'committed', 'replayed', 'dispatch_started',
        'dispatch_queued', 'dispatch_failed', 'dispatch_deferred',
        'conflict', 'domain_rejected',
    ];

    public readonly string $id;

    private readonly Closure $clock;

    private readonly int $startedAt;

    private int $previousAt;

    private bool $completed = false;

    public function __construct(private readonly LoggerInterface $logger, ?Closure $clock = null)
    {
        $this->id = (string) Str::uuid();
        $this->clock = $clock ?? static fn (): int => hrtime(true);
        $this->startedAt = ($this->clock)();
        $this->previousAt = $this->startedAt;
        $this->record('started');
    }

    public static function from(Request $request): ?self
    {
        $trace = $request->attributes->get(self::class);

        return $trace instanceof self ? $trace : null;
    }

    public function mark(string $stage): void
    {
        if ($this->completed || ! in_array($stage, self::STAGES, true)) {
            return;
        }

        $this->record($stage);
    }

    /** Use Laravel's actual rendered response; validation can legitimately redirect. */
    public function finish(Response $response, ?Throwable $exception = null): Response
    {
        $response->headers->set(self::HEADER, $this->id);
        if ($this->completed) {
            return $response;
        }

        $elapsed = $this->elapsed(($this->clock)() - $this->startedAt);
        $response->headers->set('Server-Timing', 'it_request;dur='.$elapsed, false);
        $this->record('completed', [
            'http_status' => $response->getStatusCode(),
            'failure_category' => $exception === null ? null : $this->failureCategory($exception),
        ]);
        $this->completed = true;

        return $response;
    }

    /** A thrown exception is not an HTTP response and therefore gets no guessed status. */
    public function interrupted(Throwable $exception): void
    {
        if (! $this->completed) {
            $this->record('interrupted', ['failure_category' => $this->failureCategory($exception)]);
        }
    }

    /** @param array{http_status?: int, failure_category?: string|null} $terminal */
    private function record(string $stage, array $terminal = []): void
    {
        $now = ($this->clock)();
        $context = [
            'correlation_id' => $this->id,
            'operation' => 'it_ticket_create',
            'stage' => $stage,
            'elapsed_ms' => $this->elapsed($now - $this->startedAt),
            'since_previous_stage_ms' => $this->elapsed($now - $this->previousAt),
            ...$terminal,
        ];
        $this->previousAt = $now;

        try {
            // No request body, headers, command identity, user/record IDs,
            // SQL, filenames, exception messages or arbitrary caller context.
            $this->logger->info('IT ticket request trace', $context);
        } catch (Throwable) {
            // Diagnostic storage failure must not change the ticket outcome.
        }
    }

    private function elapsed(int $nanoseconds): float
    {
        return round(max(0, $nanoseconds) / 1_000_000, 3);
    }

    private function failureCategory(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ValidationException => 'validation',
            $exception instanceof AuthenticationException => 'authentication',
            $exception instanceof TokenMismatchException => 'session_expired',
            $exception instanceof AuthorizationException => 'authorization',
            $exception instanceof ModelNotFoundException => 'not_found',
            $exception instanceof ItTicketCommandConflict => 'conflict',
            default => 'application_failure',
        };
    }
}
