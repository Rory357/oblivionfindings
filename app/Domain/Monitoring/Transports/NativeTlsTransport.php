<?php

namespace App\Domain\Monitoring\Transports;

use App\Domain\Monitoring\Contracts\TlsTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\TlsTransportResult;
use Carbon\CarbonImmutable;

final class NativeTlsTransport implements TlsTransport
{
    public function probe(AuthorizedProbeTarget $target): TlsTransportResult
    {
        foreach ($target->addresses as $address) {
            $context = stream_context_create(['ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $target->host,
                'SNI_enabled' => true,
                'capture_peer_cert' => true,
                'disable_compression' => true,
            ]]);
            $endpoint = sprintf('tls://%s:%d', str_contains($address, ':') ? "[{$address}]" : $address, $target->port);
            $started = hrtime(true);
            $socket = @stream_socket_client(
                $endpoint,
                $errorCode,
                $errorMessage,
                $target->connectTimeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            $latency = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
            if (! is_resource($socket)) {
                continue;
            }

            stream_set_timeout($socket, $target->responseTimeoutSeconds);
            $metadata = stream_get_meta_data($socket);
            $parameters = stream_context_get_params($socket);
            fclose($socket);
            $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
            $parsed = $certificate !== null ? openssl_x509_parse($certificate, false) : false;
            if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
                return new TlsTransportResult(false, $latency, null, null, false, null, 'tls_verification_failed');
            }

            $issuer = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];
            $issuerHash = hash('sha256', json_encode($issuer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $peerFingerprint = openssl_x509_fingerprint($certificate, 'sha256');
            $protocol = is_string($metadata['crypto']['protocol'] ?? null) ? $metadata['crypto']['protocol'] : null;

            return new TlsTransportResult(
                true,
                $latency,
                CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']),
                $issuerHash,
                true,
                $protocol,
                'verified',
                is_string($peerFingerprint) ? strtolower($peerFingerprint) : null,
            );
        }

        return new TlsTransportResult(false, null, null, null, false, null, 'tls_verification_failed');
    }
}
