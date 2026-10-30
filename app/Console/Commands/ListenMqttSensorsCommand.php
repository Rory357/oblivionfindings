<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorIntakeService;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttV311Client;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ListenMqttSensorsCommand extends Command
{
    protected $signature = 'security-devices:listen-mqtt
        {--site= : Authorised Site ID for inbound MQTT sensor topics}
        {--host=127.0.0.1 : MQTT broker hostname or IP}
        {--port=1883 : MQTT broker TCP/TLS port}
        {--tls : Connect over TLS (MQTTS)}
        {--username= : Optional MQTT broker username}
        {--topic=oblivion/sensors/# : MQTT topic filter to subscribe}
        {--max-messages=0 : Exit cleanly after N messages (0 = run continuously)}
        {--timeout=5 : Read timeout per poll cycle in seconds}';

    protected $description = 'Subscribe to an OASIS MQTT v3.1.1 broker for a Site and ingest sensor readings and alerts';

    public function handle(MqttV311Client $client, MqttSensorIntakeService $intake): int
    {
        $siteId = (int) $this->option('site');
        if ($siteId < 1) {
            $this->error('A valid --site=<id> option is required.');

            return self::FAILURE;
        }

        $host = trim((string) $this->option('host'));
        $port = (int) $this->option('port');
        $tls = (bool) $this->option('tls');
        $username = $this->option('username') !== null ? (string) $this->option('username') : null;
        $password = env('MQTT_BROKER_PASSWORD');
        $topic = trim((string) $this->option('topic'));
        $maxMessages = max(0, (int) $this->option('max-messages'));
        $timeout = max(1, min(30, (int) $this->option('timeout')));

        try {
            $client->connect(
                host: $host,
                port: $port,
                clientId: 'oblivion-site-'.$siteId.'-'.substr(bin2hex(random_bytes(3)), 0, 6),
                username: $username,
                password: is_string($password) ? $password : null,
                tls: $tls,
                keepAliveSeconds: 30,
                timeoutSeconds: $timeout,
            );
            $client->subscribe($topic, 0);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Subscribed to MQTT %s:%d (%s) for Site #%d', $host, $port, $topic, $siteId));

        $handled = 0;
        try {
            while (true) {
                $message = $client->readNextPublish();
                if ($message === null) {
                    if ($maxMessages > 0) {
                        break;
                    }
                    $client->ping();

                    continue;
                }

                $intake->ingest(
                    siteId: $siteId,
                    topic: $message['topic'],
                    rawPayload: $message['payload'],
                    receivedAt: CarbonImmutable::now('UTC'),
                );

                $handled++;
                if ($maxMessages > 0 && $handled >= $maxMessages) {
                    break;
                }
            }
        } finally {
            $client->disconnect();
        }

        return self::SUCCESS;
    }
}
