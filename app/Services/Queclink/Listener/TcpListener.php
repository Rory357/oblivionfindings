<?php

namespace App\Services\Queclink\Listener;

use App\Services\Queclink\AtTrackProtocolParser;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pure-PHP TCP server for Queclink @Track devices.
 *
 * Uses stream_socket_server + stream_select for connection multiplexing.
 * Suitable for tens to low-hundreds of concurrent device sessions — the
 * realistic scale for a residential care provider's fleet + lone-worker
 * trackers. No PHP extension dependencies (no Swoole, no Workerman).
 *
 * Lifecycle:
 *   1. Bind 0.0.0.0:$port
 *   2. Loop: stream_select(server + clients) with 1s timeout
 *      - on server readable: accept
 *      - on client readable: read up to 64KB, feed to parser frame-splitter
 *      - on idle tick: check for shutdown signal, prune timed-out connections
 *   3. Cleanup on SIGTERM/SIGINT.
 */
class TcpListener
{
    /** @var resource|null */
    protected $server = null;

    /** @var array<int, resource> */
    protected array $clients = [];

    /** @var array<int, ConnectionState> */
    protected array $states = [];

    protected bool $shouldStop = false;
    protected bool $hasPcntl = false;

    public function __construct(
        protected AtTrackProtocolParser $parser,
        protected FrameRouter $router,
    ) {
        $this->hasPcntl = function_exists('pcntl_signal') && function_exists('pcntl_signal_dispatch');
    }

    public function run(int $port, ?callable $tick = null): void
    {
        $this->registerSignalHandlers();

        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => true,
                'backlog' => 64,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $this->server = @stream_socket_server(
            "tcp://0.0.0.0:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if (! is_resource($this->server)) {
            throw new RuntimeException("queclink: failed to bind tcp://0.0.0.0:{$port} — {$errstr} (errno {$errno})");
        }

        stream_set_blocking($this->server, false);
        Log::info("queclink: listening on tcp://0.0.0.0:{$port}");

        try {
            $this->loop($tick);
        } finally {
            $this->shutdown();
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    protected function loop(?callable $tick): void
    {
        while (! $this->shouldStop) {
            $read = $this->clients;
            $read['__server__'] = $this->server;
            $write = null;
            $except = null;

            $changed = @stream_select($read, $write, $except, 1, 0);
            if ($changed === false) {
                // Interrupted by signal — re-check stop flag.
                if ($this->hasPcntl) {
                    pcntl_signal_dispatch();
                }
                continue;
            }

            if (isset($read['__server__'])) {
                $this->accept();
                unset($read['__server__']);
            }

            foreach ($read as $clientId => $client) {
                $this->serviceClient((int) $clientId, $client);
            }

            if ($tick !== null) {
                $tick();
            }

            if ($this->hasPcntl) {
                pcntl_signal_dispatch();
            }
        }
    }

    protected function accept(): void
    {
        $peer = '';
        $client = @stream_socket_accept($this->server, 0, $peer);
        if (! is_resource($client)) {
            return;
        }
        stream_set_blocking($client, false);
        $id = (int) $client;
        $this->clients[$id] = $client;
        $this->states[$id] = new ConnectionState($peer);
        Log::debug('queclink: accepted', ['peer' => $peer, 'session' => $this->states[$id]->sessionId]);
    }

    protected function serviceClient(int $clientId, $client): void
    {
        $state = $this->states[$clientId] ?? null;
        if ($state === null) {
            $this->disconnect($clientId);
            return;
        }

        $data = @fread($client, 65536);
        if ($data === false || $data === '') {
            // Connection closed by peer.
            $this->disconnect($clientId);
            return;
        }

        $frames = $this->parser->splitFrames($data, $state->buffer);
        foreach ($frames as $rawFrame) {
            try {
                $responses = $this->router->handleInbound($rawFrame, $state);
                foreach ($responses as $response) {
                    @fwrite($client, $response);
                }
            } catch (\Throwable $e) {
                Log::error('queclink: frame handler threw', [
                    'session' => $state->sessionId,
                    'frame' => substr($rawFrame, 0, 200),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function disconnect(int $clientId): void
    {
        if (isset($this->states[$clientId])) {
            $this->router->handleDisconnect($this->states[$clientId]);
            unset($this->states[$clientId]);
        }
        if (isset($this->clients[$clientId])) {
            @fclose($this->clients[$clientId]);
            unset($this->clients[$clientId]);
        }
    }

    protected function shutdown(): void
    {
        foreach (array_keys($this->clients) as $clientId) {
            $this->disconnect($clientId);
        }
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
        $this->server = null;
        Log::info('queclink: listener stopped');
    }

    protected function registerSignalHandlers(): void
    {
        if (! $this->hasPcntl) {
            // Windows / non-POSIX — Ctrl+C will still terminate the PHP
            // process, just without a graceful shutdown. systemd on Linux
            // sends SIGTERM via KillSignal=SIGTERM.
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
    }

    public function connectionCount(): int
    {
        return count($this->clients);
    }
}
