<?php

use App\Domain\It\Services\ItTicketRequestTrace;
use App\Http\Middleware\TraceItTicketCreation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Response;

function itTraceTestLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = $context;
        }
    };
}

test('ticket trace correlates distinct commit and deferred dispatch stages without request content', function () {
    $logger = itTraceTestLogger();
    $request = Request::create('/it/tickets?private_query=DO_NOT_LOG', 'POST', [
        'title' => 'PRIVATE_TITLE', 'description' => 'PRIVATE_DESCRIPTION',
        'request_uuid' => 'PRIVATE_COMMAND_ID',
    ], server: ['HTTP_X_IT_REQUEST_ID' => 'FORGED_PRIVATE_HEADER']);
    $response = (new TraceItTicketCreation($logger))->handle($request, function (Request $request): Response {
        $trace = ItTicketRequestTrace::from($request);
        $trace->mark('validated');
        $trace->mark('committed');
        $trace->mark('dispatch_deferred');
        $trace->mark('PRIVATE_UNAPPROVED_STAGE');

        return new Response('', 201);
    });

    $id = $response->headers->get(ItTicketRequestTrace::HEADER);
    expect(Str::isUuid($id))->toBeTrue()
        ->and(array_unique(array_column($logger->records, 'correlation_id')))->toBe([$id])
        ->and(array_column($logger->records, 'stage'))->toBe(['started', 'validated', 'committed', 'dispatch_deferred', 'completed'])
        ->and($logger->records[4]['http_status'])->toBe(201)
        ->and(json_encode($logger->records))->not->toContain('PRIVATE', 'DO_NOT_LOG', 'FORGED')
        ->and($response->headers->get('Server-Timing'))->toStartWith('it_request;dur=');
});

test('trace uses monotonic stage durations and only completes once', function () {
    $logger = itTraceTestLogger();
    $nanoseconds = 1_000_000;
    $trace = new ItTicketRequestTrace($logger, function () use (&$nanoseconds): int {
        return $nanoseconds;
    });
    $nanoseconds += 5_000_000;
    $trace->mark('validated');
    $nanoseconds += 2_000_000;
    $trace->mark('committed');
    $response = new Response('', 201);
    $trace->finish($response);
    $trace->finish($response);
    $trace->mark('dispatch_failed');

    expect($logger->records[1]['elapsed_ms'])->toBe(5.0)
        ->and($logger->records[2]['elapsed_ms'])->toBe(7.0)
        ->and($logger->records[2]['since_previous_stage_ms'])->toBe(2.0)
        ->and(array_column($logger->records, 'stage'))->toBe(['started', 'validated', 'committed', 'completed'])
        ->and($response->headers->all('server-timing'))->toHaveCount(1);
});

test('thrown exceptions remain unchanged and completion uses the actual rendered status', function () {
    $logger = itTraceTestLogger();
    $request = Request::create('/it/tickets', 'POST');
    $exception = new AuthenticationException('PRIVATE_AUTH_MESSAGE');
    $caught = null;
    try {
        (new TraceItTicketCreation($logger))->handle($request, function () use ($exception): never {
            throw $exception;
        });
    } catch (Throwable $failure) {
        $caught = $failure;
    }

    expect($caught)->toBe($exception)
        ->and($logger->records[1]['stage'])->toBe('interrupted')
        ->and($logger->records[1])->not->toHaveKey('http_status');

    // The exception hook receives Laravel's redirect, not an invented 401.
    $response = ItTicketRequestTrace::from($request)->finish(new Response('', 302), $exception);
    expect($logger->records[2]['http_status'])->toBe(302)
        ->and($logger->records[2]['failure_category'])->toBe('authentication')
        ->and($response->headers->has(ItTicketRequestTrace::HEADER))->toBeTrue()
        ->and(json_encode($logger->records))->not->toContain('PRIVATE_AUTH_MESSAGE');
});

test('unrelated routes are untouched and every intake request gets independent state', function () {
    $logger = itTraceTestLogger();
    $middleware = new TraceItTicketCreation($logger);
    $existing = new Response('unchanged', 200);
    foreach ([['GET', '/it/tickets'], ['POST', '/sites'], ['GET', '/it/tickets/1']] as [$method, $path]) {
        expect($middleware->handle(Request::create($path, $method), fn () => $existing))->toBe($existing);
    }
    expect($logger->records)->toBe([])
        ->and($existing->headers->has(ItTicketRequestTrace::HEADER))->toBeFalse();

    $one = $middleware->handle(Request::create('/it/tickets', 'POST'), fn () => new Response('', 201));
    $two = $middleware->handle(Request::create('/it/tickets', 'POST'), fn () => new Response('', 422));
    expect($one->headers->get(ItTicketRequestTrace::HEADER))->not->toBe($two->headers->get(ItTicketRequestTrace::HEADER));
});

test('a logging failure cannot replace the committed response or application exception', function () {
    $logger = new class extends AbstractLogger
    {
        public function log($level, string|Stringable $message, array $context = []): void
        {
            throw new RuntimeException('PRIVATE_LOG_FAILURE');
        }
    };
    $middleware = new TraceItTicketCreation($logger);
    $response = $middleware->handle(Request::create('/it/tickets', 'POST'), fn () => new Response('', 201));
    expect($response->getStatusCode())->toBe(201)
        ->and($response->headers->has(ItTicketRequestTrace::HEADER))->toBeTrue();
});
