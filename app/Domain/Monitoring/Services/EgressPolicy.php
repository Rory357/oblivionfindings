<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\ProbeScopeResolver;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

final class EgressPolicy
{
    private const MAX_CONNECT_TIMEOUT_SECONDS = 30;

    private const MAX_RESPONSE_TIMEOUT_SECONDS = 120;

    private const MAX_RESPONSE_BYTES = 10_485_760;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly CidrMatcher $cidrMatcher,
        private readonly DnsResolver $dnsResolver,
        private readonly ProbeScopeResolver $scopeResolver,
        private readonly array $config,
    ) {}

    public function authorise(int $siteId, int $deviceId, ProbeTarget $target): AuthorizedProbeTarget
    {
        if ($siteId < 1 || $deviceId < 1) {
            throw new EgressDenied('probe scope is invalid');
        }

        try {
            $scope = $this->scopeResolver->resolve($siteId, $deviceId);
        } catch (Throwable) {
            throw new EgressDenied('probe scope resolution failed');
        }

        if ($scope->siteId !== $siteId || $scope->deviceId !== $deviceId) {
            throw new EgressDenied('canonical scope mismatch');
        }

        $this->assertScope($scope, $target);
        [$connectTimeout, $responseTimeout, $maxResponseBytes] = $this->transportBounds($scope);
        $addresses = $this->authorisedAddresses($scope, $target->host);

        return AuthorizedProbeTarget::fromEgressPolicy(
            siteId: $siteId,
            deviceId: $deviceId,
            scheme: $target->scheme,
            host: $target->host,
            port: $target->port,
            path: $target->path,
            addresses: $addresses,
            connectTimeoutSeconds: $connectTimeout,
            responseTimeoutSeconds: $responseTimeout,
            maxResponseBytes: $maxResponseBytes,
        );
    }

    /**
     * Authorise a governed discovery target before a canonical Device exists.
     *
     * @param  list<string>  $approvedCidrs
     * @param  list<int>  $allowedPorts
     */
    public function authoriseDiscovery(
        int $siteId,
        array $approvedCidrs,
        array $allowedPorts,
        ProbeTarget $target,
    ): AuthorizedProbeTarget {
        if ($siteId < 1) {
            throw new EgressDenied('discovery scope is invalid');
        }

        $scope = new ProbeScope(
            siteId: $siteId,
            deviceId: 0,
            approvedCidrs: $approvedCidrs,
            allowedPorts: $allowedPorts,
        );
        $this->assertScope($scope, $target);
        [$connectTimeout, $responseTimeout, $maxResponseBytes] = $this->transportBounds($scope);
        $addresses = $this->authorisedAddresses($scope, $target->host);

        return AuthorizedProbeTarget::fromEgressPolicy(
            siteId: $siteId,
            deviceId: 0,
            scheme: $target->scheme,
            host: $target->host,
            port: $target->port,
            path: $target->path,
            addresses: $addresses,
            connectTimeoutSeconds: $connectTimeout,
            responseTimeoutSeconds: $responseTimeout,
            maxResponseBytes: $maxResponseBytes,
        );
    }

    /** @param list<string> $allowedHosts */
    public function authoriseExternalHttps(string $url, array $allowedHosts): AuthorizedProbeTarget
    {
        try {
            $target = ProbeTarget::http($url);
        } catch (Throwable) {
            throw new EgressDenied('external heartbeat target is invalid');
        }

        if ($target->scheme !== 'https') {
            throw new EgressDenied('external heartbeat target scheme must be HTTPS');
        }
        if ($target->port !== 443 || $target->path === null || $target->path === '/'
            || str_contains($target->path, '?')) {
            throw new EgressDenied('external heartbeat target is invalid');
        }
        if (! array_is_list($allowedHosts) || $allowedHosts === []) {
            throw new EgressDenied('external heartbeat host allowlist is invalid');
        }

        $hosts = [];
        foreach ($allowedHosts as $host) {
            if (! is_string($host) || $host === '' || strtolower($host) !== $host
                || preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
                throw new EgressDenied('external heartbeat host allowlist is invalid');
            }
            $hosts[] = $host;
        }
        if (count($hosts) !== count(array_unique($hosts))
            || ! in_array($target->host, $hosts, true)) {
            throw new EgressDenied('external heartbeat host is not allowlisted');
        }

        $connectTimeout = $this->config['external_heartbeat']['connect_timeout_seconds'] ?? null;
        $responseTimeout = $this->config['external_heartbeat']['response_timeout_seconds'] ?? null;
        if (! is_int($connectTimeout) || $connectTimeout < 1 || $connectTimeout > 10
            || ! is_int($responseTimeout) || $responseTimeout < 1 || $responseTimeout > 15) {
            throw new EgressDenied('external heartbeat transport bounds are invalid');
        }

        return AuthorizedProbeTarget::fromEgressPolicy(
            siteId: 0,
            deviceId: 0,
            scheme: 'https',
            host: $target->host,
            port: 443,
            path: $target->path,
            addresses: $this->externalPublicAddresses($target->host),
            connectTimeoutSeconds: $connectTimeout,
            responseTimeoutSeconds: $responseTimeout,
            maxResponseBytes: 1024,
        );
    }

    public function reauthoriseRedirect(AuthorizedProbeTarget $current, string $location): AuthorizedProbeTarget
    {
        if (! in_array($current->scheme, ['http', 'https'], true)) {
            throw new EgressDenied('redirects are forbidden for this target');
        }

        try {
            $resolved = UriResolver::resolve(new Uri($current->url()), new Uri($location));
            $target = ProbeTarget::http((string) $resolved);
        } catch (EgressDenied $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new EgressDenied('redirect target is invalid');
        }

        if ($current->scheme === 'https' && $target->scheme !== 'https') {
            throw new EgressDenied('HTTPS downgrade is forbidden');
        }

        return $this->authorise($current->siteId, $current->deviceId, $target);
    }

    private function assertScope(ProbeScope $scope, ProbeTarget $target): void
    {
        if ($scope->approvedCidrs === [] || ! array_is_list($scope->approvedCidrs)
            || $scope->allowedPorts === [] || ! array_is_list($scope->allowedPorts)) {
            throw new EgressDenied('probe scope is invalid');
        }

        try {
            foreach ($scope->approvedCidrs as $cidr) {
                if (! is_string($cidr) || $cidr === '') {
                    throw new EgressDenied('probe scope is invalid');
                }
                $this->cidrMatcher->assertValidCidr($cidr);
            }
        } catch (EgressDenied $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new EgressDenied('probe scope is invalid');
        }

        foreach ($scope->allowedPorts as $port) {
            if (! is_int($port) || $port < 1 || $port > 65535) {
                throw new EgressDenied('probe scope is invalid');
            }
        }

        if ($target->scheme !== 'icmp' && ! in_array($target->port, $scope->allowedPorts, true)) {
            throw new EgressDenied('port is outside scope');
        }
    }

    /** @return non-empty-list<string> */
    private function authorisedAddresses(ProbeScope $scope, string $host): array
    {
        $denyCidrs = $this->globalDenyCidrs();

        try {
            $numeric = filter_var($host, FILTER_VALIDATE_IP) !== false;
            $answers = $numeric ? [$host] : $this->dnsResolver->resolve($host);
        } catch (Throwable) {
            throw new EgressDenied('DNS resolution failed');
        }

        if (! is_array($answers) || ! array_is_list($answers) || $answers === []) {
            throw new EgressDenied('DNS resolution returned no usable addresses');
        }

        $addresses = [];
        foreach ($answers as $answer) {
            if (! is_string($answer) || $answer === '') {
                throw new EgressDenied('DNS resolution returned a malformed address');
            }

            try {
                $addresses[] = $this->cidrMatcher->canonicalAddress($answer);
            } catch (Throwable) {
                throw new EgressDenied('DNS resolution returned a malformed address');
            }
        }
        $addresses = array_values(array_unique($addresses));

        foreach ($addresses as $address) {
            foreach ($denyCidrs as $denyCidr) {
                if ($this->cidrMatcher->contains($denyCidr, $address)) {
                    throw new EgressDenied('resolved address outside scope');
                }
            }

            $matchingCidrs = array_values(array_filter(
                $scope->approvedCidrs,
                fn (string $cidr): bool => $this->cidrMatcher->contains($cidr, $address),
            ));

            if ($matchingCidrs === []) {
                throw new EgressDenied('resolved address outside scope');
            }

            $hasUsableHostRoute = collect($matchingCidrs)->contains(
                fn (string $cidr): bool => ! $this->cidrMatcher->isIpv4NetworkOrBroadcast($cidr, $address),
            );
            if (! $hasUsableHostRoute) {
                throw new EgressDenied('network or broadcast address is forbidden');
            }
        }

        /** @var non-empty-list<string> $addresses */
        return $addresses;
    }

    /** @return non-empty-list<string> */
    private function globalDenyCidrs(): array
    {
        $denyCidrs = $this->config['deny_cidrs'] ?? null;
        if (! is_array($denyCidrs) || ! array_is_list($denyCidrs) || $denyCidrs === []) {
            throw new EgressDenied('global egress policy is invalid');
        }

        try {
            foreach ($denyCidrs as $cidr) {
                if (! is_string($cidr) || $cidr === '') {
                    throw new EgressDenied('global egress policy is invalid');
                }
                $this->cidrMatcher->assertValidCidr($cidr);
            }
        } catch (Throwable) {
            throw new EgressDenied('global egress policy is invalid');
        }

        /** @var non-empty-list<string> $denyCidrs */
        return $denyCidrs;
    }

    /** @return non-empty-list<string> */
    private function externalPublicAddresses(string $host): array
    {
        $denyCidrs = $this->config['external_heartbeat']['deny_cidrs'] ?? null;
        if (! is_array($denyCidrs) || ! array_is_list($denyCidrs) || $denyCidrs === []) {
            throw new EgressDenied('external heartbeat egress policy is invalid');
        }

        try {
            foreach ($denyCidrs as $cidr) {
                if (! is_string($cidr) || $cidr === '') {
                    throw new EgressDenied('external heartbeat egress policy is invalid');
                }
                $this->cidrMatcher->assertValidCidr($cidr);
            }
            $answers = filter_var($host, FILTER_VALIDATE_IP) !== false
                ? [$host]
                : $this->dnsResolver->resolve($host);
        } catch (EgressDenied $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new EgressDenied('external heartbeat DNS resolution failed');
        }

        if (! is_array($answers) || ! array_is_list($answers) || $answers === []) {
            throw new EgressDenied('external heartbeat DNS resolution failed');
        }

        $addresses = [];
        foreach ($answers as $answer) {
            try {
                if (! is_string($answer) || $answer === '') {
                    throw new EgressDenied('external heartbeat DNS resolution failed');
                }
                $address = $this->cidrMatcher->canonicalAddress($answer);
            } catch (EgressDenied $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new EgressDenied('external heartbeat DNS resolution failed');
            }

            foreach ($denyCidrs as $denyCidr) {
                if ($this->cidrMatcher->contains($denyCidr, $address)) {
                    throw new EgressDenied('external heartbeat resolved address is not public');
                }
            }
            $addresses[] = $address;
        }

        $addresses = array_values(array_unique($addresses));
        sort($addresses, SORT_STRING);
        if ($addresses === [] || count($addresses) > 16) {
            throw new EgressDenied('external heartbeat DNS resolution failed');
        }

        /** @var non-empty-list<string> $addresses */
        return $addresses;
    }

    /** @return array{int, int, int} */
    private function transportBounds(ProbeScope $scope): array
    {
        $globalConnect = $this->config['connect_timeout_seconds'] ?? null;
        $globalResponse = $this->config['response_timeout_seconds'] ?? null;
        $globalBytes = $this->config['max_response_bytes'] ?? null;

        if (! $this->validBound($globalConnect, self::MAX_CONNECT_TIMEOUT_SECONDS)
            || ! $this->validBound($globalResponse, self::MAX_RESPONSE_TIMEOUT_SECONDS)
            || ! $this->validBound($globalBytes, self::MAX_RESPONSE_BYTES)
            || ($scope->connectTimeoutSeconds !== null
                && ! $this->validBound($scope->connectTimeoutSeconds, self::MAX_CONNECT_TIMEOUT_SECONDS))
            || ($scope->responseTimeoutSeconds !== null
                && ! $this->validBound($scope->responseTimeoutSeconds, self::MAX_RESPONSE_TIMEOUT_SECONDS))
            || ($scope->maxResponseBytes !== null
                && ! $this->validBound($scope->maxResponseBytes, self::MAX_RESPONSE_BYTES))) {
            throw new EgressDenied('transport bounds are invalid');
        }

        return [
            min($globalConnect, $scope->connectTimeoutSeconds ?? $globalConnect),
            min($globalResponse, $scope->responseTimeoutSeconds ?? $globalResponse),
            min($globalBytes, $scope->maxResponseBytes ?? $globalBytes),
        ];
    }

    private function validBound(mixed $value, int $maximum): bool
    {
        return is_int($value) && $value > 0 && $value <= $maximum;
    }
}
