<?php

use App\Http\Middleware\ProtectRemoteSupportResponses;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes the configured HTTPS deployment origin for remote-support transport', function () {
    config()->set('endpoint_management.server_url', 'https://LOCALHOST:443/');
    $middleware = new ProtectRemoteSupportResponses;
    foreach (['/it/remote-support/devices/1/policy', '/api/endpoints/agent/remote/offer'] as $path) {
        $response = $middleware->handle(Request::create('https://localhost'.$path), fn () => response()->json(['ok' => true]));
        expect($response->getStatusCode())->toBe(200)
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    foreach (['http://localhost', 'https://elsewhere.example.test', 'https://localhost:8443'] as $origin) {
        expect(fn () => $middleware->handle(Request::create($origin.'/api/endpoints/agent/remote/offer'), fn () => response()->json(['ok' => true])))
            ->toThrow(HttpException::class);
    }
});
