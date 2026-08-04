<?php

namespace App\Domain\Monitoring\Services;

use RuntimeException;

final class UdpListenerLiveness
{
    private const WAIT_SECONDS = 5;

    /** @param resource $socket */
    public function prepare($socket): void
    {
        if (! is_resource($socket) || ! stream_set_blocking($socket, false)) {
            throw new RuntimeException('Monitoring listener liveness could not prepare the socket.');
        }
    }

    /** @param resource $socket */
    public function wait($socket, ListenerHeartbeatReporter $heartbeat, string $listener): bool
    {
        if (! is_resource($socket)) {
            throw new RuntimeException('Monitoring listener socket is unavailable.');
        }

        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, self::WAIT_SECONDS);
        if ($ready === false) {
            throw new RuntimeException('Monitoring listener readiness check failed.');
        }

        $heartbeat->alive($listener);

        return $ready > 0;
    }
}
