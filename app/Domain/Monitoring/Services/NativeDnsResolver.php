<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use Closure;

final class NativeDnsResolver implements DnsResolver
{
    /** @param (Closure(string, int): array<int, array<string, mixed>>|false)|null $lookup */
    public function __construct(private readonly ?Closure $lookup = null) {}

    public function resolve(string $host): array
    {
        if ($host === '' || strlen($host) > 253
            || preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $host) !== 1
            || ! function_exists('dns_get_record')) {
            throw new EgressDenied('DNS resolution failed');
        }

        $lookup = $this->lookup ?? static fn (string $name, int $type): array|false => @dns_get_record($name, $type);
        $records = $lookup($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            throw new EgressDenied('DNS resolution failed');
        }

        $addresses = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new EgressDenied('DNS resolution returned a malformed address');
            }
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($address === null) {
                continue;
            }
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new EgressDenied('DNS resolution returned a malformed address');
            }
            $packed = @inet_pton($address);
            $canonical = $packed === false ? false : inet_ntop($packed);
            if (! is_string($canonical) || $canonical === '') {
                throw new EgressDenied('DNS resolution returned a malformed address');
            }
            $addresses[] = strtolower($canonical);
        }

        $addresses = array_values(array_unique($addresses));
        sort($addresses, SORT_STRING);
        if ($addresses === [] || count($addresses) > 16) {
            throw new EgressDenied('DNS resolution returned no usable addresses');
        }

        return $addresses;
    }
}
