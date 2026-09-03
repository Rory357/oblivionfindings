<?php

use App\Domain\Hr\Exceptions\UnsafeWebhookDestination;
use App\Domain\Hr\Services\HrWebhookDestinationPolicy;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Services\CidrMatcher;

final class HrWebhookPolicyDnsResolver implements DnsResolver
{
    /** @var array<string, list<list<string>>> */
    private array $answers;

    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, list<string>|list<list<string>>> $answers */
    public function __construct(array $answers = [])
    {
        $this->answers = [];

        foreach ($answers as $host => $answer) {
            $this->answers[$host] = isset($answer[0]) && is_array($answer[0])
                ? $answer
                : [$answer];
        }
    }

    public function resolve(string $host): array
    {
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;

        return array_shift($this->answers[$host]) ?? [];
    }
}

/** @return array{HrWebhookDestinationPolicy, HrWebhookPolicyDnsResolver} */
function hrWebhookDestinationPolicy(array $answers = []): array
{
    $resolver = new HrWebhookPolicyDnsResolver($answers);

    return [new HrWebhookDestinationPolicy(new CidrMatcher, $resolver), $resolver];
}

it('rejects non-https and special-use IPv4 and IPv6 literals', function (string $url) {
    [$policy] = hrWebhookDestinationPolicy();

    expect(fn () => $policy->authorize($url))
        ->toThrow(UnsafeWebhookDestination::class, 'Webhook destination is not approved.');
})->with([
    'unencrypted public URL' => 'http://93.184.216.34/webhook',
    'unspecified IPv4' => 'https://0.0.0.0/webhook',
    'localhost' => 'https://localhost/webhook',
    'private IPv4 10.x' => 'https://10.1.2.3/webhook',
    'private IPv4 172.16.x' => 'https://172.16.0.1/webhook',
    'private IPv4 192.168.x' => 'https://192.168.1.1/webhook',
    'loopback IPv4' => 'https://127.0.0.1/webhook',
    'link-local IPv4' => 'https://169.254.169.254/webhook',
    'multicast IPv4' => 'https://224.0.0.1/webhook',
    'documentation IPv4' => 'https://192.0.2.10/webhook',
    'unspecified IPv6' => 'https://[::]/webhook',
    'loopback IPv6' => 'https://[::1]/webhook',
    'IPv4-compatible loopback IPv6' => 'https://[::127.0.0.1]/webhook',
    'mapped loopback IPv6' => 'https://[::ffff:127.0.0.1]/webhook',
    'NAT64 translated IPv6' => 'https://[64:ff9b::7f00:1]/webhook',
    '6to4 translated IPv6' => 'https://[2002:7f00:1::1]/webhook',
    'unique-local IPv6' => 'https://[fd00::1]/webhook',
    'deprecated site-local IPv6' => 'https://[fec0::1]/webhook',
    'link-local IPv6' => 'https://[fe80::1]/webhook',
    'multicast IPv6' => 'https://[ff02::1]/webhook',
    'documentation IPv6' => 'https://[2001:db8::1]/webhook',
]);

it('rejects ambiguous host forms and credential-bearing URLs', function (string $url) {
    [$policy] = hrWebhookDestinationPolicy();

    expect(fn () => $policy->authorize($url))
        ->toThrow(UnsafeWebhookDestination::class, 'Webhook destination is not approved.');
})->with([
    'integer IPv4' => 'https://2130706433/webhook',
    'octal IPv4' => 'https://0177.0.0.1/webhook',
    'hex IPv4' => 'https://0x7f.0.0.1/webhook',
    'encoded host' => 'https://%31%32%37.0.0.1/webhook',
    'userinfo' => 'https://user:password@hooks.example.test/webhook',
    'backslash authority' => 'https://hooks.example.test\\@127.0.0.1/webhook',
    'fragment' => 'https://hooks.example.test/webhook#token',
    'credential query' => 'https://hooks.example.test/webhook?access_token=secret',
]);

it('canonicalizes an approved public endpoint and pins its complete DNS answer set', function () {
    [$policy, $resolver] = hrWebhookDestinationPolicy([
        'hooks.example.test' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'],
    ]);

    $target = $policy->authorize('https://Hooks.Example.Test.:443/hr/events?kind=leave');

    expect($target->url)->toBe('https://hooks.example.test/hr/events?kind=leave')
        ->and($target->host)->toBe('hooks.example.test')
        ->and($target->port)->toBe(443)
        ->and($target->addresses)->toBe(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])
        ->and($target->curlResolveEntry())->toBe('hooks.example.test:443:93.184.216.34')
        ->and($resolver->calls)->toBe(['hooks.example.test' => 1]);
});

it('rejects the complete hostname when any DNS answer is not public', function () {
    [$policy, $resolver] = hrWebhookDestinationPolicy([
        'mixed.example.test' => ['93.184.216.34', '10.20.30.40'],
    ]);

    expect(fn () => $policy->authorize('https://mixed.example.test/webhook'))
        ->toThrow(UnsafeWebhookDestination::class, 'Webhook destination is not approved.')
        ->and($resolver->calls)->toBe(['mixed.example.test' => 1]);
});

it('re-resolves on every authorization and rejects a rebound private answer', function () {
    [$policy, $resolver] = hrWebhookDestinationPolicy([
        'rebind.example.test' => [
            ['93.184.216.34'],
            ['127.0.0.1'],
        ],
    ]);

    expect($policy->authorize('https://rebind.example.test/webhook')->addresses)
        ->toBe(['93.184.216.34']);
    expect(fn () => $policy->authorize('https://rebind.example.test/webhook'))
        ->toThrow(UnsafeWebhookDestination::class, 'Webhook destination is not approved.')
        ->and($resolver->calls)->toBe(['rebind.example.test' => 2]);
});

it('reauthorizes same-origin redirects and denies private or cross-origin hops', function () {
    [$policy, $resolver] = hrWebhookDestinationPolicy([
        'hooks.example.test' => [
            ['93.184.216.34'],
            ['93.184.216.34'],
            ['127.0.0.1'],
        ],
    ]);

    $target = $policy->authorize('https://hooks.example.test/start');
    expect($policy->authorizeRedirect($target, '/next')->url)
        ->toBe('https://hooks.example.test/next');
    expect(fn () => $policy->authorizeRedirect($target, '/private-after-rebind'))
        ->toThrow(UnsafeWebhookDestination::class, 'Webhook destination is not approved.');
    expect(fn () => $policy->authorizeRedirect($target, 'https://private.example.test/metadata'))
        ->toThrow(UnsafeWebhookDestination::class, 'Webhook redirect is not approved.');
    expect(fn () => $policy->authorizeRedirect($target, 'https://other.example.test/webhook'))
        ->toThrow(UnsafeWebhookDestination::class, 'Webhook redirect is not approved.')
        ->and($resolver->calls)->toBe([
            'hooks.example.test' => 3,
        ]);
});
