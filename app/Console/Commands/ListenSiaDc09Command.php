<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09SocketServer;
use App\Domain\SecurityDevices\Services\ListenerHeartbeat;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The alarm receiver: answers ANSI/SIA DC-09 alarm reports over TCP and UDP.
 *
 * Supervisor keeps it running on the server (ops/supervisor/oblivion-sd-listeners.conf). The address and
 * port come from config (SIA_DC09_BIND_HOST, SIA_DC09_PORT) unless given here.
 */
final class ListenSiaDc09Command extends Command
{
    protected $signature = 'security-devices:listen-sia-dc09
        {--host= : IP address to listen on (default: SIA_DC09_BIND_HOST, else 127.0.0.1)}
        {--port= : TCP and UDP port (default: SIA_DC09_PORT, else 51100)}
        {--once : Answer the first frame or connection, then stop (for checks and tests)}';

    protected $description = 'Run the alarm receiver for SIA DC-09 reports (Contact ID and SIA-DCS) over TCP and UDP';

    public function handle(SiaDc09SocketServer $server, ListenerHeartbeat $heartbeat): int
    {
        $host = trim((string) ($this->option('host') ?? '')) ?: (string) config('security_devices.listeners.sia_dc09.bind_host', '127.0.0.1');
        $port = (int) (($this->option('port') ?? '') !== '' ? $this->option('port') : config('security_devices.listeners.sia_dc09.port', 51100));
        $once = (bool) $this->option('once');

        if ($port < 1) {
            $this->error('Give a port from 1 to 65535.');

            return self::INVALID;
        }

        try {
            $server->open($host, $port);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Alarm receiver listening on %s (TCP%s).',
            $server->boundAddress(),
            $server->listensOnUdp() ? ' and UDP' : ' only; UDP could not bind',
        ));

        $heartbeat->beat(ListenerHeartbeat::SIA_DC09);
        $lastBeat = time();

        try {
            while (true) {
                $handled = $server->poll(1.0);
                if ($once && $handled > 0) {
                    return self::SUCCESS;
                }

                if (time() - $lastBeat >= ListenerHeartbeat::BEAT_EVERY_SECONDS) {
                    $heartbeat->beat(ListenerHeartbeat::SIA_DC09);
                    $lastBeat = time();
                }
            }
        } finally {
            $server->close();
        }
    }
}
