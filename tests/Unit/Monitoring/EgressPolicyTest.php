<?php

use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\ProbeScopeResolver;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\EgressPolicy;

final class TaskFourDnsResolver implements DnsResolver
{
    /** @var array<string, list<list<string>|Throwable>> */
    private array $answers;

    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, list<string>|list<list<string>|Throwable>> $answers */
    public function __construct(array $answers)
    {
        $this->answers = [];

        foreach ($answers as $host => $answer) {
            $this->answers[$host] = isset($answer[0]) && (is_array($answer[0]) || $answer[0] instanceof Throwable)
                ? $answer
                : [$answer];
        }
    }

    public function resolve(string $host): array
    {
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;
        $answer = array_shift($this->answers[$host]) ?? [];

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}

final class TaskFourScopeResolver implements ProbeScopeResolver
{
    public int $calls = 0;

    public function __construct(public ProbeScope|Throwable $scope) {}

    public function resolve(int $siteId, int $deviceId): ProbeScope
    {
        $this->calls++;

        if ($this->scope instanceof Throwable) {
            throw $this->scope;
        }

        return $this->scope;
    }
}

/** @return array{EgressPolicy, TaskFourDnsResolver, TaskFourScopeResolver} */
function taskFourPolicy(
    array $answers = [],
    ProbeScope|Throwable|null $scope = null,
    array $overrides = [],
): array {
    $resolver = new TaskFourDnsResolver($answers);
    $scopeResolver = new TaskFourScopeResolver($scope ?? new ProbeScope(
        siteId: 9,
        deviceId: 81,
        approvedCidrs: ['10.44.0.0/16', '2001:db8:44::/48'],
        allowedPorts: [53, 80, 443, 8443],
    ));
    $config = array_replace([
        'connect_timeout_seconds' => 5,
        'response_timeout_seconds' => 15,
        'max_response_bytes' => 1_048_576,
        'deny_cidrs' => [
            '0.0.0.0/8',
            '127.0.0.0/8',
            '100.100.100.200/32',
            '169.254.0.0/16',
            '224.0.0.0/4',
            '240.0.0.0/4',
            '::/128',
            '::1/128',
            'fe80::/10',
            'fd00:ec2::254/128',
            'ff00::/8',
        ],
    ], $overrides);

    return [new EgressPolicy(new CidrMatcher, $resolver, $scopeResolver, $config), $resolver, $scopeResolver];
}

it('resolves a hostname exactly once and pins every approved address', function () {
    [$policy, $resolver, $scopeResolver] = taskFourPolicy([
        'switch.site.example' => ['10.44.8.10', '2001:db8:44::10', '10.44.8.10'],
    ]);

    $authorised = $policy->authorise(9, 81, ProbeTarget::tcp('SWITCH.Site.Example.', 443));

    expect($authorised->host)->toBe('switch.site.example')
        ->and($authorised->addresses)->toBe(['10.44.8.10', '2001:db8:44::10'])
        ->and($authorised->siteId)->toBe(9)
        ->and($authorised->deviceId)->toBe(81)
        ->and($authorised->port)->toBe(443)
        ->and($resolver->calls)->toBe(['switch.site.example' => 1])
        ->and($scopeResolver->calls)->toBe(1);
});

it('denies the whole target when one DNS result is outside the approved scope', function () {
    [$policy, $resolver] = taskFourPolicy([
        'rebind.site.example' => ['10.44.8.11', '169.254.169.254'],
    ]);

    expect(fn () => $policy->authorise(9, 81, ProbeTarget::http('http://rebind.site.example/status')))
        ->toThrow(EgressDenied::class, 'resolved address outside scope')
        ->and($resolver->calls)->toBe(['rebind.site.example' => 1]);
});

it('denies a canonical scope that does not exactly match the requested site and device', function (
    ProbeScope $scope,
) {
    [$policy] = taskFourPolicy(scope: $scope);

    expect(fn () => $policy->authorise(9, 81, ProbeTarget::tcp('10.44.1.10', 443)))
        ->toThrow(EgressDenied::class, 'canonical scope mismatch');
})->with([
    'site mismatch' => new ProbeScope(10, 81, ['10.44.0.0/16'], [443]),
    'device mismatch' => new ProbeScope(9, 82, ['10.44.0.0/16'], [443]),
]);

it('denies global special-use ranges even when an approved scope includes them', function (string $address) {
    $scope = new ProbeScope(9, 81, ['0.0.0.0/0', '::/0'], [443]);
    [$policy] = taskFourPolicy(scope: $scope);

    expect(fn () => $policy->authorise(9, 81, ProbeTarget::tcp($address, 443)))
        ->toThrow(EgressDenied::class, 'resolved address outside scope');
})->with([
    'IPv4 unspecified block' => '0.0.0.1',
    'IPv4 loopback' => '127.0.0.1',
    'Alibaba metadata' => '100.100.100.200',
    'link-local metadata' => '169.254.169.254',
    'IPv4 multicast' => '224.0.0.1',
    'limited broadcast' => '255.255.255.255',
    'IPv6 unspecified' => '::',
    'IPv6 loopback' => '::1',
    'IPv6 link local' => 'fe80::1',
    'AWS IPv6 metadata' => 'fd00:ec2::254',
    'IPv6 multicast' => 'ff02::1',
    'mapped IPv4 loopback' => '::ffff:127.0.0.1',
    'mapped IPv4 metadata' => '::ffff:169.254.169.254',
]);

it('matches IPv4 and IPv6 CIDR boundaries without family confusion', function () {
    $matcher = new CidrMatcher;

    expect($matcher->contains('10.44.0.0/16', '10.44.0.0'))->toBeTrue()
        ->and($matcher->contains('10.44.0.0/16', '10.44.255.255'))->toBeTrue()
        ->and($matcher->contains('10.44.0.0/16', '10.45.0.0'))->toBeFalse()
        ->and($matcher->contains('2001:db8:44::/48', '2001:db8:44:ffff::1'))->toBeTrue()
        ->and($matcher->contains('2001:db8:44::/48', '2001:db8:45::1'))->toBeFalse()
        ->and($matcher->contains('10.44.0.0/16', '2001:db8:44::1'))->toBeFalse()
        ->and($matcher->contains('::ffff:10.44.0.0/112', '10.44.8.1'))->toBeTrue();
});

it('denies IPv4 network and directed broadcast addresses for ordinary subnets', function (string $address) {
    [$policy] = taskFourPolicy();

    expect(fn () => $policy->authorise(9, 81, ProbeTarget::tcp($address, 443)))
        ->toThrow(EgressDenied::class, 'network or broadcast address is forbidden');
})->with(['10.44.0.0', '10.44.255.255']);

it('allows both endpoints of point-to-point and host-route scopes', function (string $cidr, string $address) {
    [$policy] = taskFourPolicy(scope: new ProbeScope(9, 81, [$cidr], [443]));

    expect($policy->authorise(9, 81, ProbeTarget::tcp($address, 443))->addresses)->toBe([$address]);
})->with([
    '/31 endpoint zero' => ['10.44.1.0/31', '10.44.1.0'],
    '/31 endpoint one' => ['10.44.1.0/31', '10.44.1.1'],
    '/32 host route' => ['10.44.1.7/32', '10.44.1.7'],
]);

it('denies targets outside approved networks and ports outside the allowlist', function () {
    [$policy] = taskFourPolicy();

    expect(fn () => $policy->authorise(9, 81, ProbeTarget::tcp('10.45.0.10', 443)))
        ->toThrow(EgressDenied::class, 'resolved address outside scope')
        ->and(fn () => $policy->authorise(9, 81, ProbeTarget::tcp('10.44.0.10', 22)))
        ->toThrow(EgressDenied::class, 'port is outside scope');
});

it('fails closed on empty malformed and mixed DNS answers', function (array $answer) {
    [$policy] = taskFourPolicy(['bad.site.example' => $answer]);

    expect(fn () => $policy->authorise(9, 81, ProbeTarget::tcp('bad.site.example', 443)))
        ->toThrow(EgressDenied::class);
})->with([
    'empty' => [[]],
    'malformed only' => [['not-an-address']],
    'mixed malformed' => [['10.44.1.8', 'not-an-address']],
    'non-string' => [['10.44.1.8', 123]],
]);

it('fails closed without leaking resolver failures', function () {
    [$policy] = taskFourPolicy(['down.site.example' => [new RuntimeException('nameserver 10.0.0.53 secret')]]);

    $denial = null;
    try {
        $policy->authorise(9, 81, ProbeTarget::tcp('down.site.example', 443));
    } catch (EgressDenied $exception) {
        $denial = $exception;
    }

    expect($denial)->toBeInstanceOf(EgressDenied::class)
        ->and($denial?->getMessage())->toBe('DNS resolution failed')
        ->and($denial?->getPrevious())->toBeNull();
});

it('does not perform DNS resolution for a numeric address', function () {
    [$policy, $resolver] = taskFourPolicy();

    expect($policy->authorise(9, 81, ProbeTarget::tcp('10.44.1.8', 443))->addresses)->toBe(['10.44.1.8'])
        ->and($resolver->calls)->toBe([]);
});

it('authorises ICMP by approved network without inventing a transport port', function () {
    [$policy, $resolver] = taskFourPolicy();

    $authorised = $policy->authorise(9, 81, ProbeTarget::icmp('10.44.1.8'));

    expect($authorised->scheme)->toBe('icmp')
        ->and($authorised->port)->toBe(0)
        ->and($authorised->addresses)->toBe(['10.44.1.8'])
        ->and($resolver->calls)->toBe([]);
});

it('normalises IDN case and a single trailing root dot', function (string $input, string $normalised) {
    [$policy, $resolver] = taskFourPolicy([$normalised => ['10.44.1.8']]);

    expect($policy->authorise(9, 81, ProbeTarget::tcp($input, 443))->host)->toBe($normalised)
        ->and($resolver->calls)->toBe([$normalised => 1]);
})->with([
    ['SWITCH.Site.Example.', 'switch.site.example'],
    ['BÜCHER.Example.', 'xn--bcher-kva.example'],
]);

it('rejects invalid URLs and ambiguous paths before resolution', function (string $url, string $message) {
    expect(fn () => ProbeTarget::http($url))->toThrow(EgressDenied::class, $message);
})->with([
    ['http://user:pass@switch.site.example/', 'userinfo is forbidden'],
    ['http://user@switch.site.example/', 'userinfo is forbidden'],
    ['http://:pass@switch.site.example/', 'userinfo is forbidden'],
    ['ftp://switch.site.example/', 'scheme is forbidden'],
    ['http://switch.site.example/%0d%0aInjected', 'path is forbidden'],
    ['http://switch.site.example/%QQ', 'path is forbidden'],
    ['http://switch.site.example/a\\b', 'path is forbidden'],
    ['http://switch.site.example/a%5cb', 'path is forbidden'],
    ['http://switch.site.example/a?next=%5cadmin', 'path is forbidden'],
    ['http://switch.site.example/a#fragment', 'fragment is forbidden'],
]);

it('rejects legacy numeric IPv4 forms instead of resolving them as DNS names', function (string $host) {
    expect(fn () => ProbeTarget::tcp($host, 443))->toThrow(EgressDenied::class, 'host is invalid');
})->with(['127.1', '2130706433', '010.000.000.001', '0x7f000001', '0x7f.0.0.1']);

it('carries a validated path query and bounded transport limits', function () {
    $scope = new ProbeScope(
        siteId: 9,
        deviceId: 81,
        approvedCidrs: ['10.44.0.0/16'],
        allowedPorts: [8443],
        connectTimeoutSeconds: 2,
        responseTimeoutSeconds: 7,
        maxResponseBytes: 4096,
    );
    [$policy] = taskFourPolicy(
        ['service.site.example' => ['10.44.1.8']],
        $scope,
    );

    $authorised = $policy->authorise(
        9,
        81,
        ProbeTarget::http('https://service.site.example:8443/health?full=1'),
    );

    expect($authorised->scheme)->toBe('https')
        ->and($authorised->path)->toBe('/health?full=1')
        ->and($authorised->connectTimeoutSeconds)->toBe(2)
        ->and($authorised->responseTimeoutSeconds)->toBe(7)
        ->and($authorised->maxResponseBytes)->toBe(4096);
});

it('rejects credential-bearing query parameter names without retaining the raw value', function (string $url) {
    $denial = null;

    try {
        ProbeTarget::http($url);
    } catch (EgressDenied $exception) {
        $denial = $exception;
    }

    expect($denial)->toBeInstanceOf(EgressDenied::class)
        ->and($denial?->getMessage())->toBe('credential query parameters are forbidden')
        ->and($denial?->getMessage())->not->toContain('raw-secret');
})->with([
    'token' => 'https://service.site.example/health?access_token=raw-secret',
    'mixed-case API key' => 'https://service.site.example/health?API_KEY=raw-secret',
    'compact API key' => 'https://service.site.example/health?apiKey=raw-secret',
    'compact access token' => 'https://service.site.example/health?accessToken=raw-secret',
    'compact auth token' => 'https://service.site.example/health?authToken=raw-secret',
    'compact client secret' => 'https://service.site.example/health?clientSecret=raw-secret',
    'encoded password name' => 'https://service.site.example/health?pass%77ord=raw-secret',
    'double-encoded token separator' => 'https://service.site.example/health?access%255Ftoken=raw-secret',
    'over-encoded token letters' => 'https://service.site.example/health?access_%25252574oken=raw-secret',
    'nested signature' => 'https://service.site.example/health?filter%5Bsignature%5D=raw-secret',
]);

it('fails closed on invalid global or scope transport bounds', function (array $overrides, ?ProbeScope $scope = null) {
    [$policy] = taskFourPolicy(scope: $scope, overrides: $overrides);

    expect(fn () => $policy->authorise(9, 81, ProbeTarget::tcp('10.44.1.8', 443)))
        ->toThrow(EgressDenied::class, 'transport bounds are invalid');
})->with([
    'zero connect timeout' => [['connect_timeout_seconds' => 0]],
    'excessive response timeout' => [['response_timeout_seconds' => 121]],
    'excessive response body' => [['max_response_bytes' => 10_485_761]],
    'invalid scope timeout' => [[], new ProbeScope(9, 81, ['10.44.0.0/16'], [443], 0, 7, 4096)],
]);

it('reauthorises every redirect and denies a target that resolves outside scope', function () {
    [$policy, $resolver] = taskFourPolicy([
        'service.site.example' => ['10.44.1.8'],
        'metadata.site.example' => ['169.254.169.254'],
    ]);
    $current = $policy->authorise(9, 81, ProbeTarget::http('https://service.site.example/health'));

    expect(fn () => $policy->reauthoriseRedirect($current, 'https://metadata.site.example/latest'))
        ->toThrow(EgressDenied::class, 'resolved address outside scope')
        ->and($resolver->calls)->toBe([
            'service.site.example' => 1,
            'metadata.site.example' => 1,
        ]);
});

it('resolves a relative redirect then performs fresh DNS authorisation', function () {
    [$policy, $resolver] = taskFourPolicy([
        'service.site.example' => [
            ['10.44.1.8'],
            ['10.44.1.9'],
        ],
    ]);
    $current = $policy->authorise(9, 81, ProbeTarget::http('https://service.site.example/a/health'));
    $redirected = $policy->reauthoriseRedirect($current, '../ready?full=1');

    expect($redirected->path)->toBe('/ready?full=1')
        ->and($redirected->addresses)->toBe(['10.44.1.9'])
        ->and($resolver->calls)->toBe(['service.site.example' => 2]);
});

it('denies HTTPS downgrade redirects', function () {
    [$policy] = taskFourPolicy(['service.site.example' => ['10.44.1.8']]);
    $current = $policy->authorise(9, 81, ProbeTarget::http('https://service.site.example/health'));

    expect(fn () => $policy->reauthoriseRedirect($current, 'http://service.site.example/ready'))
        ->toThrow(EgressDenied::class, 'HTTPS downgrade is forbidden');
});

it('fails closed when canonical scope resolution fails', function (Throwable $failure) {
    [$policy] = taskFourPolicy(scope: $failure);

    $denial = null;
    try {
        $policy->authorise(9, 81, ProbeTarget::tcp('10.44.1.8', 443));
    } catch (EgressDenied $exception) {
        $denial = $exception;
    }

    expect($denial)->toBeInstanceOf(EgressDenied::class)
        ->and($denial?->getMessage())->toBe('probe scope resolution failed')
        ->and($denial?->getPrevious())->toBeNull();
})->with([
    'infrastructure exception' => new RuntimeException('database credentials leaked'),
    'policy exception' => new EgressDenied('scope row secret leaked'),
]);

it('validates the global deny policy before asking DNS for an address', function () {
    [$policy, $resolver] = taskFourPolicy(
        ['service.site.example' => ['10.44.1.8']],
        overrides: ['deny_cidrs' => ['invalid-cidr']],
    );

    expect(fn () => $policy->authorise(9, 81, ProbeTarget::tcp('service.site.example', 443)))
        ->toThrow(EgressDenied::class, 'global egress policy is invalid')
        ->and($resolver->calls)->toBe([]);
});
