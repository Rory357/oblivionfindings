<?php

namespace App\Domain\SecurityDevices\Alarms\SiaDc09;

use RuntimeException;

/**
 * TCP and UDP sockets for the alarm receiver (Phase A, item A3).
 *
 * A TCP connection may carry several frames, each ending with CR; every frame gets its own reply before
 * the next is read. The connection closes when the panel closes it, after a short quiet spell, or after
 * MAX_FRAMES_PER_CONNECTION frames. Each UDP datagram is one frame.
 */
final class SiaDc09SocketServer
{
    public const int MAX_FRAMES_PER_CONNECTION = 20;

    /** Seconds to wait for the next frame on an open TCP connection. */
    public const float CONNECTION_IDLE_SECONDS = 3.0;

    /** @var resource|null */
    private $tcp = null;

    /** @var resource|null */
    private $udp = null;

    private string $boundAddress = '';

    public function __construct(private readonly SiaDc09IntakeService $intake) {}

    /**
     * Bind TCP (required) and UDP (if the address allows it) on the same address and port. Port 0 picks
     * a free port; boundAddress() then reports it.
     */
    public function open(string $host, int $port): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false || $port < 0 || $port > 65535) {
            throw new RuntimeException('Give a numeric IP address to listen on and a port from 1 to 65535.');
        }

        $target = (str_contains($host, ':') ? "[{$host}]" : $host).':'.$port;
        $tcp = @stream_socket_server("tcp://{$target}", $errorNumber, $errorText);
        if (! is_resource($tcp)) {
            throw new RuntimeException("The alarm receiver couldn't listen on TCP {$target}: {$errorText} ({$errorNumber}).");
        }
        stream_set_blocking($tcp, false);
        $this->tcp = $tcp;
        $this->boundAddress = (string) stream_socket_get_name($tcp, false);

        $boundPort = (int) substr($this->boundAddress, (int) strrpos($this->boundAddress, ':') + 1);
        $udpTarget = (str_contains($host, ':') ? "[{$host}]" : $host).':'.$boundPort;
        $udp = @stream_socket_server("udp://{$udpTarget}", $udpErrorNumber, $udpErrorText, STREAM_SERVER_BIND);
        if (is_resource($udp)) {
            stream_set_blocking($udp, false);
            $this->udp = $udp;
        }
    }

    public function boundAddress(): string
    {
        return $this->boundAddress;
    }

    public function listensOnUdp(): bool
    {
        return is_resource($this->udp);
    }

    /**
     * Wait up to `$timeoutSeconds` for a panel, then serve whatever arrived.
     *
     * @return int how many frames were answered or refused
     */
    public function poll(float $timeoutSeconds): int
    {
        if (! is_resource($this->tcp)) {
            throw new RuntimeException('The alarm receiver is not open.');
        }

        $read = [$this->tcp];
        if (is_resource($this->udp)) {
            $read[] = $this->udp;
        }
        $write = null;
        $except = null;
        $seconds = (int) floor($timeoutSeconds);
        $microseconds = (int) round(($timeoutSeconds - $seconds) * 1_000_000);

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready === false || $ready === 0) {
            return 0;
        }

        $handled = 0;
        if (in_array($this->tcp, $read, true)) {
            $peer = '';
            $client = @stream_socket_accept($this->tcp, 1.0, $peer);
            if (is_resource($client)) {
                try {
                    $handled += $this->serveTcpClient($client, $peer);
                } finally {
                    @fclose($client);
                }
            }
        }

        if (is_resource($this->udp) && in_array($this->udp, $read, true)) {
            $peer = '';
            $datagram = @stream_socket_recvfrom($this->udp, SiaDc09FrameDecoder::MAX_FRAME_BYTES, 0, $peer);
            $senderIp = self::peerIp($peer);
            if (is_string($datagram) && trim($datagram) !== '' && $senderIp !== null) {
                $result = $this->intake->ingest($datagram, $senderIp);
                if (is_string($result['response_frame']) && $result['response_frame'] !== '') {
                    @stream_socket_sendto($this->udp, $result['response_frame'], 0, $peer);
                }
                $handled++;
            }
        }

        return $handled;
    }

    public function close(): void
    {
        foreach ([$this->tcp, $this->udp] as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
        $this->tcp = null;
        $this->udp = null;
    }

    public static function peerIp(string $peer): ?string
    {
        $trimmed = trim($peer);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, '[')) {
            $close = strpos($trimmed, ']');

            return $close !== false ? substr($trimmed, 1, $close - 1) : null;
        }
        $lastColon = strrpos($trimmed, ':');
        $ip = $lastColon !== false ? substr($trimmed, 0, $lastColon) : $trimmed;

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /** @param resource $client */
    private function serveTcpClient($client, string $peer): int
    {
        $senderIp = self::peerIp($peer);
        if ($senderIp === null) {
            return 0;
        }

        stream_set_blocking($client, true);
        $idle = self::CONNECTION_IDLE_SECONDS;
        stream_set_timeout($client, (int) floor($idle), (int) round(($idle - floor($idle)) * 1_000_000));

        $buffer = '';
        $handled = 0;
        while ($handled < self::MAX_FRAMES_PER_CONNECTION) {
            $chunk = @fread($client, SiaDc09FrameDecoder::MAX_FRAME_BYTES);
            $gotData = is_string($chunk) && $chunk !== '';
            if ($gotData) {
                $buffer .= $chunk;
            }

            while (($end = strpos($buffer, "\r")) !== false && $handled < self::MAX_FRAMES_PER_CONNECTION) {
                $frame = substr($buffer, 0, $end + 1);
                $buffer = (string) substr($buffer, $end + 1);
                if (trim($frame) === '') {
                    continue;
                }
                $this->answer($client, $frame, $senderIp);
                $handled++;
            }

            if (strlen($buffer) > SiaDc09FrameDecoder::MAX_FRAME_BYTES) {
                break;
            }

            if (! $gotData) {
                $meta = stream_get_meta_data($client);
                if (($meta['timed_out'] ?? false) || feof($client)) {
                    break;
                }
            }
        }

        // A sender that closes without a final CR still gets its last frame read.
        if (trim($buffer) !== '' && $handled < self::MAX_FRAMES_PER_CONNECTION) {
            $this->answer($client, $buffer, $senderIp);
            $handled++;
        }

        return $handled;
    }

    /** @param resource $client */
    private function answer($client, string $frame, string $senderIp): void
    {
        $result = $this->intake->ingest($frame, $senderIp);
        if (is_string($result['response_frame']) && $result['response_frame'] !== '') {
            @fwrite($client, $result['response_frame']);
        }
    }
}
