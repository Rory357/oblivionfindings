<?php

namespace App\Domain\SecurityDevices\Management\Transports;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

final class NativeCommandHttpTransport implements CommandHttpTransport
{
    private const MAX_RESPONSE_BYTES = 65_536;

    public function __construct(private readonly ?ClientInterface $client = null) {}

    public function request(
        AuthorizedProbeTarget $target,
        string $method,
        array $headers = [],
        ?array $json = null,
    ): CommandHttpResponse {
        $method = strtoupper($method);
        if ($target->scheme !== 'https'
            || ! in_array($method, ['GET', 'PUT'], true)
            || ($method === 'GET' && $json !== null)) {
            throw new RuntimeException('Command HTTP request is invalid.');
        }
        $this->assertHeaders($headers);
        if ($json !== null) {
            try {
                $encoded = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                throw new RuntimeException('Command HTTP request is invalid.');
            }
            if (strlen($encoded) > 16_384) {
                throw new RuntimeException('Command HTTP request is too large.');
            }
            unset($encoded);
        }
        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Pinned command transport is unavailable.');
        }

        // A provider command may have executed even when its response is lost.
        // Pin one approved address and surface ambiguity to reconciliation;
        // never fail over and risk issuing the same side effect twice.
        return $this->requestAddress($target, $target->addresses[0], $method, $headers, $json);
    }

    /** @param array<string, string> $headers @param array<string, mixed>|null $json */
    private function requestAddress(
        AuthorizedProbeTarget $target,
        string $address,
        string $method,
        array $headers,
        ?array $json,
    ): CommandHttpResponse {
        $options = [
            'allow_redirects' => false,
            'connect_timeout' => min(10, $target->connectTimeoutSeconds),
            'timeout' => min(30, $target->responseTimeoutSeconds),
            'http_errors' => false,
            'stream' => true,
            'decode_content' => false,
            'proxy' => '',
            'verify' => true,
            'headers' => $headers,
            'curl' => [
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target->host, $target->port, $address)],
            ],
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $response = ($this->client ?? new Client)->request($method, $target->url(), $options);
        $stream = $response->getBody();
        $limit = min(self::MAX_RESPONSE_BYTES, $target->maxResponseBytes);
        $body = '';
        while (! $stream->eof() && strlen($body) <= $limit) {
            $body .= $stream->read(min(8192, $limit + 1 - strlen($body)));
        }
        if (strlen($body) > $limit || ! $stream->eof()) {
            throw new RuntimeException('Command provider response exceeded the safe limit.');
        }

        return new CommandHttpResponse(
            status: $response->getStatusCode(),
            body: $body,
            location: $response->hasHeader('Location') ? $response->getHeaderLine('Location') : null,
        );
    }

    /** @param array<string, string> $headers */
    private function assertHeaders(array $headers): void
    {
        $allowed = ['Accept', 'Authorization', 'Content-Type'];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! in_array($name, $allowed, true)
                || ! is_string($value) || $value === '' || strlen($value) > 4096
                || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new RuntimeException('Command HTTP headers are invalid.');
            }
        }
        if (! isset($headers['Authorization'])
            || preg_match('/^Bearer [A-Za-z0-9+\/=._-]{8,4090}$/', $headers['Authorization']) !== 1) {
            throw new RuntimeException('Command HTTP authentication is invalid.');
        }
    }
}
