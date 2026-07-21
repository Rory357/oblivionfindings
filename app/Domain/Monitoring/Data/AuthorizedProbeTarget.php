<?php

namespace App\Domain\Monitoring\Data;

final readonly class AuthorizedProbeTarget
{
    /** @param non-empty-list<string> $addresses */
    private function __construct(
        public int $siteId,
        public int $deviceId,
        public string $scheme,
        public string $host,
        public int $port,
        public ?string $path,
        public array $addresses,
        public int $connectTimeoutSeconds,
        public int $responseTimeoutSeconds,
        public int $maxResponseBytes,
    ) {}

    /**
     * Internal construction seam guarded by the monitoring architecture test.
     *
     * @param  non-empty-list<string>  $addresses
     */
    public static function fromEgressPolicy(
        int $siteId,
        int $deviceId,
        string $scheme,
        string $host,
        int $port,
        ?string $path,
        array $addresses,
        int $connectTimeoutSeconds,
        int $responseTimeoutSeconds,
        int $maxResponseBytes,
    ): self {
        return new self(
            $siteId,
            $deviceId,
            $scheme,
            $host,
            $port,
            $path,
            $addresses,
            $connectTimeoutSeconds,
            $responseTimeoutSeconds,
            $maxResponseBytes,
        );
    }

    public function url(): string
    {
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;
        $defaultPort = ($this->scheme === 'http' && $this->port === 80)
            || ($this->scheme === 'https' && $this->port === 443);

        return "{$this->scheme}://{$host}".($defaultPort ? '' : ":{$this->port}").($this->path ?? '/');
    }
}
