<?php

namespace App\Domain\SecurityDevices\Iot\Mqtt;

use App\Domain\SecurityDevices\Services\ListenerHeartbeat;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * Keeps the MQTT sensor listener connected (Phase A, item A4).
 *
 * The earlier listener read its password with env(), which is empty once settings are cached, and never
 * reconnected after the broker went away. This one takes its settings from config, reconnects with
 * back-off (1, 2, 4 … up to 60 seconds) and writes a heartbeat while connected.
 */
final class MqttSensorListener
{
    public const int MAX_BACKOFF_SECONDS = 60;

    /** @var Closure(): MqttSubscriber */
    private Closure $makeClient;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  (Closure(): MqttSubscriber)|null  $makeClient
     * @param  (Closure(int): void)|null  $sleep
     */
    public function __construct(
        private readonly MqttSensorIntakeService $intake,
        private readonly ListenerHeartbeat $heartbeat,
        ?Closure $makeClient = null,
        ?Closure $sleep = null,
    ) {
        $this->makeClient = $makeClient ?? static fn (): MqttSubscriber => new MqttV311Client;
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param  array{site_id: int, host: string, port: int, tls: bool, username: string|null, password: string|null, topic: string, timeout: int}  $settings
     * @param  Closure(string): void  $say  progress lines for the log
     * @param  int  $maxMessages  stop after this many messages (0 = keep going)
     * @param  int  $maxConnectAttempts  stop after this many failed connects in a row (0 = keep trying)
     * @return int messages handled
     */
    public function run(array $settings, Closure $say, int $maxMessages = 0, int $maxConnectAttempts = 0): int
    {
        $handled = 0;
        $failedAttempts = 0;

        while (true) {
            $client = ($this->makeClient)();

            try {
                $client->connect(
                    host: $settings['host'],
                    port: $settings['port'],
                    clientId: 'oblivion-site-'.$settings['site_id'].'-'.bin2hex(random_bytes(3)),
                    username: $settings['username'],
                    password: $settings['password'],
                    tls: $settings['tls'],
                    keepAliveSeconds: 30,
                    timeoutSeconds: $settings['timeout'],
                );
                $client->subscribe($settings['topic'], 0);
            } catch (Throwable $exception) {
                $client->disconnect();
                $failedAttempts++;
                if ($maxConnectAttempts > 0 && $failedAttempts >= $maxConnectAttempts) {
                    $say(sprintf('Gave up after %d tries: %s', $failedAttempts, $exception->getMessage()));

                    return $handled;
                }

                $wait = self::backoffSeconds($failedAttempts);
                $say(sprintf('Could not connect (%s). Trying again in %d s.', $exception->getMessage(), $wait));
                ($this->sleep)($wait);

                continue;
            }

            $failedAttempts = 0;
            $say(sprintf('Connected to %s:%d, listening on %s for Site #%d.', $settings['host'], $settings['port'], $settings['topic'], $settings['site_id']));
            $this->heartbeat->beat(ListenerHeartbeat::MQTT_SENSORS);
            $lastBeat = time();

            try {
                while (true) {
                    $message = $client->readNextPublish();

                    if (time() - $lastBeat >= ListenerHeartbeat::BEAT_EVERY_SECONDS) {
                        $this->heartbeat->beat(ListenerHeartbeat::MQTT_SENSORS);
                        $lastBeat = time();
                    }

                    if ($message === null) {
                        if ($maxMessages > 0) {
                            return $handled;
                        }
                        if (! $client->ping()) {
                            throw new MqttConnectionLost('The MQTT broker stopped answering.');
                        }

                        continue;
                    }

                    $this->intake->ingest(
                        siteId: $settings['site_id'],
                        topic: $message['topic'],
                        rawPayload: $message['payload'],
                        receivedAt: CarbonImmutable::now('UTC'),
                    );
                    $handled++;
                    if ($maxMessages > 0 && $handled >= $maxMessages) {
                        return $handled;
                    }
                }
            } catch (MqttConnectionLost $lost) {
                $say($lost->getMessage().' Reconnecting.');
                ($this->sleep)(1);
            } finally {
                $client->disconnect();
            }
        }
    }

    /** 1, 2, 4, 8 … seconds, never more than a minute. */
    public static function backoffSeconds(int $failedAttempts): int
    {
        return (int) min(self::MAX_BACKOFF_SECONDS, 2 ** max(0, min(6, $failedAttempts - 1)));
    }
}
