<?php

namespace App\Services\Chat;

/**
 * The public side of Oblivion Relay as a browser sees it (spec 9, "In the app"), with PHP's built-in
 * sockets: one unauthenticated Allocate over UDP, which must get a 401 whether Binding is on or
 * off, and a TLS handshake that reads the certificate's expiry. No credential is ever sent.
 */
class ChatRelayProbe
{
    private const COOKIE = "\x21\x12\xA4\x42";

    /** The error code the relay answered an unauthenticated Allocate with, or null for no answer. */
    public function allocateChallenge(string $url, float $timeoutSeconds = 2.0): ?int
    {
        $target = self::target($url);
        if ($target === null || $target['transport'] !== 'udp') {
            return null;
        }
        $socket = @stream_socket_client("udp://{$target['host']}:{$target['port']}", $errorCode, $errorMessage, $timeoutSeconds);
        if ($socket === false) {
            return null;
        }
        try {
            $transactionId = random_bytes(12);
            stream_set_timeout($socket, (int) $timeoutSeconds, (int) (fmod($timeoutSeconds, 1) * 1_000_000));
            if (fwrite($socket, self::allocateRequest($transactionId)) === false) {
                return null;
            }
            $response = fread($socket, 2048);

            return is_string($response) ? self::errorCode($response, $transactionId) : null;
        } finally {
            fclose($socket);
        }
    }

    /** The certificate's expiry (Unix seconds) from a TLS handshake with the turns: URL, or null. */
    public function certificateExpiry(string $url, float $timeoutSeconds = 3.0): ?int
    {
        $target = self::target($url);
        if ($target === null || $target['transport'] !== 'tls') {
            return null;
        }
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => trim($target['host'], '[]'), 'SNI_enabled' => true,
        ]]);
        $socket = @stream_socket_client("tls://{$target['host']}:{$target['port']}", $errorCode, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return null;
        }
        try {
            $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            $parsed = $certificate ? openssl_x509_parse($certificate) : false;

            return is_array($parsed) && isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        } finally {
            fclose($socket);
        }
    }

    /** An Allocate request with REQUESTED-TRANSPORT UDP and nothing else (RFC 8656 7.1). */
    public static function allocateRequest(string $transactionId): string
    {
        return pack('nn', 0x0003, 8).self::COOKIE.$transactionId.pack('nnN', 0x0019, 4, 17 << 24);
    }

    /** The ERROR-CODE of an Allocate error response to this transaction, or null. */
    public static function errorCode(string $response, string $transactionId): ?int
    {
        if (strlen($response) < 20 || substr($response, 4, 4) !== self::COOKIE || substr($response, 8, 12) !== $transactionId) {
            return null;
        }
        ['type' => $type, 'length' => $length] = unpack('ntype/nlength', $response);
        if ($type !== 0x0113 || 20 + $length !== strlen($response)) {
            return null;
        }
        for ($offset = 20; $offset + 4 <= strlen($response);) {
            ['attribute' => $attribute, 'size' => $size] = unpack('nattribute/nsize', $response, $offset);
            if ($attribute === 0x0009 && $size >= 4) {
                return (ord($response[$offset + 6]) & 0x07) * 100 + ord($response[$offset + 7]);
            }
            $offset += 4 + $size + ((4 - $size % 4) % 4);
        }

        return null;
    }

    /** @return array{transport: string, host: string, port: int}|null */
    public static function target(string $url): ?array
    {
        if (! preg_match('/\A(turns?):(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.-]+):([0-9]{1,5})(?:\?transport=(udp|tcp))?\z/', $url, $parts)) {
            return null;
        }
        $transport = $parts[1] === 'turns' ? 'tls' : (($parts[4] ?? 'udp') === 'tcp' ? 'tcp' : 'udp');

        return ['transport' => $transport, 'host' => $parts[2], 'port' => (int) $parts[3]];
    }
}
