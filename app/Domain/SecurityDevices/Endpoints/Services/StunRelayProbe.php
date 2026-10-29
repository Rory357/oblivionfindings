<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

/**
 * Checks that a remote-support relay (a TURN server such as coturn) answers (Phase A, item A2).
 *
 * Sends one STUN Binding Request (RFC 5389) to the relay address and waits for a Binding response with
 * the same transaction ID. TURN servers answer these by default. It proves the relay is reachable and
 * speaking STUN; it does not prove the shared secret matches. That is proved by a real remote-support
 * session (Phase G).
 *
 * `turn:host:port` uses UDP unless `?transport=tcp`; `turns:host:port` uses TLS with certificate checks on.
 */
class StunRelayProbe
{
    private const int MAGIC_COOKIE = 0x2112A442;

    private const int BINDING_REQUEST = 0x0001;

    private const int BINDING_SUCCESS = 0x0101;

    private const int BINDING_ERROR = 0x0111;

    /**
     * @return array{answered: bool, milliseconds: int|null, problem: string|null}
     */
    public function probe(string $relayUrl, float $timeoutSeconds = 3.0): array
    {
        if (preg_match('/\A(turns?):(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.-]+):([0-9]{1,5})(?:\?transport=(udp|tcp))?\z/', $relayUrl, $parts) !== 1) {
            return ['answered' => false, 'milliseconds' => null, 'problem' => 'not a turn: or turns: address'];
        }

        $host = $parts[2];
        $port = (int) $parts[3];
        $scheme = match (true) {
            $parts[1] === 'turns' => 'tls',
            ($parts[4] ?? 'udp') === 'tcp' => 'tcp',
            default => 'udp',
        };

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => trim($host, '[]'),
            'SNI_enabled' => true,
        ]]);

        $started = hrtime(true);
        // A relay that can't be reached is an expected answer here, not a PHP warning.
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client("{$scheme}://{$host}:{$port}", $errorNumber, $errorText, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($socket)) {
            return ['answered' => false, 'milliseconds' => null, 'problem' => $scheme === 'tls' ? 'no secure connection' : 'no connection'];
        }

        try {
            $transactionId = random_bytes(12);
            stream_set_timeout($socket, (int) floor($timeoutSeconds), (int) round(($timeoutSeconds - floor($timeoutSeconds)) * 1_000_000));
            if (@fwrite($socket, self::bindingRequest($transactionId)) === false) {
                return ['answered' => false, 'milliseconds' => null, 'problem' => 'could not send'];
            }

            $reply = $this->readMessage($socket, $scheme === 'udp');
            $verdict = self::readBindingResponse($reply, $transactionId);
            if ($verdict === null) {
                return ['answered' => false, 'milliseconds' => null, 'problem' => $reply === '' ? 'no answer' : 'answer was not STUN'];
            }

            return [
                'answered' => true,
                'milliseconds' => (int) round((hrtime(true) - $started) / 1_000_000),
                'problem' => $verdict === 'success' ? null : 'the relay answered with a STUN error',
            ];
        } finally {
            @fclose($socket);
        }
    }

    /** A STUN Binding Request: type, zero length, magic cookie, 96-bit transaction ID. */
    public static function bindingRequest(string $transactionId): string
    {
        return pack('nnN', self::BINDING_REQUEST, 0, self::MAGIC_COOKIE).$transactionId;
    }

    /**
     * 'success' or 'error' for a Binding response to this transaction, otherwise null.
     */
    public static function readBindingResponse(string $message, string $transactionId): ?string
    {
        if (strlen($message) < 20) {
            return null;
        }

        $header = unpack('ntype/nlength/Ncookie', substr($message, 0, 8));
        if (! is_array($header)
            || $header['cookie'] !== self::MAGIC_COOKIE
            || ! hash_equals($transactionId, substr($message, 8, 12))
            || strlen($message) < 20 + $header['length']) {
            return null;
        }

        return match ($header['type']) {
            self::BINDING_SUCCESS => 'success',
            self::BINDING_ERROR => 'error',
            default => null,
        };
    }

    /** @param resource $socket */
    private function readMessage($socket, bool $datagram): string
    {
        if ($datagram) {
            $reply = @fread($socket, 1500);

            return is_string($reply) ? $reply : '';
        }

        $message = '';
        while (strlen($message) < 20) {
            $chunk = @fread($socket, 20 - strlen($message));
            if (! is_string($chunk) || $chunk === '') {
                return $message;
            }
            $message .= $chunk;
        }

        $length = unpack('n', substr($message, 2, 2))[1] ?? 0;
        $length = min($length, 1500);
        while (strlen($message) < 20 + $length) {
            $chunk = @fread($socket, 20 + $length - strlen($message));
            if (! is_string($chunk) || $chunk === '') {
                break;
            }
            $message .= $chunk;
        }

        return $message;
    }
}
