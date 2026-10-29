<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorListener;
use Illuminate\Console\Command;

/**
 * MQTT sensor listener (Phase A, item A4).
 *
 * Settings come from config (security_devices.listeners.mqtt_sensors), so they still work once settings
 * are cached. Options here override them for a one-off run. The broker password is never an option: it
 * only comes from MQTT_BROKER_PASSWORD, which Stephan types into .env himself.
 */
final class ListenMqttSensorsCommand extends Command
{
    protected $signature = 'security-devices:listen-mqtt
        {--site= : Site ID the sensor topics belong to (default: MQTT_SENSORS_SITE_ID)}
        {--host= : MQTT broker hostname or IP (default: MQTT_SENSORS_HOST)}
        {--port= : MQTT broker port (default: MQTT_SENSORS_PORT, else 1883)}
        {--tls : Connect over TLS (default: MQTT_SENSORS_TLS)}
        {--username= : MQTT broker username (default: MQTT_SENSORS_USERNAME)}
        {--topic= : Topic filter to subscribe to (default: MQTT_SENSORS_TOPIC, else oblivion/sensors/#)}
        {--max-messages=0 : Stop after N messages (0 = keep going)}
        {--max-connect-attempts=0 : Stop after N failed connects in a row (0 = keep trying)}
        {--timeout= : Seconds to wait for each read (default 5)}';

    protected $description = 'Listen to an MQTT v3.1.1 broker for a Site and record sensor readings and alerts; reconnects by itself';

    public function handle(MqttSensorListener $listener): int
    {
        $config = (array) config('security_devices.listeners.mqtt_sensors', []);
        $option = fn (string $name): ?string => ($value = $this->option($name)) !== null && $value !== '' ? (string) $value : null;

        $settings = [
            'site_id' => (int) ($option('site') ?? $config['site_id'] ?? 0),
            'host' => trim((string) ($option('host') ?? $config['host'] ?? '')),
            'port' => (int) ($option('port') ?? $config['port'] ?? 1883),
            'tls' => (bool) $this->option('tls') || (bool) ($config['tls'] ?? false),
            'username' => $option('username') ?? (is_string($config['username'] ?? null) && $config['username'] !== '' ? $config['username'] : null),
            'password' => is_string($config['password'] ?? null) && $config['password'] !== '' ? $config['password'] : null,
            'topic' => trim((string) ($option('topic') ?? $config['topic'] ?? 'oblivion/sensors/#')),
            'timeout' => max(1, min(30, (int) ($option('timeout') ?? 5))),
        ];

        if ($settings['site_id'] < 1) {
            $this->error('Give the Site the sensors belong to: --site=<id> or MQTT_SENSORS_SITE_ID.');

            return self::FAILURE;
        }
        if ($settings['host'] === '' || $settings['port'] < 1 || $settings['port'] > 65535 || $settings['topic'] === '') {
            $this->error('Give the broker address, port and topic: --host/--port/--topic or the MQTT_SENSORS_ settings.');

            return self::FAILURE;
        }

        $listener->run(
            $settings,
            fn (string $line) => $this->line(now()->utc()->format('Y-m-d H:i:s').' '.$line),
            maxMessages: max(0, (int) $this->option('max-messages')),
            maxConnectAttempts: max(0, (int) $this->option('max-connect-attempts')),
        );

        return self::SUCCESS;
    }
}
