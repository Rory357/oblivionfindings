<?php

use App\Domain\SecurityDevices\Endpoints\Services\EndpointBootstrap;

it('accepts only configured HTTPS server origins for installation profiles', function () {
    expect(EndpointBootstrap::serverOrigin('https://care.example.nz/'))->toBe('https://care.example.nz')
        ->and(EndpointBootstrap::serverOrigin('https://care.example.nz:8443'))->toBe('https://care.example.nz:8443')
        ->and(EndpointBootstrap::serverOrigin('https://[2001:db8::1]:8443'))->toBe('https://[2001:db8::1]:8443');
    foreach ([
        '', 'http://care.example.nz', ' https://care.example.nz', 'https://care.example.nz ',
        'https://user:password@care.example.nz', 'https://care.example.nz/path',
        'https://care.example.nz//', 'https://care.example.nz/?key=secret', 'https://care.example.nz/?',
        'https://care.example.nz/#fragment', 'https://care.example.nz/#',
        'https://care.example.nz:', 'https://care.example.nz:/', 'https://care.example.nz:0', 'https://care.example.nz:65536',
        "https://care.example.nz\n", 'https://care.example.nz\\other',
    ] as $origin) {
        expect(fn () => EndpointBootstrap::serverOrigin($origin))->toThrow(RuntimeException::class);
    }
});
