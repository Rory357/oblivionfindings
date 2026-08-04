<?php

namespace Oblivion\Collector\Runtime;

interface CollectorCommandTransport
{
    /**
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     * @return array{status: int, body: string, location: ?string}
     */
    public function request(
        array $endpoint,
        string $method,
        string $path,
        array $headers,
        ?array $json = null,
    ): array;
}
