<?php

namespace App\Domain\Monitoring\Transports;

use App\Domain\Monitoring\Contracts\TcpTransport;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\TcpTransportResult;

final class NativeTcpTransport implements TcpTransport
{
    public function probe(AuthorizedProbeTarget $target): TcpTransportResult
    {
        foreach ($target->addresses as $address) {
            $endpoint = sprintf('tcp://%s:%d', str_contains($address, ':') ? "[{$address}]" : $address, $target->port);
            $started = hrtime(true);
            $socket = @stream_socket_client(
                $endpoint,
                $errorCode,
                $errorMessage,
                $target->connectTimeoutSeconds,
                STREAM_CLIENT_CONNECT,
            );
            $latency = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
            if (is_resource($socket)) {
                fclose($socket);

                return new TcpTransportResult(true, $latency, 'connected');
            }
        }

        return new TcpTransportResult(false, null, 'connection_refused');
    }
}
