<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09FrameDecoder;
use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09IntakeService;
use Illuminate\Console\Command;

/**
 * Bounded TCP & UDP ANSI/SIA DC-09 IP alarm receiver listener.
 */
final class ListenSiaDc09Command extends Command
{
    protected $signature = 'security-devices:listen-sia-dc09
        {--host=127.0.0.1 : Local IP address to bind}
        {--port=51100 : TCP and UDP port to listen on}
        {--once : Process a single inbound frame and exit (useful for health checks and tests)}';

    protected $description = 'Listen for inbound ANSI/SIA DC-09 (Ademco Contact ID / SIA-DCS) IP alarm frames over TCP and UDP';

    public function handle(SiaDc09IntakeService $intake): int
    {
        $host = trim((string) $this->option('host'));
        $port = (int) $this->option('port');
        $once = (bool) $this->option('once');

        if (filter_var($host, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            $this->error('Provide a valid numeric bind address and port (1-65535).');

            return self::INVALID;
        }

        $bindTarget = (str_contains($host, ':') ? "[{$host}]" : $host).':'.$port;
        $tcpServer = @stream_socket_server("tcp://{$bindTarget}", $tcpErrNo, $tcpErrStr);
        if (! is_resource($tcpServer)) {
            $this->error("Failed to bind TCP SIA DC-09 socket on {$bindTarget}: {$tcpErrStr} ({$tcpErrNo})");

            return self::FAILURE;
        }
        stream_set_blocking($tcpServer, false);

        $udpServer = @stream_socket_server("udp://{$bindTarget}", $udpErrNo, $udpErrStr, STREAM_SERVER_BIND);
        if (is_resource($udpServer)) {
            stream_set_blocking($udpServer, false);
        }

        $this->info("Listening for SIA DC-09 alarm frames on {$bindTarget}...");

        try {
            while (true) {
                $read = [$tcpServer];
                if (is_resource($udpServer)) {
                    $read[] = $udpServer;
                }
                $write = null;
                $except = null;

                $ready = @stream_select($read, $write, $except, 1, 0);
                if ($ready === false || $ready === 0) {
                    continue;
                }

                if (in_array($tcpServer, $read, true)) {
                    $peerName = '';
                    $client = @stream_socket_accept($tcpServer, 1.0, $peerName);
                    if (is_resource($client)) {
                        try {
                            stream_set_timeout($client, 2, 0);
                            $raw = (string) @fread($client, SiaDc09FrameDecoder::MAX_FRAME_BYTES);
                            $senderIp = $this->extractPeerIp($peerName);
                            if ($raw !== '' && $senderIp !== null) {
                                $result = $intake->ingest($raw, $senderIp);
                                if (is_string($result['response_frame']) && $result['response_frame'] !== '') {
                                    @fwrite($client, $result['response_frame']);
                                }
                            }
                        } finally {
                            @fclose($client);
                        }
                        if ($once) {
                            return self::SUCCESS;
                        }
                    }
                }

                if (is_resource($udpServer) && in_array($udpServer, $read, true)) {
                    $peerName = '';
                    $datagram = @stream_socket_recvfrom($udpServer, SiaDc09FrameDecoder::MAX_FRAME_BYTES, 0, $peerName);
                    $senderIp = $this->extractPeerIp($peerName);
                    if (is_string($datagram) && $datagram !== '' && $senderIp !== null) {
                        $result = $intake->ingest($datagram, $senderIp);
                        if (is_string($result['response_frame']) && $result['response_frame'] !== '') {
                            @stream_socket_sendto($udpServer, $result['response_frame'], 0, $peerName);
                        }
                        if ($once) {
                            return self::SUCCESS;
                        }
                    }
                }
            }
        } finally {
            @fclose($tcpServer);
            if (is_resource($udpServer)) {
                @fclose($udpServer);
            }
        }
    }

    private function extractPeerIp(string $peer): ?string
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
}
