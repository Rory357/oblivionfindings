<?php

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\SecurityDevices\Management\Transports\NativeCommandHttpTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function commandTransportTarget(string $scheme = 'https'): AuthorizedProbeTarget
{
    return AuthorizedProbeTarget::fromEgressPolicy(
        siteId: 9004,
        deviceId: 77,
        scheme: $scheme,
        host: 'access.example.test',
        port: 12445,
        path: '/api/v1/developer/doors/0ed545f8-2fcd-4839-9021-b39e707f6aa9/unlock',
        addresses: ['10.77.4.5', '10.77.4.6'],
        connectTimeoutSeconds: 2,
        responseTimeoutSeconds: 5,
        maxResponseBytes: 65_536,
    );
}

it('does not fail over a side-effecting command after an ambiguous transport failure', function () {
    $handler = new MockHandler([
        new ConnectException('response lost after write', new Request('PUT', 'https://access.example.test')),
        new Response(200, [], '{"code":"SUCCESS"}'),
    ]);
    $transport = new NativeCommandHttpTransport(new Client([
        'handler' => HandlerStack::create($handler),
    ]));

    expect(fn () => $transport->request(
        commandTransportTarget(),
        'PUT',
        [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer SAFE-TEST-TOKEN',
            'Content-Type' => 'application/json',
        ],
        ['actor_id' => 'command-uuid'],
    ))->toThrow(ConnectException::class)
        ->and($handler->count())->toBe(1);
});

it('rejects a non-TLS command target before transport', function () {
    $handler = new MockHandler([new Response(200, [], '{"code":"SUCCESS"}')]);
    $transport = new NativeCommandHttpTransport(new Client([
        'handler' => HandlerStack::create($handler),
    ]));

    expect(fn () => $transport->request(
        commandTransportTarget('http'),
        'GET',
        [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer SAFE-TEST-TOKEN',
            'Content-Type' => 'application/json',
        ],
    ))->toThrow(RuntimeException::class, 'Command HTTP request is invalid.')
        ->and($handler->count())->toBe(1);
});
