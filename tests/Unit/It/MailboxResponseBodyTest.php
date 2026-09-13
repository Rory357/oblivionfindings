<?php

use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\MailboxProviderHttp;
use App\Services\Integration\MailboxResponseBody;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Response as HttpResponse;
use Symfony\Component\Process\Process;

test('mailbox receive sink never stores bytes beyond its boundary', function () {
    $body = new MailboxResponseBody(5);
    expect($body->write('abc'))->toBe(3)
        ->and($body->write('de'))->toBe(2)
        ->and($body->write('f'))->toBe(0)
        ->and($body->getSize())->toBe(5);
    $body->rewind();
    expect($body->write('z'))->toBe(0)->and((string) $body)->toBe('abcde');
    $body->close();
});

test('mailbox receive limits reject invalid server-side configuration', function (int $limit) {
    expect(fn () => new MailboxResponseBody($limit))->toThrow(InvalidArgumentException::class);
})->with([0, -1, MailboxResponseBody::MAX_LIMIT + 1]);

test('mailbox response middleware uses a fresh bounded body for every request and fake handler', function () {
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], 'first'), new Response(200, [], 'next'), new Response(200, [], 'excess')]));
    $stack->push(MailboxResponseBody::middleware(5));
    $client = new Client(['handler' => $stack]);
    expect((string) $client->get('https://synthetic.invalid/first')->getBody())->toBe('first');
    expect((string) $client->get('https://synthetic.invalid/next')->getBody())->toBe('next');
    expect(fn () => $client->get('https://synthetic.invalid/large'))->toThrow(MailboxProviderFailure::class);
});

test('native mailbox transfers enforce decoded byte limits before returning content', function (string $handlerName, string $mode) {
    $server = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Support/It/mailbox-response-server.php', $mode]);
    $server->setTimeout(15);
    $server->start();
    try {
        $ready = '';
        $deadline = microtime(true) + 5;
        do {
            $ready .= $server->getIncrementalOutput();
            if (str_contains($ready, "\n")) {
                break;
            }
            usleep(10000);
        } while ($server->isRunning() && microtime(true) < $deadline);
        $address = trim($ready);
        expect($address)->toMatch('/^127\.0\.0\.1:[1-9][0-9]{0,4}$/D');
        $stack = HandlerStack::create($handlerName === 'curl' ? new CurlHandler : new StreamHandler);
        $stack->push(MailboxResponseBody::middleware(64));
        $client = new Client(['handler' => $stack, 'allow_redirects' => false, 'timeout' => 5, 'decode_content' => true]);
        try {
            $response = MailboxProviderHttp::send(fn () => new HttpResponse($client->get('http://'.$address.'/synthetic')));
            expect($mode)->toBe('exact')->and($response->body())->toBe(str_repeat('x', 64));
        } catch (MailboxProviderFailure $failure) {
            expect($mode)->not->toBe('exact');
            if ($mode === 'truncated') {
                expect($failure->reason)->toBeIn(['invalid_response', 'unavailable']);
            } else {
                expect($failure->reason)->toBe('response_too_large');
            }
            expect($failure->getPrevious())->toBeNull()
                ->and($failure->getMessage())->not->toContain($address, 'synthetic', str_repeat('x', 20));
        }
        $server->wait();
        expect($server->getExitCode())->toBe(0);
    } finally {
        if ($server->isRunning()) {
            $server->stop(1);
        }
    }
})->with(['curl', 'stream'])->with(['exact', 'declared', 'chunked', 'unknown', 'gzip', 'truncated']);
