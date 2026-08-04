<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Protocols\Syslog\SyslogDecoder;
use App\Domain\Monitoring\Protocols\Syslog\SyslogIntakeService;
use App\Domain\Monitoring\Services\ListenerHeartbeatReporter;
use App\Domain\Monitoring\Services\UdpListenerLiveness;
use App\Domain\Monitoring\Services\UdpPeerAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MonitoringListenSyslog extends Command
{
    protected $signature = 'monitoring:listen-syslog {--once : Stop after one datagram}';

    protected $description = 'Listen for bounded syslog datagrams and enqueue signed monitoring events';

    public function handle(
        SyslogIntakeService $intake,
        ListenerHeartbeatReporter $heartbeat,
        UdpListenerLiveness $liveness,
    ): int {
        $bind = config('monitoring.inbound.syslog.bind', '127.0.0.1');
        $port = config('monitoring.inbound.syslog.port', 5514);
        $maximum = config('monitoring.inbound.syslog.max_datagram_bytes', SyslogDecoder::MAX_DATAGRAM_BYTES);
        $allowlist = config('monitoring.inbound.bind_allowlist', ['127.0.0.1', '::1']);
        if (! is_string($bind) || filter_var($bind, FILTER_VALIDATE_IP) === false
            || ! is_array($allowlist) || ! in_array($bind, $allowlist, true)
            || ! is_int($port) || $port < 1 || $port > 65_535
            || $maximum !== SyslogDecoder::MAX_DATAGRAM_BYTES) {
            $this->error('Syslog listener configuration is invalid.');

            return self::FAILURE;
        }
        $host = str_contains($bind, ':') ? "[{$bind}]" : $bind;
        $socket = @stream_socket_server("udp://{$host}:{$port}", $errorCode, $errorMessage, STREAM_SERVER_BIND);
        unset($errorMessage);
        if (! is_resource($socket)) {
            Log::error('Syslog listener could not bind.', ['error_code' => $errorCode]);

            return self::FAILURE;
        }
        $liveness->prepare($socket);
        $heartbeat->started('syslog');
        try {
            do {
                if (! $liveness->wait($socket, $heartbeat, 'syslog')) {
                    continue;
                }
                $peer = null;
                $datagram = @stream_socket_recvfrom($socket, SyslogDecoder::MAX_DATAGRAM_BYTES + 1, 0, $peer);
                if (! is_string($datagram) || ! is_string($peer)) {
                    continue;
                }
                $heartbeat->received('syslog');
                $sender = UdpPeerAddress::parse($peer);
                if ($sender === null) {
                    $heartbeat->rejected('syslog');
                    Log::warning('Syslog datagram had an invalid sender address.');

                    continue;
                }
                try {
                    $intake->ingest($datagram, $sender);
                    $heartbeat->accepted('syslog');
                } catch (Throwable) {
                    $heartbeat->rejected('syslog');
                    Log::warning('Syslog datagram was rejected.');
                }
            } while (! $this->option('once'));
        } finally {
            fclose($socket);
        }

        return self::SUCCESS;
    }
}
