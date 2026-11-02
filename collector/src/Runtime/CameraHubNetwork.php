<?php

namespace Oblivion\Collector\Runtime;

/**
 * The two network steps a hub camera job needs, behind one interface so tests can stand in for a
 * Protect console: read a TLS certificate without trusting it, and make a pinned HTTPS GET.
 */
interface CameraHubNetwork
{
    /**
     * The certificate an address presents, read without sending anything else. Same shapes as the
     * server's CameraEndpointTrustService::inspect().
     *
     * @return array{pin: string, fingerprint: string, subject: string|null, valid_to: int|null, publicly_trusted: bool}|null
     */
    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array;

    /**
     * A GET that accepts only the pinned public key (or a publicly valid certificate when $pin is
     * null). Status 0 means no answer.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string, truncated: bool}
     */
    public function get(string $url, array $headers, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array;
}
