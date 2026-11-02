<?php

use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\WebPages\WebFetcher;
use Oblivion\Collector\WebPages\WebLane;

/*
 * S&D Phase G (pop-up 61): the hub side of a device's web page. Only Oblivion-signed requests are
 * fetched, only from private addresses, https only with the trusted key, and the device's cookies
 * stay with the hub.
 */

final class FakeWebFetcher implements WebFetcher
{
    public array $calls = [];

    /** @param list<array{status: int, headers: array<string, list<string>>, body: string, truncated: bool}> $answers */
    public function __construct(public array $answers = []) {}

    public function fetch(string $url, string $method, array $headers, string $body, ?string $pin, int $maxBytes): array
    {
        $this->calls[] = compact('url', 'method', 'headers', 'body', 'pin');

        return array_shift($this->answers) ?? ['status' => 200, 'headers' => [], 'body' => '', 'truncated' => false];
    }
}

function webKeys(): string
{
    static $pair;

    return $pair ??= sodium_crypto_sign_seed_keypair(str_repeat("\x39", SODIUM_CRYPTO_SIGN_SEEDBYTES));
}

/** @return array<string, mixed> */
function webRequest(string $uuid, array $details = [], array $overrides = [], bool $sign = true): array
{
    $details = array_replace([
        'session' => '5a6b7c8d-9e0f-4a1b-8c2d-3e4f5a6b7c8d',
        'scheme' => 'http',
        'address' => '10.44.0.50',
        'port' => 80,
        'trusted_pin' => null,
        'expires_at' => '2026-07-23T12:02:00+00:00',
    ], $details);
    $details['signature'] = $sign
        ? base64_encode(sodium_crypto_sign_detached(WebLane::canonical($details), sodium_crypto_sign_secretkey(webKeys())))
        : base64_encode(str_repeat("\0", 64));

    return array_replace(['uuid' => $uuid, 'details' => $details, 'method' => 'GET', 'path' => '/status.html', 'headers' => ['accept' => 'text/html', 'x-evil' => 'no'], 'body_base64' => null], $overrides);
}

/** @param list<array<string, mixed>> $responses @param list<array<string, mixed>> $reports */
function webLane(array &$responses, array &$reports, FakeWebFetcher $fetcher, float &$now): WebLane
{
    return new WebLane(
        function (array $report) use (&$responses, &$reports): array {
            $reports[] = $report;

            return array_shift($responses) ?? ['requests' => [], 'closed' => []];
        },
        new EnvelopeVerifier(sodium_crypto_sign_publickey(webKeys())),
        $fetcher,
        function () use (&$now): float {
            return $now;
        },
    );
}

it('fetches nothing Oblivion didn’t sign', function () {
    $responses = [['requests' => [webRequest('a1b2c3d4-0000-4000-8000-000000000001', [], [], false)], 'closed' => []]];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $fetcher = new FakeWebFetcher;
    $lane = webLane($responses, $reports, $fetcher, $now);

    $lane->tick();
    $now += 1;
    $lane->tick();

    expect($fetcher->calls)->toBe([])
        ->and($reports[1]['responses'])->toBe([]);
});

it('fetches a signed page, passes only safe headers, and keeps the device’s cookies on the hub', function () {
    $responses = [
        ['requests' => [webRequest('a1b2c3d4-0000-4000-8000-000000000002')], 'closed' => []],
        ['requests' => [webRequest('a1b2c3d4-0000-4000-8000-000000000003', [], ['path' => '/settings'])], 'closed' => []],
    ];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $fetcher = new FakeWebFetcher([
        ['status' => 200, 'headers' => ['content-type' => ['text/html'], 'set-cookie' => ['SID=abc123; Path=/; HttpOnly'], 'server' => ['lighttpd']], 'body' => '<h1>Printer</h1>', 'truncated' => false],
        ['status' => 200, 'headers' => ['content-type' => ['text/html']], 'body' => 'ok', 'truncated' => false],
    ]);
    $lane = webLane($responses, $reports, $fetcher, $now);

    $lane->tick();
    $now += 0.3;
    $lane->tick();

    expect($fetcher->calls[0]['url'])->toBe('http://10.44.0.50/status.html')
        ->and($fetcher->calls[0]['headers'])->toBe(['accept' => 'text/html'])
        ->and($fetcher->calls[0]['pin'])->toBeNull()
        ->and($fetcher->calls[1]['headers']['cookie'])->toBe('SID=abc123')
        ->and($reports[1]['responses'][0]['status'])->toBe(200)
        ->and($reports[1]['responses'][0]['headers'])->toBe(['content-type' => 'text/html'])
        ->and(base64_decode($reports[1]['responses'][0]['body_base64']))->toBe('<h1>Printer</h1>')
        ->and(json_encode($reports))->not->toContain('abc123');
});

it('refuses a public address and https without a trusted key, before fetching', function (array $details) {
    $responses = [['requests' => [webRequest('a1b2c3d4-0000-4000-8000-000000000004', $details)], 'closed' => []]];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $fetcher = new FakeWebFetcher;
    $lane = webLane($responses, $reports, $fetcher, $now);

    $lane->tick();
    $now += 1;
    $lane->tick();

    expect($fetcher->calls)->toBe([])
        ->and($reports[1]['responses'][0]['failure'])->toBe('unreachable');
})->with([
    'a public address' => [['address' => '8.8.8.8']],
    'https without a key' => [['scheme' => 'https', 'port' => 443]],
]);

it('uses the trusted key for https, and stops at 3 MB', function () {
    $pin = 'sha256//'.str_repeat('C', 43).'=';
    $responses = [['requests' => [webRequest('a1b2c3d4-0000-4000-8000-000000000005', ['scheme' => 'https', 'port' => 8443, 'trusted_pin' => $pin])], 'closed' => []]];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $fetcher = new FakeWebFetcher([['status' => 200, 'headers' => [], 'body' => '', 'truncated' => true]]);
    $lane = webLane($responses, $reports, $fetcher, $now);

    $lane->tick();
    $now += 1;
    $lane->tick();

    expect($fetcher->calls[0]['url'])->toBe('https://10.44.0.50:8443/status.html')
        ->and($fetcher->calls[0]['pin'])->toBe($pin)
        ->and($reports[1]['responses'][0]['failure'])->toBe('too_large');
});
