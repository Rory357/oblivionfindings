<?php

namespace Oblivion\Collector\Mqtt;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * S&D Phase B (D21): the Site hub's sensor relay. A small MQTT 3.1.1 server that only accepts
 * publishes: each gateway logs in with its own login and may publish only under its own topic.
 * Every reading goes into the RelayInbox with the time the hub received it; the hub's cycle uploads
 * it, so readings held while the link is down arrive later with their real times.
 *
 * No subscriptions, no retained messages, no wills, no QoS 2. A QoS 1 reading is acknowledged only
 * once it is safely in the inbox, so the gateway sends it again if it wasn't kept.
 */
final class MqttRelayServer
{
    private const int MAX_CLIENTS = 64;

    private const int CONNECT_WITHIN_SECONDS = 10;

    private const int FAILURES_BEFORE_PAUSE = 5;

    private const int FAILURE_WINDOW_SECONDS = 600;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array{socket: resource, peer: string, buffer: string, login: ?array{username: string, topic_prefix: string}, opened: int, seen: int, keepalive: int, secure: bool}> */
    private array $clients = [];

    private const int TLS_SERVER = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;

    /** @var array<string, list<int>> failed logins per address */
    private array $failures = [];

    private Closure $now;

    /**
     * @param  Closure(): ?array{listen_port: int, max_payload_bytes: int, logins: list<array{username: string, password_hash: string, site_id: int, topic_prefix: string}>}  $relay
     * @param  (Closure(): int)|null  $now
     * @param  array{certificate_file: string, key_file: string}|null  $tls  S&D Phase E (E5): TLS 1.2+ with this hub's certificate (8883)
     */
    public function __construct(
        private readonly RelayInbox $inbox,
        private readonly Closure $relay,
        ?Closure $now = null,
        private readonly ?Closure $log = null,
        private readonly ?array $tls = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    public function listen(string $address, int $port): void
    {
        $this->close();
        $target = 'tcp://'.(str_contains($address, ':') ? "[{$address}]" : $address).':'.$port;
        $context = stream_context_create($this->tls === null ? [] : ['ssl' => [
            // The certificate file holds this hub's certificate, then the sensors certificate.
            'local_cert' => $this->tls['certificate_file'],
            'local_pk' => $this->tls['key_file'],
            'verify_peer' => false,
            'disable_compression' => true,
            'crypto_method' => self::TLS_SERVER,
        ]]);
        $server = @stream_socket_server($target, $errorNumber, $errorText, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if (! is_resource($server)) {
            throw new RuntimeException("The sensor relay couldn't listen on port {$port}.");
        }
        stream_set_blocking($server, false);
        $this->server = $server;
    }

    public function port(): ?int
    {
        if (! is_resource($this->server)) {
            return null;
        }
        $name = (string) stream_socket_get_name($this->server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** One round: accept, read, answer, and drop connections that went quiet. */
    public function poll(float $timeoutSeconds): void
    {
        if (! is_resource($this->server)) {
            usleep((int) ($timeoutSeconds * 1_000_000));

            return;
        }
        $read = [$this->server, ...array_column($this->clients, 'socket')];
        $write = null;
        $except = null;
        $seconds = (int) floor($timeoutSeconds);
        $changed = @stream_select($read, $write, $except, $seconds, (int) (($timeoutSeconds - $seconds) * 1_000_000));
        if ($changed !== false && $changed > 0) {
            foreach ($read as $socket) {
                if ($socket === $this->server) {
                    $this->accept();
                } else {
                    $this->receive((int) $socket);
                }
            }
        }
        $this->expire();
    }

    public function close(): void
    {
        foreach (array_keys($this->clients) as $id) {
            $this->drop($id);
        }
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        $this->server = null;
    }

    private function accept(): void
    {
        $socket = @stream_socket_accept($this->server, 0, $peer);
        if (! is_resource($socket)) {
            return;
        }
        $address = self::addressOf((string) $peer);
        if (count($this->clients) >= self::MAX_CLIENTS || $this->paused($address)) {
            fclose($socket);

            return;
        }
        stream_set_blocking($socket, false);
        $now = ($this->now)();
        $this->clients[(int) $socket] = [
            'socket' => $socket, 'peer' => $address, 'buffer' => '', 'login' => null,
            'opened' => $now, 'seen' => $now, 'keepalive' => 0, 'secure' => $this->tls === null,
        ];
    }

    private function receive(int $id): void
    {
        if (! $this->clients[$id]['secure']) {
            // TLS first: nothing is read as MQTT until the handshake is done. A stalled handshake is
            // dropped by expire() like a slow login.
            $done = @stream_socket_enable_crypto($this->clients[$id]['socket'], true, self::TLS_SERVER);
            if ($done === true) {
                $this->clients[$id]['secure'] = true;
                $this->clients[$id]['seen'] = ($this->now)();
            } elseif ($done === false) {
                $this->drop($id);
            }

            return;
        }
        $chunk = @fread($this->clients[$id]['socket'], 65_536);
        if (! is_string($chunk) || $chunk === '') {
            if (feof($this->clients[$id]['socket'])) {
                $this->drop($id);
            }

            return;
        }
        $this->clients[$id]['buffer'] .= $chunk;
        $this->clients[$id]['seen'] = ($this->now)();
        $limit = (($this->relay)()['max_payload_bytes'] ?? 65_536) + 2048;
        if (strlen($this->clients[$id]['buffer']) > $limit + 8) {
            $this->drop($id);

            return;
        }
        try {
            while (isset($this->clients[$id]) && ($packet = self::nextPacket($this->clients[$id]['buffer'], $limit)) !== null) {
                $this->handle($id, $packet['type'], $packet['flags'], $packet['body']);
            }
        } catch (Throwable) {
            $this->drop($id);
        }
    }

    private function handle(int $id, int $type, int $flags, string $body): void
    {
        $client = $this->clients[$id];
        if ($client['login'] === null && $type !== 1) {
            $this->drop($id);

            return;
        }
        match ($type) {
            1 => $client['login'] === null ? $this->connect($id, $body) : $this->drop($id),
            3 => $this->publish($id, $flags, $body),
            12 => $this->send($id, "\xD0\x00"),
            14 => $this->drop($id),
            default => $this->drop($id), // SUBSCRIBE, UNSUBSCRIBE, QoS 2 flow: not offered.
        };
    }

    private function connect(int $id, string $body): void
    {
        $offset = 0;
        $protocol = self::string($body, $offset);
        $level = ord($body[$offset++] ?? "\0");
        $connectFlags = ord($body[$offset++] ?? "\0");
        $keepalive = self::u16($body, $offset);
        $offset += 2;
        if ($protocol !== 'MQTT' || $level !== 4) {
            $this->send($id, "\x20\x02\x00\x01");
            $this->drop($id);

            return;
        }
        self::string($body, $offset); // client id
        $hasUsername = ($connectFlags & 0x80) !== 0;
        $hasPassword = ($connectFlags & 0x40) !== 0;
        if (($connectFlags & 0x04) !== 0 || ($connectFlags & 0x01) !== 0 || ! $hasUsername || ! $hasPassword) {
            // No wills, no anonymous connections.
            $this->refuse($id, "\x20\x02\x00\x05");

            return;
        }
        $username = self::string($body, $offset);
        $password = self::string($body, $offset);
        $login = null;
        foreach (($this->relay)()['logins'] ?? [] as $candidate) {
            if (hash_equals($candidate['username'], $username)) {
                $login = $candidate;
                break;
            }
        }
        $accepted = $login !== null && password_verify($password, $login['password_hash']);
        sodium_memzero($password);
        if (! $accepted) {
            $this->refuse($id, "\x20\x02\x00\x04");

            return;
        }
        $this->clients[$id]['login'] = ['username' => $login['username'], 'topic_prefix' => $login['topic_prefix']];
        $this->clients[$id]['keepalive'] = (int) $keepalive;
        $this->send($id, "\x20\x02\x00\x00");
    }

    private function publish(int $id, int $flags, string $body): void
    {
        $qos = ($flags >> 1) & 0x03;
        $offset = 0;
        $topic = self::string($body, $offset);
        $packetId = null;
        if ($qos === 1) {
            $packetId = substr($body, $offset, 2);
            $offset += 2;
        }
        $payload = substr($body, $offset);
        $login = $this->clients[$id]['login'];
        $relay = ($this->relay)();
        $stillAllowed = $login !== null && array_any(
            $relay['logins'] ?? [],
            static fn (array $candidate): bool => $candidate['username'] === $login['username'],
        );
        if ($qos === 2 || ! $stillAllowed || $topic === '' || strlen($topic) > 255
            || preg_match('/[#+\x00]/', $topic) === 1
            || ! str_starts_with($topic, (string) $login['topic_prefix'])
            || strlen($payload) > (int) ($relay['max_payload_bytes'] ?? 65_536)) {
            // Another device's topic, a revoked login or an oversized reading: stop talking to it.
            $this->drop($id);

            return;
        }
        $kept = $this->inbox->put([
            'item_type' => 'sensor_reading',
            'username' => $login['username'],
            'topic' => $topic,
            'payload_base64' => base64_encode($payload),
            'received_at' => (new DateTimeImmutable('@'.($this->now)()))->format(DATE_ATOM),
        ]);
        if ($kept && $qos === 1 && strlen((string) $packetId) === 2) {
            $this->send($id, "\x40\x02".$packetId);
        }
    }

    private function refuse(int $id, string $connack): void
    {
        $address = $this->clients[$id]['peer'];
        $this->failures[$address][] = ($this->now)();
        $this->send($id, $connack);
        $this->drop($id);
        $count = count($this->failures[$address]);
        if ($count === self::FAILURES_BEFORE_PAUSE) {
            // S&D Phase E: Oblivion hears about it once per pause and alerts IT ("5 failed logins in 10 minutes").
            $this->inbox->put([
                'item_type' => 'relay_login_failures',
                'address' => $address,
                'count' => $count,
                'received_at' => (new DateTimeImmutable('@'.($this->now)()))->format(DATE_ATOM),
            ]);
        }
        if ($this->log !== null && $count >= self::FAILURES_BEFORE_PAUSE) {
            ($this->log)("Sensor relay: {$address} gave {$count} wrong logins in 10 minutes; ignoring it for 10 minutes.");
        }
    }

    private function paused(string $address): bool
    {
        $cutoff = ($this->now)() - self::FAILURE_WINDOW_SECONDS;
        $this->failures[$address] = array_values(array_filter($this->failures[$address] ?? [], static fn (int $at): bool => $at > $cutoff));
        if ($this->failures[$address] === []) {
            unset($this->failures[$address]);

            return false;
        }

        return count($this->failures[$address]) >= self::FAILURES_BEFORE_PAUSE;
    }

    private function expire(): void
    {
        $now = ($this->now)();
        foreach ($this->clients as $id => $client) {
            $quietFor = $now - $client['seen'];
            $tooSlowToLogIn = $client['login'] === null && $now - $client['opened'] > self::CONNECT_WITHIN_SECONDS;
            $keepalive = $client['keepalive'] > 0 ? (int) ceil($client['keepalive'] * 1.5) : 900;
            if ($tooSlowToLogIn || $quietFor > $keepalive) {
                $this->drop($id);
            }
        }
    }

    private function send(int $id, string $bytes): void
    {
        if (isset($this->clients[$id])) {
            @fwrite($this->clients[$id]['socket'], $bytes);
        }
    }

    private function drop(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]['socket']);
            unset($this->clients[$id]);
        }
    }

    /** @return array{type: int, flags: int, body: string}|null */
    private static function nextPacket(string &$buffer, int $limit): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }
        $length = 0;
        $multiplier = 1;
        $index = 1;
        do {
            if (! isset($buffer[$index]) || $index > 4) {
                if ($index > 4) {
                    throw new RuntimeException('Malformed MQTT length.');
                }

                return null;
            }
            $byte = ord($buffer[$index]);
            $length += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
            $index++;
        } while (($byte & 0x80) !== 0);
        if ($length > $limit) {
            throw new RuntimeException('MQTT packet too large.');
        }
        if (strlen($buffer) < $index + $length) {
            return null;
        }
        $first = ord($buffer[0]);
        $body = substr($buffer, $index, $length);
        $buffer = (string) substr($buffer, $index + $length);

        return ['type' => $first >> 4, 'flags' => $first & 0x0F, 'body' => $body];
    }

    private static function u16(string $body, int $offset): int
    {
        if (strlen($body) < $offset + 2) {
            throw new RuntimeException('Malformed MQTT packet.');
        }

        return (ord($body[$offset]) << 8) | ord($body[$offset + 1]);
    }

    private static function string(string $body, int &$offset): string
    {
        $length = self::u16($body, $offset);
        if (strlen($body) < $offset + 2 + $length) {
            throw new RuntimeException('Malformed MQTT string.');
        }
        $value = substr($body, $offset + 2, $length);
        $offset += 2 + $length;

        return $value;
    }

    private static function addressOf(string $peer): string
    {
        $colon = strrpos($peer, ':');

        return trim($colon === false ? $peer : substr($peer, 0, $colon), '[]');
    }
}
