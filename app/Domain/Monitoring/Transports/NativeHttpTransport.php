<?php

namespace App\Domain\Monitoring\Transports;

use App\Domain\Monitoring\Contracts\HttpTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\HttpTransportResponse;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use RuntimeException;
use Throwable;

final class NativeHttpTransport implements HttpTransport
{
    public function request(AuthorizedProbeTarget $target): HttpTransportResponse
    {
        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Pinned HTTP transport is unavailable.');
        }

        foreach ($target->addresses as $address) {
            try {
                return $this->requestAddress($target, $address);
            } catch (Throwable) {
                continue;
            }
        }

        throw new RuntimeException('Pinned HTTP request failed.');
    }

    private function requestAddress(AuthorizedProbeTarget $target, string $address): HttpTransportResponse
    {
        $latencyMs = 0;
        $response = (new Client)->request('GET', $target->url(), [
            'allow_redirects' => false,
            'connect_timeout' => $target->connectTimeoutSeconds,
            'timeout' => $target->responseTimeoutSeconds,
            'http_errors' => false,
            'stream' => true,
            'decode_content' => false,
            'proxy' => '',
            'verify' => true,
            'headers' => [
                'Accept' => '*/*',
                'User-Agent' => 'OblivionFindings-Monitor/1.0',
            ],
            'curl' => [
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target->host, $target->port, $address)],
            ],
            'on_stats' => function (TransferStats $stats) use (&$latencyMs): void {
                $latencyMs = max(0, (int) round($stats->getTransferTime() * 1000));
            },
        ]);

        $stream = $response->getBody();
        $body = '';
        while (! $stream->eof() && strlen($body) <= $target->maxResponseBytes) {
            $body .= $stream->read(min(8192, $target->maxResponseBytes + 1 - strlen($body)));
        }
        $truncated = strlen($body) > $target->maxResponseBytes || ! $stream->eof();
        if ($truncated) {
            $body = substr($body, 0, $target->maxResponseBytes + 1);
        }

        return new HttpTransportResponse(
            status: $response->getStatusCode(),
            body: $body,
            location: $response->hasHeader('Location') ? $response->getHeaderLine('Location') : null,
            latencyMs: $latencyMs,
            truncated: $truncated,
        );
    }
}
