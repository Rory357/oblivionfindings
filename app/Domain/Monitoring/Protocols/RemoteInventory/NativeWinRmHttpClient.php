<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Throwable;

final class NativeWinRmHttpClient implements WinRmHttpClient
{
    public function exchange(
        AuthorizedProbeTarget $target,
        string $address,
        string $soap,
        array $material,
        int $maxResponseBytes,
    ): WinRmHttpResponse {
        if (! defined('CURLOPT_RESOLVE')) {
            throw new WinRmTransportException('transport_unavailable');
        }

        $curl = [
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target->host, $target->port, $address)],
        ];
        $mode = $material['auth_mode'] ?? null;
        if ($mode === 'kerberos') {
            if (! defined('CURLOPT_HTTPAUTH') || ! defined('CURLAUTH_NEGOTIATE') || ! defined('CURLOPT_USERPWD')) {
                throw new WinRmTransportException('transport_unavailable');
            }
            $curl[CURLOPT_HTTPAUTH] = CURLAUTH_NEGOTIATE;
            $curl[CURLOPT_USERPWD] = $material['username'].':'.$material['password'];
        } elseif ($mode === 'certificate') {
            if (! defined('CURLOPT_SSLCERT_BLOB') || ! defined('CURLOPT_SSLKEY_BLOB')) {
                throw new WinRmTransportException('transport_unavailable');
            }
            $curl[CURLOPT_SSLCERT_BLOB] = $material['certificate_pem'];
            $curl[CURLOPT_SSLKEY_BLOB] = $material['private_key_pem'];
            if (is_string($material['private_key_passphrase'] ?? null) && defined('CURLOPT_KEYPASSWD')) {
                $curl[CURLOPT_KEYPASSWD] = $material['private_key_passphrase'];
            }
        } else {
            throw new WinRmTransportException('authentication_failed');
        }

        $host = str_contains($target->host, ':') ? "[{$target->host}]" : $target->host;
        $url = "https://{$host}:{$target->port}/wsman";
        $latencyMs = 0;
        try {
            $response = (new Client)->request('POST', $url, [
                'allow_redirects' => false,
                'connect_timeout' => min($target->connectTimeoutSeconds, 15),
                'timeout' => min($target->responseTimeoutSeconds, 15),
                'http_errors' => false,
                'stream' => true,
                'decode_content' => false,
                'proxy' => '',
                'verify' => true,
                'headers' => [
                    'Accept' => 'application/soap+xml',
                    'Content-Type' => 'application/soap+xml; charset=UTF-8',
                    'User-Agent' => 'OblivionFindings-Monitor/1.0',
                ],
                'body' => $soap,
                'curl' => $curl,
                'on_stats' => function (TransferStats $stats) use (&$latencyMs): void {
                    $latencyMs = max(0, (int) round($stats->getTransferTime() * 1000));
                },
            ]);
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $reason = match (true) {
                str_contains($message, 'certificate'), str_contains($message, 'ssl'), str_contains($message, 'hostname') => 'certificate_mismatch',
                str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
                default => 'transport_unavailable',
            };

            throw new WinRmTransportException($reason);
        }

        $stream = $response->getBody();
        $body = '';
        while (! $stream->eof() && strlen($body) <= $maxResponseBytes) {
            $body .= $stream->read(min(8192, $maxResponseBytes + 1 - strlen($body)));
        }
        $truncated = strlen($body) > $maxResponseBytes || ! $stream->eof();

        return new WinRmHttpResponse(
            status: $response->getStatusCode(),
            body: substr($body, 0, $maxResponseBytes + 1),
            latencyMs: $latencyMs,
            truncated: $truncated,
        );
    }
}
