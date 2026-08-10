<?php

use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\NativeDnsResolver;

it('returns a bounded canonical unique address set', function () {
    $resolver = new NativeDnsResolver(fn (string $host, int $type): array => [
        ['host' => $host, 'type' => 'AAAA', 'ipv6' => '2001:0db8:0000:0000:0000:0000:0000:0010'],
        ['host' => $host, 'type' => 'A', 'ip' => '10.77.4.5'],
        ['host' => $host, 'type' => 'A', 'ip' => '10.77.4.5'],
    ]);

    expect($resolver->resolve('access.example.test'))->toBe([
        '10.77.4.5',
        '2001:db8::10',
    ]);
});

it('fails closed on malformed or excessive DNS answers', function (array $records, string $reason) {
    $resolver = new NativeDnsResolver(fn (string $host, int $type): array => $records);

    expect(fn () => $resolver->resolve('access.example.test'))
        ->toThrow(EgressDenied::class, $reason);
})->with([
    'malformed address' => [
        [['type' => 'A', 'ip' => 'not-an-address']],
        'DNS resolution returned a malformed address',
    ],
    'too many addresses' => [
        array_map(fn (int $last): array => ['type' => 'A', 'ip' => '10.77.4.'.$last], range(1, 17)),
        'DNS resolution returned no usable addresses',
    ],
]);
