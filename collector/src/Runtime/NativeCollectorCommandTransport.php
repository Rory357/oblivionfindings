<?php

namespace Oblivion\Collector\Runtime;

use RuntimeException;

final class NativeCollectorCommandTransport implements CollectorCommandTransport
{
    public function request(
        array $endpoint,
        string $method,
        string $path,
        array $headers,
        ?array $json = null,
    ): array {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'PUT'], true)
            || ($method === 'GET' && $json !== null)
            || ! in_array($path, [
                '/api/v1/developer/doors/'.$endpoint['door_id'],
                '/api/v1/developer/doors/'.$endpoint['door_id'].'/unlock',
            ], true)) {
            throw new RuntimeException('Collector command HTTP request is invalid.');
        }
        $body = $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (is_string($body) && strlen($body) > 16_384) {
            throw new RuntimeException('Collector command HTTP request is too large.');
        }
        foreach ($headers as $name => $value) {
            if (! in_array($name, ['Accept', 'Authorization', 'Content-Type'], true)
                || ! is_string($value) || $value === '' || strlen($value) > 4096
                || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new RuntimeException('Collector command HTTP headers are invalid.');
            }
        }
        if (! isset($headers['Authorization'])
            || preg_match('/^Bearer [A-Za-z0-9+\/=._-]{8,4090}$/', $headers['Authorization']) !== 1) {
            throw new RuntimeException('Collector command HTTP authentication is invalid.');
        }

        $host = (string) $endpoint['host'];
        $port = (int) $endpoint['port'];
        $displayHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $url = 'https://'.$displayHost.($port === 443 ? '' : ':'.$port).$path;
        $handle = curl_init($url);
        if ($handle === false || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Pinned collector command transport is unavailable.');
        }
        $responseBody = '';
        $location = null;
        $maximum = (int) $endpoint['max_response_bytes'];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => (int) $endpoint['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => (int) $endpoint['response_timeout_seconds'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $endpoint['address'])],
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$responseBody, $maximum): int {
                if (strlen($responseBody) + strlen($chunk) > $maximum) {
                    return 0;
                }
                $responseBody .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($ok === false) {
            throw new RuntimeException('Collector command transport did not return a confirmed response.');
        }

        return ['status' => $status, 'body' => $responseBody, 'location' => $location];
    }
}
