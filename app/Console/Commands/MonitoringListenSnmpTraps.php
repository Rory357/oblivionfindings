<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Protocols\Snmp\SnmpTrapIntakeService;
use App\Domain\Monitoring\Services\ListenerHeartbeatReporter;
use App\Domain\Monitoring\Services\UdpListenerLiveness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MonitoringListenSnmpTraps extends Command
{
    protected $signature = 'monitoring:listen-snmp-traps {--once : Stop after one datagram}';

    protected $description = 'Listen for bounded SNMP traps and enqueue authenticated monitoring events';

    public function handle(
        SnmpTrapIntakeService $intake,
        ListenerHeartbeatReporter $heartbeat,
        UdpListenerLiveness $liveness,
    ): int {
        $bind = config('monitoring.snmp.traps.bind', '0.0.0.0');
        $port = config('monitoring.snmp.traps.port', 162);
        $maximum = config('monitoring.snmp.traps.max_datagram_bytes', 65_507);
        if (! is_string($bind) || filter_var($bind, FILTER_VALIDATE_IP) === false
            || ! is_int($port) || $port < 1 || $port > 65_535
            || $maximum !== 65_507) {
            $this->error('SNMP trap listener configuration is invalid.');

            return self::FAILURE;
        }

        $host = str_contains($bind, ':') ? "[{$bind}]" : $bind;
        $socket = @stream_socket_server(
            "udp://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND,
        );
        unset($errorMessage);
        if (! is_resource($socket)) {
            Log::error('SNMP trap listener could not bind.', ['error_code' => $errorCode]);

            return self::FAILURE;
        }
        $liveness->prepare($socket);
        $heartbeat->started('snmp_traps');

        try {
            do {
                if (! $liveness->wait($socket, $heartbeat, 'snmp_traps')) {
                    continue;
                }
                $peer = null;
                $datagram = @stream_socket_recvfrom($socket, 65_507 + 1, 0, $peer);
                if (! is_string($datagram) || ! is_string($peer)) {
                    continue;
                }
                $heartbeat->received('snmp_traps');
                $sender = $this->senderAddress($peer);
                if ($sender === null) {
                    $heartbeat->rejected('snmp_traps');
                    Log::warning('SNMP trap datagram had an invalid sender address.');

                    continue;
                }
                try {
                    $intake->ingest($datagram, $sender);
                    $heartbeat->accepted('snmp_traps');
                } catch (Throwable) {
                    $heartbeat->rejected('snmp_traps');
                    // Datagram contents and credential/provider errors are never
                    // copied to the listener log. Operational counts are exposed
                    // through the durable outbox/DLQ and runtime health surfaces.
                    Log::warning('SNMP trap datagram was rejected.');
                }
            } while (! $this->option('once'));
        } finally {
            fclose($socket);
        }

        return self::SUCCESS;
    }

    private function senderAddress(string $peer): ?string
    {
        if (str_starts_with($peer, '[')) {
            $end = strpos($peer, ']');
            if ($end === false) {
                return null;
            }
            $address = substr($peer, 1, $end - 1);
        } else {
            $separator = strrpos($peer, ':');
            $address = $separator === false ? $peer : substr($peer, 0, $separator);
        }

        return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : null;
    }
}
