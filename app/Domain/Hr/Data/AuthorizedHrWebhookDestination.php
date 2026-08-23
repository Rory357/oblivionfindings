<?php

namespace App\Domain\Hr\Data;

final readonly class AuthorizedHrWebhookDestination
{
    /** @param non-empty-list<string> $addresses */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public array $addresses,
    ) {}

    public function requiresDnsPin(): bool
    {
        return filter_var($this->host, FILTER_VALIDATE_IP) === false;
    }

    public function curlResolveEntry(): string
    {
        $address = $this->addresses[0];
        if (str_contains($address, ':')) {
            $address = "[{$address}]";
        }

        return "{$this->host}:{$this->port}:{$address}";
    }
}
