<?php

use App\Domain\SecurityDevices\Iot\Mqtt\MqttConnectionLost;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorIntakeService;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorListener;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttSubscriber;
use App\Domain\SecurityDevices\Services\ListenerHeartbeat;

/*
 * Phase A, item A4 (docs/security-devices/GOAL.md): the MQTT sensor listener takes its password from
 * config (so it still works once settings are cached) and reconnects by itself, with back-off.
 */

/** A stand-in broker connection that plays a script, one step per connect. */
final class ScriptedMqttBroker implements MqttSubscriber
{
    /** @var list<array<string, mixed>> */
    public array $connects = [];

    public int $session = -1;

    /** @var list<string> */
    public array $events = [];

    /**
     * @param  list<array{connect: string, reads?: list<array<string, mixed>|null|string>, ping?: bool}>  $script
     */
    public function __construct(private array $script) {}

    public function connect(string $host, int $port = 1883, string $clientId = 'oblivion-mqtt', ?string $username = null, ?string $password = null, bool $tls = false, int $keepAliveSeconds = 30, int $timeoutSeconds = 5): void
    {
        $this->session++;
        $this->connects[] = compact('host', 'port', 'username', 'password', 'tls');
        $step = $this->script[$this->session] ?? ['connect' => 'refuse'];
        if ($step['connect'] === 'refuse') {
            $this->events[] = 'refused';
            throw new RuntimeException('connection refused');
        }
        $this->events[] = 'connected';
    }

    public function subscribe(string $topicFilter, int $qos = 0): void
    {
        $this->events[] = 'subscribed '.$topicFilter;
    }

    public function readNextPublish(): ?array
    {
        $reads = &$this->script[$this->session]['reads'];
        $next = $reads === [] ? null : array_shift($reads);
        if ($next === 'drop') {
            $this->events[] = 'dropped';
            throw new MqttConnectionLost('The MQTT broker closed the connection.');
        }

        return is_array($next) ? $next : null;
    }

    public function ping(): bool
    {
        $answered = $this->script[$this->session]['ping'] ?? true;
        $this->events[] = $answered ? 'ping answered' : 'ping unanswered';

        return $answered;
    }

    public function disconnect(): void {}
}

function listenerWith(ScriptedMqttBroker $broker, array &$sleeps): MqttSensorListener
{
    return new MqttSensorListener(
        app(MqttSensorIntakeService::class),
        app(ListenerHeartbeat::class),
        fn (): MqttSubscriber => $broker,
        function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    );
}

it('waits 1, 2, 4 … up to 60 seconds between failed connects', function (): void {
    expect(array_map(MqttSensorListener::backoffSeconds(...), [1, 2, 3, 4, 5, 6, 7, 8, 20]))
        ->toBe([1, 2, 4, 8, 16, 32, 60, 60, 60]);

    $sleeps = [];
    $broker = new ScriptedMqttBroker([]);
    $handled = listenerWith($broker, $sleeps)->run([
        'site_id' => 1, 'host' => 'broker.test', 'port' => 1883, 'tls' => false, 'username' => null,
        'password' => null, 'topic' => 'oblivion/sensors/#', 'timeout' => 1,
    ], fn () => null, maxConnectAttempts: 4);

    expect($handled)->toBe(0)
        ->and($broker->events)->toBe(['refused', 'refused', 'refused', 'refused'])
        ->and($sleeps)->toBe([1, 2, 4]);
});

it('reconnects after the broker restarts and keeps receiving readings', function (): void {
    $sleeps = [];
    $lines = [];
    $reading = ['topic' => 'oblivion/sensors/lounge', 'payload' => '{"temperature":21.4}', 'qos' => 0, 'retain' => false];
    // Runs continuously (no message limit); the script ends with a refused connect, which stops the
    // run because only one failed connect in a row is allowed here.
    $broker = new ScriptedMqttBroker([
        ['connect' => 'ok', 'reads' => [$reading, 'drop']],
        ['connect' => 'ok', 'reads' => [null], 'ping' => false],
        ['connect' => 'ok', 'reads' => [$reading, 'drop']],
        ['connect' => 'refuse'],
    ]);

    $handled = listenerWith($broker, $sleeps)->run([
        'site_id' => 1, 'host' => 'broker.test', 'port' => 1883, 'tls' => false, 'username' => null,
        'password' => null, 'topic' => 'oblivion/sensors/#', 'timeout' => 1,
    ], function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, maxConnectAttempts: 1);

    expect($handled)->toBe(2)
        ->and($broker->events)->toBe([
            'connected', 'subscribed oblivion/sensors/#', 'dropped',
            'connected', 'subscribed oblivion/sensors/#', 'ping unanswered',
            'connected', 'subscribed oblivion/sensors/#', 'dropped',
            'refused',
        ])
        ->and($sleeps)->toBe([1, 1, 1])
        ->and($lines)->toContain('The MQTT broker closed the connection. Reconnecting.')
        ->and($lines)->toContain('The MQTT broker stopped answering. Reconnecting.')
        ->and($lines)->toContain('Reading on oblivion/sensors/lounge: unmatched_sensor.')
        ->and(app(ListenerHeartbeat::class)->lastBeat(ListenerHeartbeat::MQTT_SENSORS))->not->toBeNull();
});

it('takes the broker password from config, so cached settings still connect', function (): void {
    // With settings cached, env() returns nothing at run time; config keeps the value.
    config()->set('security_devices.listeners.mqtt_sensors', [
        'enabled' => true, 'site_id' => 7, 'host' => 'broker.test', 'port' => 8883, 'tls' => true,
        'username' => 'sensors', 'password' => 'test-only-password', 'topic' => 'oblivion/sensors/#',
    ]);
    putenv('MQTT_BROKER_PASSWORD');

    $sleeps = [];
    $broker = new ScriptedMqttBroker([['connect' => 'ok', 'reads' => [null]]]);
    app()->instance(MqttSensorListener::class, listenerWith($broker, $sleeps));

    $this->artisan('security-devices:listen-mqtt', ['--max-messages' => 1])->assertSuccessful();

    expect($broker->connects)->toHaveCount(1)
        ->and($broker->connects[0])->toBe([
            'host' => 'broker.test', 'port' => 8883, 'username' => 'sensors', 'password' => 'test-only-password', 'tls' => true,
        ]);
});

it('refuses to start without a Site or a broker address', function (): void {
    config()->set('security_devices.listeners.mqtt_sensors', ['site_id' => 0, 'host' => '']);

    $this->artisan('security-devices:listen-mqtt')->assertFailed();
    $this->artisan('security-devices:listen-mqtt', ['--site' => 3])->assertFailed();
});
