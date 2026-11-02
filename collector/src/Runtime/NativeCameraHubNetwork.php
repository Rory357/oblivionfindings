<?php

namespace Oblivion\Collector\Runtime;

/**
 * PHP streams and cURL behind CameraHubNetwork. The certificate reading and the pin match the
 * server's CameraEndpointTrustService, so a pin trusted on the server is the same pin here.
 */
final class NativeCameraHubNetwork implements CameraHubNetwork
{
    public function inspectCertificate(string $address, int $port, int $timeoutSeconds): ?array
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $target = (str_contains($address, ':') ? "[{$address}]" : $address).':'.$port;
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => false,
        ]]);
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('ssl://'.$target, $errorNumber, $errorText, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($socket)) {
            return null;
        }
        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        if ($certificate === null) {
            return null;
        }
        $key = openssl_pkey_get_public($certificate);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        $der = is_array($details) && is_string($details['key'] ?? null)
            ? base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']) ?? '', true) : false;
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (! is_string($der) || $der === '' || ! is_string($fingerprint)) {
            return null;
        }
        $parsed = openssl_x509_parse($certificate) ?: [];
        $subject = $parsed['subject']['CN'] ?? ($parsed['name'] ?? null);

        return [
            'pin' => 'sha256//'.base64_encode(hash('sha256', $der, true)),
            'fingerprint' => strtoupper(implode(':', str_split(strtolower($fingerprint), 2))),
            'subject' => is_array($subject) ? substr(implode(', ', $subject), 0, 200) : (is_string($subject) ? substr($subject, 0, 200) : null),
            'valid_to' => isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null,
            'publicly_trusted' => $this->publiclyTrusted($target, $address, $timeoutSeconds),
        ];
    }

    public function get(string $url, array $headers, ?string $pin, int $connectTimeoutSeconds, int $responseTimeoutSeconds, int $maxBytes): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'truncated' => false];
        }
        $body = '';
        $truncated = false;
        $options = [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => max(1, min(10, $connectTimeoutSeconds)),
            CURLOPT_TIMEOUT => max(1, min(30, $responseTimeoutSeconds)),
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name.': '.$value, array_keys($headers), $headers),
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $truncated = true;

                    return 0; // stop reading
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($pin !== null) {
            // The pin is the check: cURL refuses any other public key before sending a byte.
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
            $options[CURLOPT_PINNEDPUBLICKEY] = $pin;
        } else {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($truncated) {
            return ['status' => $status, 'body' => '', 'truncated' => true];
        }

        return ['status' => $ok === false ? 0 : $status, 'body' => $ok === false ? '' : $body, 'truncated' => false];
    }

    private function publiclyTrusted(string $target, string $address, int $timeoutSeconds): bool
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $address,
        ]]);
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('ssl://'.$target, $errorNumber, $errorText, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($socket)) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
