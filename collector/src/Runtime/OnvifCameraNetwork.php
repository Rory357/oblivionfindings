<?php

namespace Oblivion\Collector\Runtime;

/**
 * The network steps an ONVIF camera picture needs (S&D Phase E, R5), behind one interface so tests
 * can stand in for a camera: read a TLS certificate without trusting it, and make one request
 * (SOAP POST or picture GET) over plain HTTP or pinned HTTPS. Never follows redirects.
 */
interface OnvifCameraNetwork
{
    /**
     * Same shape as CameraHubNetwork::inspectCertificate().
     *
     * @return array{pin: string, fingerprint: string, subject: string|null, valid_to: int|null, publicly_trusted: bool}|null
     */
    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array;

    /**
     * Status 0 means no answer. `challenge` is the WWW-Authenticate header of a 401, or ''.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string, truncated: bool, challenge: string}
     */
    public function request(string $method, string $url, array $headers, ?string $body, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array;
}
