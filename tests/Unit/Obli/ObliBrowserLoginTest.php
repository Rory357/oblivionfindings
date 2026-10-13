<?php

use App\Domain\Obli\ObliSignIn;
use App\Http\Middleware\ProtectObliResponses;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

it('lets a signed-out native browser authorization use the normal Laravel login redirect', function () {
    $request = Request::create('https://care.example.test/obli/authorize?state=public-state', 'GET');
    expect(ProtectObliResponses::render(new AuthenticationException, $request))->toBeNull();
});

it('normalizes deployment origins consistently with the native HTTPS client', function () {
    expect(ObliSignIn::canonicalIssuer('https://CARE.example.test:443/'))->toBe('https://care.example.test')
        ->and(ObliSignIn::canonicalIssuer('https://CARE.example.test:8443'))->toBe('https://care.example.test:8443')
        ->and(ObliSignIn::canonicalIssuer('https://[2001:DB8::1]/'))->toBe('https://[2001:db8::1]');
});
