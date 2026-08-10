<?php

namespace App\Domain\Monitoring\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductionRetentionEndpointProbe
{
    /** @return array{mysql_endpoint_sha256: string, influx_scope_sha256: string, influx_tls_certificate_sha256: string} */
    public function fingerprints(array $timeSeriesSettings): array
    {
        $connection = DB::connection();
        $identity = $connection->selectOne(
            'SELECT @@server_uuid AS server_uuid, @@hostname AS server_hostname, @@port AS server_port, DATABASE() AS database_name',
        );
        $configuredHost = strtolower(trim((string) $connection->getConfig('host')));
        $configuredPort = (int) ($connection->getConfig('port') ?: 3306);
        if (! is_object($identity)
            || ! is_string($identity->server_uuid ?? null)
            || preg_match('/^[a-f0-9-]{36}$/i', $identity->server_uuid) !== 1
            || ! is_string($identity->server_hostname ?? null)
            || trim($identity->server_hostname) === ''
            || ! is_numeric($identity->server_port ?? null)
            || ! is_string($identity->database_name ?? null)
            || trim($identity->database_name) === '') {
            throw new RuntimeException('Production MySQL endpoint identity is unavailable.');
        }

        $mysql = self::commitment([
            'configured_host' => $configuredHost,
            'configured_port' => $configuredPort,
            'database_name' => (string) $identity->database_name,
            'server_hostname' => strtolower(trim((string) $identity->server_hostname)),
            'server_port' => (int) $identity->server_port,
            'server_uuid' => strtolower((string) $identity->server_uuid),
        ]);
        $tls = $this->influxTlsCertificateFingerprint((string) ($timeSeriesSettings['url'] ?? ''));
        $scope = self::commitment([
            'bucket' => (string) ($timeSeriesSettings['bucket'] ?? ''),
            'organisation' => (string) ($timeSeriesSettings['organisation'] ?? ''),
            'tls_certificate_sha256' => $tls,
            'url' => rtrim((string) ($timeSeriesSettings['url'] ?? ''), '/'),
        ]);

        return [
            'mysql_endpoint_sha256' => $mysql,
            'influx_scope_sha256' => $scope,
            'influx_tls_certificate_sha256' => $tls,
        ];
    }

    /** @param array<string, scalar> $identity */
    public static function commitment(array $identity): string
    {
        ksort($identity, SORT_STRING);

        return hash('sha256', json_encode($identity, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function influxTlsCertificateFingerprint(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)) {
            throw new RuntimeException('Production InfluxDB TLS endpoint is invalid.');
        }
        $host = $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'peer_name' => $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ]]);
        $socket = @stream_socket_client(
            'tls://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            10,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            throw new RuntimeException('Production InfluxDB TLS certificate is unavailable.');
        }
        try {
            $parameters = stream_context_get_params($socket);
            $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
            $fingerprint = $certificate === null ? false : openssl_x509_fingerprint($certificate, 'sha256');
        } finally {
            fclose($socket);
        }
        if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/i', $fingerprint) !== 1) {
            throw new RuntimeException('Production InfluxDB TLS certificate is unavailable.');
        }

        return strtolower($fingerprint);
    }
}
