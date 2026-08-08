<?php

namespace App\Services\Queclink\Listener;

use App\Services\Queclink\AtTrackProtocolParser;
use App\Services\Queclink\Exceptions\IntakeRejected;
use App\Support\SafeOperationalData;
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
        protected ListenerLimits $limits,
        protected ListenerPressureGuard $pressure,
        protected ListenerSecurityEventAggregator $securityEvents,
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
            throw new RuntimeException('Queclink listener failed to bind.');
        }

        stream_set_blocking($this->server, false);
        Log::info('Queclink listener started.', SafeOperationalData::logContext([
            'provider' => 'queclink',
            'status' => 'started',
        ]));

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

            $this->pruneIdleConnections();
            $this->pressure->prune(microtime(true));
            $this->flushSecurityEvents();

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

        $sourceFingerprint = $this->pressure->sourceFingerprint($peer);
        $activeForSource = 0;
        foreach ($this->states as $state) {
            if ($this->pressure->sourceFingerprint($state->remoteAddress) === $sourceFingerprint) {
                $activeForSource++;
            }
        }

        $rejection = $this->pressure->connectionRejection(
            $peer,
            count($this->clients),
            $activeForSource,
            microtime(true),
        );
        if ($rejection !== null) {
            @fclose($client);
            $this->recordSecurityEvent($rejection);

            return;
        }

        stream_set_blocking($client, false);
        $id = (int) $client;
        $this->clients[$id] = $client;
        $this->states[$id] = new ConnectionState($peer);
    }

    protected function serviceClient(int $clientId, $client): void
    {
        $state = $this->states[$clientId] ?? null;
        if ($state === null) {
            $this->disconnect($clientId);

            return;
        }

        $data = @fread($client, $this->limits->maxBufferBytes + 1);
        if ($data === false || $data === '') {
            // Connection closed by peer.
            $this->disconnect($clientId);

            return;
        }

        try {
            $frames = $this->parser->splitFrames(
                $data,
                $state->buffer,
                $this->limits->maxBufferBytes,
                $this->limits->maxFrameBytes,
            );
        } catch (IntakeRejected $rejection) {
            $this->recordSecurityEvent($rejection->reason);
            $this->disconnect($clientId);

            return;
        }

        foreach ($frames as $rawFrame) {
            $pressureRejection = $this->pressure->frameRejection($state, microtime(true));
            if ($pressureRejection !== null) {
                $this->recordSecurityEvent($pressureRejection);
                $this->disconnect($clientId);

                return;
            }

            try {
                $responses = $this->router->handleInbound($rawFrame, $state);
                foreach ($responses as $response) {
                    @fwrite($client, $response);
                }
            } catch (IntakeRejected $rejection) {
                $this->recordSecurityEvent($rejection->reason);
                $invalidRejection = $this->pressure->invalidFrameRejection($state, microtime(true));
                if ($invalidRejection !== null) {
                    $this->recordSecurityEvent($invalidRejection);
                    $this->disconnect($clientId);

                    return;
                }
            } catch (\Throwable $e) {
                Log::error('Queclink frame processing failed.', SafeOperationalData::logContext([
                    'provider' => 'queclink',
                    'failure_category' => SafeOperationalData::failureCategory($e),
                    'items_errored' => 1,
                ]));
                $this->disconnect($clientId);

                return;
            }
        }
    }

    protected function pruneIdleConnections(): void
    {
        $now = microtime(true);
        foreach ($this->states as $clientId => $state) {
            if ($this->pressure->isIdle($state, $now)) {
                $this->recordSecurityEvent('idle_timeout');
                $this->disconnect($clientId);
            }
        }
    }

    protected function recordSecurityEvent(string $failureCategory): void
    {
        $this->securityEvents->record($failureCategory, microtime(true));
        $this->flushSecurityEvents();
    }

    protected function flushSecurityEvents(bool $force = false): void
    {
        foreach ($this->securityEvents->drain(microtime(true), $force) as $category => $count) {
            Log::warning('Queclink intake pressure event.', SafeOperationalData::logContext([
                'provider' => 'queclink',
                'failure_category' => $category,
                'items_errored' => $count,
            ]));
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
        $this->flushSecurityEvents(true);
        foreach (array_keys($this->clients) as $clientId) {
            $this->disconnect($clientId);
        }
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
        $this->server = null;
        Log::info('Queclink listener stopped.', SafeOperationalData::logContext([
            'provider' => 'queclink',
            'status' => 'stopped',
        ]));
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
