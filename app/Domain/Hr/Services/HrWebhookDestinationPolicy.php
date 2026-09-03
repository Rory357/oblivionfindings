<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Data\AuthorizedHrWebhookDestination;
use App\Domain\Hr\Exceptions\UnsafeWebhookDestination;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Services\CidrMatcher;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

final class HrWebhookDestinationPolicy
{
    private const MAX_URL_BYTES = 1500;

    /** @var non-empty-list<string> */
    private const DENIED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/96',
        '::1/128',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:2::/48',
        '2001:10::/28',
        '2001:20::/28',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fec0::/10',
        'fe80::/10',
        'ff00::/8',
    ];

    public function __construct(
        private readonly CidrMatcher $cidrMatcher,
        private readonly DnsResolver $dnsResolver,
    ) {}

    public function authorize(string $url): AuthorizedHrWebhookDestination
    {
        try {
            if ($url === '' || strlen($url) > self::MAX_URL_BYTES) {
                throw new UnsafeWebhookDestination('Webhook destination is not approved.');
            }

            $target = ProbeTarget::http($url);
            if ($target->scheme !== 'https') {
                throw new UnsafeWebhookDestination('Webhook destination is not approved.');
            }

            $addresses = $this->publicAddresses($target->host);

            return new AuthorizedHrWebhookDestination(
                url: $this->canonicalUrl($target),
                host: $target->host,
                port: $target->port,
                addresses: $addresses,
            );
        } catch (UnsafeWebhookDestination $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new UnsafeWebhookDestination('Webhook destination is not approved.');
        }
    }

    public function authorizeRedirect(
        AuthorizedHrWebhookDestination $current,
        string $location,
    ): AuthorizedHrWebhookDestination {
        try {
            if ($location === '' || strlen($location) > self::MAX_URL_BYTES
                || preg_match('/[\x00-\x20\x7f]/', $location) === 1
                || str_contains($location, '\\')) {
                throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
            }

            $resolved = (string) UriResolver::resolve(new Uri($current->url), new Uri($location));
            $redirectTarget = ProbeTarget::http($resolved);
            if ($redirectTarget->scheme !== 'https'
                || $redirectTarget->host !== $current->host
                || $redirectTarget->port !== $current->port) {
                throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
            }

            return $this->authorize($resolved);
        } catch (UnsafeWebhookDestination $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
        }
    }

    /** @return non-empty-list<string> */
    private function publicAddresses(string $host): array
    {
        $normalizedHost = strtolower(trim($host, '[]'));
        if ($normalizedHost === 'localhost' || str_ends_with($normalizedHost, '.localhost') || str_ends_with($normalizedHost, '.local') || str_ends_with($normalizedHost, '.internal')) {
            throw new UnsafeWebhookDestination('Webhook destination is not approved.');
        }

        try {
            $answers = filter_var($host, FILTER_VALIDATE_IP) !== false
                ? [$host]
                : $this->dnsResolver->resolve($host);
        } catch (Throwable) {
            throw new UnsafeWebhookDestination('Webhook destination is not approved.');
        }

        if (! is_array($answers) || ! array_is_list($answers) || $answers === [] || count($answers) > 16) {
            throw new UnsafeWebhookDestination('Webhook destination is not approved.');
        }

        $addresses = [];
        foreach ($answers as $answer) {
            try {
                if (! is_string($answer) || $answer === '') {
                    throw new UnsafeWebhookDestination('Webhook destination is not approved.');
                }
                $address = $this->cidrMatcher->canonicalAddress($answer);
            } catch (UnsafeWebhookDestination $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new UnsafeWebhookDestination('Webhook destination is not approved.');
            }

            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new UnsafeWebhookDestination('Webhook destination is not approved.');
            }

            foreach (self::DENIED_CIDRS as $cidr) {
                if ($this->cidrMatcher->contains($cidr, $address)) {
                    throw new UnsafeWebhookDestination('Webhook destination is not approved.');
                }
            }

            $addresses[] = $address;
        }

        $addresses = array_values(array_unique($addresses));
        usort($addresses, static function (string $left, string $right): int {
            $leftFamily = str_contains($left, ':') ? 6 : 4;
            $rightFamily = str_contains($right, ':') ? 6 : 4;

            return $leftFamily <=> $rightFamily ?: strcmp($left, $right);
        });
        if ($addresses === []) {
            throw new UnsafeWebhookDestination('Webhook destination is not approved.');
        }

        /** @var non-empty-list<string> $addresses */
        return $addresses;
    }

    private function canonicalUrl(ProbeTarget $target): string
    {
        $host = str_contains($target->host, ':') ? "[{$target->host}]" : $target->host;
        $port = $target->port === 443 ? '' : ":{$target->port}";

        return "https://{$host}{$port}".($target->path ?? '/');
    }
}
