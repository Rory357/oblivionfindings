<?php

namespace App\Domain\Monitoring\Discovery\Services;

use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Models\Site;
use Throwable;

final class DiscoveryScopeValidator
{
    public function __construct(private readonly CidrMatcher $cidrs) {}

    /** @return array{decision: string, reason: string}|null */
    public function preflight(DiscoveryScope $scope, DiscoveredIdentity $identity): ?array
    {
        $scopeValidation = $this->validateScope($scope);
        if ($scopeValidation !== null) {
            return $scopeValidation;
        }

        $scopeCidrs = array_values($scope->cidrs ?? []);

        foreach ($identity->addresses as $address) {
            try {
                $inside = collect($scopeCidrs)->contains(fn (string $cidr): bool => $this->cidrs->contains($cidr, $address));
            } catch (Throwable) {
                return ['decision' => 'rejected', 'reason' => 'discovered_address_invalid'];
            }
            if (! $inside) {
                return ['decision' => 'rejected', 'reason' => 'address_outside_approved_network'];
            }
        }

        if ($this->excluded($scope, $identity)) {
            return ['decision' => 'excluded', 'reason' => 'scope_exclusion'];
        }

        return null;
    }

    /** @return array{decision: string, reason: string}|null */
    public function validateScope(DiscoveryScope $scope): ?array
    {
        if ($scope->status !== 'active' || $scope->trashed()) {
            return ['decision' => 'rejected', 'reason' => 'scope_inactive'];
        }
        if ((int) $scope->max_targets_per_run < 1
            || (int) $scope->max_targets_per_run > 65536
            || (int) $scope->packets_per_second < 1
            || (int) $scope->packets_per_second > 1000
            || ! is_array($scope->cidrs)
            || count($scope->cidrs) > 64
            || ! is_array($scope->seed_hosts ?? [])
            || count($scope->seed_hosts ?? []) > 256
            || ! is_array($scope->exclusions ?? [])
            || count($scope->exclusions ?? []) > 1024
            || ! $this->validProtocols($scope->protocols ?? [])
            || ! $this->validSnmpReference($scope->protocols ?? [], $scope->snmp_credential_reference)
            || ! $this->validPortBounds($scope->port_bounds ?? [])
            || ! $this->validStrings($scope->exclusions ?? [], 2048)) {
            return ['decision' => 'rejected', 'reason' => 'scope_configuration_invalid'];
        }
        if (! Site::query()
            ->whereKey($scope->site_id)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->exists()) {
            return ['decision' => 'rejected', 'reason' => 'scope_site_unavailable'];
        }
        if ($scope->collector_id !== null) {
            $collector = MonitoringCollector::query()->find($scope->collector_id);
            if ($collector === null
                || (int) $collector->site_id !== (int) $scope->site_id
                || ! in_array(strtolower((string) $collector->status), ['online', 'offline', 'degraded', 'unavailable'], true)) {
                return ['decision' => 'rejected', 'reason' => 'collector_scope_mismatch'];
            }
        }

        $scopeCidrs = array_values(array_filter(
            $scope->cidrs,
            fn (mixed $cidr): bool => is_string($cidr) && trim($cidr) !== '',
        ));
        if ($scopeCidrs === []) {
            return ['decision' => 'rejected', 'reason' => 'approved_network_missing'];
        }
        if (count($scopeCidrs) !== count($scope->cidrs)) {
            return ['decision' => 'rejected', 'reason' => 'approved_network_invalid'];
        }
        try {
            foreach ($scopeCidrs as $cidr) {
                $this->cidrs->assertValidCidr($cidr);
            }
            foreach ($scope->seed_hosts ?? [] as $seed) {
                if (! is_string($seed) || strlen($seed) > 253) {
                    throw new \InvalidArgumentException('Seed host is invalid.');
                }
                ProbeTarget::icmp($seed);
            }
        } catch (Throwable) {
            return ['decision' => 'rejected', 'reason' => 'approved_network_invalid'];
        }

        return null;
    }

    private function validProtocols(mixed $protocols): bool
    {
        if (! is_array($protocols) || ! array_is_list($protocols) || $protocols === [] || count($protocols) > 9) {
            return false;
        }

        $allowed = ['icmp', 'tcp', 'dns', 'http', 'tls', 'snmp', 'syslog', 'flow', 'provider'];

        return collect($protocols)->unique()->count() === count($protocols) && collect($protocols)->every(
            fn (mixed $protocol): bool => is_string($protocol) && in_array(strtolower($protocol), $allowed, true),
        );
    }

    private function validPortBounds(mixed $bounds): bool
    {
        if (! is_array($bounds) || count($bounds) > 9) {
            return false;
        }
        $ports = [];
        foreach ($bounds as $kind => $values) {
            if (! is_string($kind)
                || ! in_array(strtolower($kind), ['tcp', 'dns', 'http', 'tls', 'snmp', 'syslog', 'flow', 'provider'], true)
                || ! is_array($values)
                || ! array_is_list($values)) {
                return false;
            }
            foreach ($values as $port) {
                if (! is_int($port) || $port < 1 || $port > 65535) {
                    return false;
                }
                $ports[] = $port;
            }
        }

        return count($ports) <= 128;
    }

    /** @param list<mixed> $protocols */
    private function validSnmpReference(array $protocols, mixed $reference): bool
    {
        $requiresSnmp = collect($protocols)->contains(
            fn (mixed $protocol): bool => is_string($protocol) && strtolower($protocol) === 'snmp',
        );
        if (! $requiresSnmp) {
            return $reference === null || $reference === '';
        }

        return is_string($reference)
            && preg_match('/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/', $reference) === 1
            && ! str_contains($reference, '://');
    }

    /** @param list<mixed> $values */
    private function validStrings(array $values, int $maximumLength): bool
    {
        return collect($values)->every(
            fn (mixed $value): bool => is_string($value)
                && trim($value) !== ''
                && strlen($value) <= $maximumLength,
        );
    }

    private function excluded(DiscoveryScope $scope, DiscoveredIdentity $identity): bool
    {
        $hostnames = collect($identity->evidence())
            ->where('type', 'hostname')
            ->pluck('value');
        $macs = collect($identity->evidence())
            ->where('type', 'mac_address')
            ->pluck('value');

        foreach ($scope->exclusions ?? [] as $exclusion) {
            if (! is_string($exclusion) || trim($exclusion) === '') {
                continue;
            }
            $exclusion = trim($exclusion);
            if ($hostnames->contains(strtolower(rtrim($exclusion, '.')))) {
                return true;
            }
            try {
                if ($macs->contains(DeviceIdentityEvidence::normaliseValue('mac_address', $exclusion))) {
                    return true;
                }
            } catch (Throwable) {
                // The exclusion is not a MAC address; continue with IP checks.
            }
            foreach ($identity->addresses as $address) {
                try {
                    if ((str_contains($exclusion, '/') && $this->cidrs->contains($exclusion, $address))
                        || (! str_contains($exclusion, '/')
                            && $this->cidrs->canonicalAddress($exclusion) === $this->cidrs->canonicalAddress($address))) {
                        return true;
                    }
                } catch (Throwable) {
                    // Non-address exclusions are already handled above.
                }
            }
        }

        return false;
    }
}
