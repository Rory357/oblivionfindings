<?php

namespace App\Domain\SecurityDevices\Iot\Mqtt;

/**
 * What the MQTT sensor listener needs from a broker connection. MqttV311Client is the real one; tests
 * use a stand-in to prove the listener reconnects (Phase A, item A4).
 */
interface MqttSubscriber
{
    /** @throws \RuntimeException when the broker can't be reached or refuses the connection */
    public function connect(
        string $host,
        int $port = 1883,
        string $clientId = 'oblivion-mqtt',
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        bool $tls = false,
        int $keepAliveSeconds = 30,
        int $timeoutSeconds = 5,
    ): void;

    public function subscribe(string $topicFilter, int $qos = 0): void;

    /**
     * The next message, or null when nothing arrived before the read timeout.
     *
     * @return array{topic: string, payload: string, qos: int, retain: bool}|null
     *
     * @throws MqttConnectionLost when the broker has gone away
     */
    public function readNextPublish(): ?array;

    /** True when the broker answered the keep-alive ping. */
    public function ping(): bool;

    public function disconnect(): void;
}
