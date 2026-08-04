<?php

use App\Domain\Monitoring\Protocols\Syslog\SyslogDecoder;
use Carbon\CarbonImmutable;

it('parses RFC5424 into an allowlisted bounded event', function () {
    $receivedAt = CarbonImmutable::parse('2026-07-23T03:34:56Z');
    $datagram = trim(file_get_contents(dirname(__DIR__, 2).'/fixtures/monitoring/syslog/rfc5424.log'));

    $event = (new SyslogDecoder)->decode($datagram, $receivedAt);
    $payload = $event->payload();

    expect($payload)->toMatchArray([
        'format' => 'rfc5424',
        'facility' => 4,
        'severity_code' => 2,
        'hostname' => 'edge-01',
        'app' => 'sshd',
        'process_id' => '4321',
        'message_id' => 'AUTH42',
        'message' => 'Login failed for alice',
        'structured_data' => [
            'auth@32473' => ['result' => 'failed', 'user' => 'alice'],
        ],
    ])->and($payload['occurred_at'])->toBe('2026-07-23T03:34:55.123000Z')
        ->and($payload['raw_hash'])->toBe(hash('sha256', $datagram))
        ->and($payload)->not->toHaveKey('raw_datagram')
        ->and(array_keys($payload))->toBe([
            'app',
            'facility',
            'format',
            'hostname',
            'message',
            'message_id',
            'occurred_at',
            'process_id',
            'raw_hash',
            'severity_code',
            'structured_data',
        ]);
});

it('parses RFC3164 with the nearest safe year', function () {
    $event = (new SyslogDecoder)->decode(
        trim(file_get_contents(dirname(__DIR__, 2).'/fixtures/monitoring/syslog/rfc3164.log')),
        CarbonImmutable::parse('2026-07-23T15:05:10Z'),
    );

    expect($event->payload())->toMatchArray([
        'format' => 'rfc3164',
        'facility' => 20,
        'severity_code' => 5,
        'hostname' => 'edge-02',
        'app' => 'dnsmasq',
        'process_id' => '77',
        'message' => 'query[A] example.test from 10.44.0.20',
        'occurred_at' => '2026-07-23T15:05:07.000000Z',
    ]);
});

it('scrubs invalid UTF-8 newline injection and bounds the message', function () {
    $datagram = '<11>1 2026-07-23T03:34:55Z edge-01 app - - - '.str_repeat('x', 5000)."\r\nforged\xffline";
    $event = (new SyslogDecoder)->decode($datagram, CarbonImmutable::parse('2026-07-23T03:34:56Z'));

    expect(strlen($event->message))->toBeLessThanOrEqual(4096)
        ->and($event->message)->not->toContain("\r", "\n", "\xff")
        ->and($event->rawHash)->toBe(hash('sha256', $datagram));
});

it('rejects malformed priorities oversize datagrams and unsafe timestamp skew', function (string $datagram, string $message) {
    expect(fn () => (new SyslogDecoder)->decode(
        $datagram,
        CarbonImmutable::parse('2026-07-23T03:34:56Z'),
    ))->toThrow(RuntimeException::class, $message);
})->with([
    'priority' => ['<192>1 2026-07-23T03:34:55Z edge app - - - invalid', 'priority is invalid'],
    'oversize' => [str_repeat('x', 8193), 'exceeds the configured limit'],
    'future skew' => ['<34>1 2026-07-24T03:34:55Z edge app - - - future', 'timestamp is outside the accepted window'],
    'past skew' => ['<34>1 2026-07-20T03:34:55Z edge app - - - old', 'timestamp is outside the accepted window'],
]);
