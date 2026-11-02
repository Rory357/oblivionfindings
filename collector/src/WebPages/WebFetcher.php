<?php

namespace Oblivion\Collector\WebPages;

/**
 * One HTTP(S) request to a device's own web page (S&D Phase G), behind an interface so tests can
 * stand in for a printer.
 */
interface WebFetcher
{
    /**
     * Never follows redirects. https only with $pin (the device's trusted key). Status 0 = no answer.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, list<string>>, body: string, truncated: bool}
     */
    public function fetch(string $url, string $method, array $headers, string $body, ?string $pin, int $maxBytes): array;
}
