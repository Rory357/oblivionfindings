<?php

namespace App\Domain\SecurityDevices\Iot\Mqtt;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure-PHP OASIS MQTT Version 3.1.1 protocol codec and TCP/TLS client.
 *
 * Clean-room implementation from the OASIS Standard:
 * - http://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html
 */
final class MqttV311Client
{
    public const int PACKET_CONNECT = 1;

    public const int PACKET_CONNACK = 2;

    public const int PACKET_PUBLISH = 3;

    public const int PACKET_PUBACK = 4;

    public const int PACKET_SUBSCRIBE = 8;

    public const int PACKET_SUBACK = 9;

    public const int PACKET_PINGREQ = 12;

    public const int PACKET_PINGRESP = 13;

    public const int PACKET_DISCONNECT = 14;

    public const int MAX_PAYLOAD_BYTES = 65536;

    /** @var resource|null */
    private $stream = null;

    private int $nextPacketId = 1;

    public function connect(
        string $host,
        int $port = 1883,
        string $clientId = 'oblivion-mqtt',
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        bool $tls = false,
        int $keepAliveSeconds = 30,
        int $timeoutSeconds = 5,
    ): void {
        $this->disconnect();

        $cleanHost = trim($host);
        if ($cleanHost === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid MQTT broker host or port.');
        }

        $transport = ($tls ? 'tls' : 'tcp').'://'.(str_contains($cleanHost, ':') && ! str_starts_with($cleanHost, '[')
            ? '['.$cleanHost.']'
            : $cleanHost).':'.$port;

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $transport,
            $errno,
            $errstr,
            max(1, min(15, $timeoutSeconds)),
            STREAM_CLIENT_CONNECT,
        );
        if (! is_resource($stream)) {
            throw new RuntimeException(sprintf('Could not connect to MQTT broker (%s).', $errstr !== '' ? $errstr : 'errno '.$errno));
        }

        stream_set_timeout($stream, max(1, min(30, $timeoutSeconds)));
        $this->stream = $stream;

        $this->writePacket(self::buildConnectPacket($clientId, $username, $password, $keepAliveSeconds));
        $packet = $this->readPacket();
        if ($packet === null || $packet['type'] !== self::PACKET_CONNACK || strlen($packet['body']) < 2) {
            $this->closeStream();
            throw new RuntimeException('MQTT broker did not return a valid CONNACK packet.');
        }

        $returnCode = ord($packet['body'][1]);
        if ($returnCode !== 0) {
            $this->closeStream();
            $reason = match ($returnCode) {
                1 => 'unacceptable protocol version',
                2 => 'client identifier rejected',
                3 => 'broker unavailable',
                4 => 'bad username or password',
                5 => 'not authorised',
                default => 'return code '.$returnCode,
            };
            throw new RuntimeException('MQTT broker refused connection: '.$reason.'.');
        }
    }

    public function subscribe(string $topicFilter, int $qos = 0): void
    {
        $qos = max(0, min(1, $qos));
        $packetId = $this->nextPacketId();
        $this->writePacket(self::buildSubscribePacket($packetId, $topicFilter, $qos));

        $packet = $this->readPacket();
        if ($packet === null || $packet['type'] !== self::PACKET_SUBACK || strlen($packet['body']) < 3) {
            throw new RuntimeException('MQTT broker did not acknowledge SUBSCRIBE.');
        }

        $ackId = (ord($packet['body'][0]) << 8) | ord($packet['body'][1]);
        $grant = ord($packet['body'][2]);
        if ($ackId !== $packetId || $grant === 0x80) {
            throw new RuntimeException('MQTT broker rejected topic subscription.');
        }
    }

    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        $qos = max(0, min(1, $qos));
        $packetId = $qos > 0 ? $this->nextPacketId() : null;
        $this->writePacket(self::buildPublishPacket($topic, $payload, $qos, $retain, $packetId));

        if ($qos === 1) {
            $ack = $this->readPacket();
            if ($ack === null || $ack['type'] !== self::PACKET_PUBACK) {
                throw new RuntimeException('MQTT broker did not return PUBACK.');
            }
        }
    }

    /**
     * Read the next inbound PUBLISH message (automatically answering PINGRESP / QoS 1 PUBACK).
     *
     * @return array{topic: string, payload: string, qos: int, retain: bool}|null
     */
    public function readNextPublish(): ?array
    {
        for ($attempts = 0; $attempts < 8; $attempts++) {
            $packet = $this->readPacket();
            if ($packet === null) {
                return null;
            }

            if ($packet['type'] === self::PACKET_PINGRESP) {
                continue;
            }

            if ($packet['type'] === self::PACKET_PUBLISH) {
                $decoded = self::parsePublishPacket($packet['flags'], $packet['body']);
                if ($decoded['qos'] === 1 && $decoded['packet_id'] !== null) {
                    $this->writePacket(self::buildPubAckPacket($decoded['packet_id']));
                }

                return [
                    'topic' => $decoded['topic'],
                    'payload' => $decoded['payload'],
                    'qos' => $decoded['qos'],
                    'retain' => $decoded['retain'],
                ];
            }
        }

        return null;
    }

    public function ping(): bool
    {
        $this->writePacket("\xC0\x00");
        $packet = $this->readPacket();

        return $packet !== null && $packet['type'] === self::PACKET_PINGRESP;
    }

    public function disconnect(): void
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, "\xE0\x00");
            $this->closeStream();
        }
    }

    public static function buildConnectPacket(
        string $clientId,
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        int $keepAliveSeconds = 30,
        bool $cleanSession = true,
    ): string {
        $flags = $cleanSession ? 0x02 : 0x00;
        $payload = self::encodeUtf8String($clientId);

        if ($username !== null && $username !== '') {
            $flags |= 0x80;
            $payload .= self::encodeUtf8String($username);
            if ($password !== null) {
                $flags |= 0x40;
                $payload .= self::encodeUtf8String($password);
            }
        }

        $keepAlive = max(0, min(65535, $keepAliveSeconds));
        $variableHeader = self::encodeUtf8String('MQTT')
            ."\x04" // Protocol Level 4 = MQTT v3.1.1
            .chr($flags)
            .pack('n', $keepAlive);

        $body = $variableHeader.$payload;

        return "\x10".self::encodeRemainingLength(strlen($body)).$body;
    }

    public static function buildSubscribePacket(int $packetId, string $topicFilter, int $qos = 0): string
    {
        if (trim($topicFilter) === '') {
            throw new InvalidArgumentException('MQTT topic filter must not be empty.');
        }

        $body = pack('n', $packetId & 0xFFFF)
            .self::encodeUtf8String($topicFilter)
            .chr($qos & 0x03);

        return "\x82".self::encodeRemainingLength(strlen($body)).$body;
    }

    public static function buildPublishPacket(
        string $topic,
        string $payload,
        int $qos = 0,
        bool $retain = false,
        ?int $packetId = null,
    ): string {
        if ($topic === '' || str_contains($topic, '+') || str_contains($topic, '#')) {
            throw new InvalidArgumentException('MQTT PUBLISH topic name must not be empty or contain wildcards.');
        }
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('MQTT payload exceeds bounded limit.');
        }

        $flags = (($qos & 0x03) << 1) | ($retain ? 0x01 : 0x00);
        $firstByte = chr((self::PACKET_PUBLISH << 4) | $flags);
        $body = self::encodeUtf8String($topic);
        if ($qos > 0) {
            $body .= pack('n', ($packetId ?? 1) & 0xFFFF);
        }
        $body .= $payload;

        return $firstByte.self::encodeRemainingLength(strlen($body)).$body;
    }

    public static function buildPubAckPacket(int $packetId): string
    {
        return "\x40\x02".pack('n', $packetId & 0xFFFF);
    }

    /**
     * @return array{topic: string, payload: string, qos: int, retain: bool, packet_id: int|null}
     */
    public static function parsePublishPacket(int $flags, string $body): array
    {
        if (strlen($body) < 2) {
            throw new InvalidArgumentException('Truncated MQTT PUBLISH packet.');
        }

        $topicLength = (ord($body[0]) << 8) | ord($body[1]);
        $offset = 2 + $topicLength;
        if (strlen($body) < $offset) {
            throw new InvalidArgumentException('MQTT PUBLISH topic exceeds packet length.');
        }

        $topic = substr($body, 2, $topicLength);
        $qos = ($flags >> 1) & 0x03;
        $retain = ($flags & 0x01) === 1;
        $packetId = null;

        if ($qos > 0) {
            if (strlen($body) < $offset + 2) {
                throw new InvalidArgumentException('Missing packet identifier on QoS > 0 MQTT PUBLISH.');
            }
            $packetId = (ord($body[$offset]) << 8) | ord($body[$offset + 1]);
            $offset += 2;
        }

        return [
            'topic' => $topic,
            'payload' => substr($body, $offset),
            'qos' => $qos,
            'retain' => $retain,
            'packet_id' => $packetId,
        ];
    }

    /**
     * Match an MQTT topic name against an MQTT topic filter supporting `+` and `#` wildcards (§4.7).
     */
    public static function topicMatchesFilter(string $topic, string $filter): bool
    {
        if ($filter === '#') {
            return ! str_starts_with($topic, '$');
        }
        if ($topic === $filter) {
            return true;
        }

        $topicLevels = explode('/', $topic);
        $filterLevels = explode('/', $filter);

        foreach ($filterLevels as $index => $filterLevel) {
            if ($filterLevel === '#') {
                return $index === count($filterLevels) - 1;
            }
            if (! array_key_exists($index, $topicLevels)) {
                return false;
            }
            if ($filterLevel === '+') {
                if ($index === 0 && str_starts_with($topicLevels[0], '$')) {
                    return false;
                }

                continue;
            }
            if ($topicLevels[$index] !== $filterLevel) {
                return false;
            }
        }

        return count($topicLevels) === count($filterLevels);
    }

    public static function encodeRemainingLength(int $length): string
    {
        if ($length < 0 || $length > 268_435_455) {
            throw new InvalidArgumentException('MQTT remaining length out of range.');
        }

        $encoded = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $digit |= 0x80;
            }
            $encoded .= chr($digit);
        } while ($length > 0);

        return $encoded;
    }

    private static function encodeUtf8String(string $value): string
    {
        if (strlen($value) > 65535) {
            throw new InvalidArgumentException('MQTT UTF-8 string exceeds 65535 bytes.');
        }

        return pack('n', strlen($value)).$value;
    }

    private function nextPacketId(): int
    {
        $current = $this->nextPacketId;
        $this->nextPacketId = $current >= 65535 ? 1 : $current + 1;

        return $current;
    }

    private function writePacket(string $bytes): void
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException('MQTT client is not connected.');
        }

        $written = 0;
        $total = strlen($bytes);
        while ($written < $total) {
            $chunk = @fwrite($this->stream, substr($bytes, $written));
            if (! is_int($chunk) || $chunk <= 0) {
                throw new RuntimeException('Failed writing packet to MQTT broker.');
            }
            $written += $chunk;
        }
    }

    /**
     * @return array{type: int, flags: int, body: string}|null
     */
    private function readPacket(): ?array
    {
        if (! is_resource($this->stream)) {
            return null;
        }

        $first = $this->readExact(1);
        if ($first === null) {
            return null;
        }

        $byte1 = ord($first[0]);
        $type = ($byte1 >> 4) & 0x0F;
        $flags = $byte1 & 0x0F;

        $multiplier = 1;
        $remainingLength = 0;
        for ($i = 0; $i < 4; $i++) {
            $lenByteStr = $this->readExact(1);
            if ($lenByteStr === null) {
                throw new RuntimeException('Unexpected EOF reading MQTT remaining length.');
            }
            $encodedByte = ord($lenByteStr[0]);
            $remainingLength += ($encodedByte & 0x7F) * $multiplier;
            if (($encodedByte & 0x80) === 0) {
                break;
            }
            $multiplier *= 128;
            if ($i === 3) {
                throw new RuntimeException('Malformed MQTT remaining length.');
            }
        }

        if ($remainingLength > self::MAX_PAYLOAD_BYTES + 2048) {
            throw new RuntimeException('Inbound MQTT packet exceeds bounded payload size.');
        }

        $body = $remainingLength === 0 ? '' : $this->readExact($remainingLength);
        if ($body === null) {
            throw new RuntimeException('Unexpected EOF reading MQTT packet body.');
        }

        return [
            'type' => $type,
            'flags' => $flags,
            'body' => $body,
        ];
    }

    private function readExact(int $length): ?string
    {
        if ($length === 0) {
            return '';
        }
        if (! is_resource($this->stream)) {
            return null;
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($this->stream, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (($meta['timed_out'] ?? false) === true || feof($this->stream)) {
                    return strlen($buffer) === 0 ? null : throw new RuntimeException('Truncated MQTT stream read.');
                }

                return null;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }
}
