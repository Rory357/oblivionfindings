<?php

use Oblivion\Collector\Mqtt\MqttRelayServer;
use Oblivion\Collector\Mqtt\RelayInbox;

/*
 * S&D Phase B (D21): the hub's sensor relay over real local sockets. Each gateway logs in with its
 * own login and may publish only under its own topic; readings land in the encrypted inbox with the
 * time the hub received them.
 */

const RELAY_TEST_USERNAME = 'gw-abcdefghijkl';
const RELAY_TEST_PASSWORD = 'test-only-relay-password';

function relayPacket(int $first, string $body): string
{
    $length = strlen($body);
    $encoded = '';
    do {
        $byte = $length % 128;
        $length = intdiv($length, 128);
        $encoded .= chr($length > 0 ? $byte | 0x80 : $byte);
    } while ($length > 0);

    return chr($first).$encoded.$body;
}

function relayString(string $value): string
{
    return pack('n', strlen($value)).$value;
}

function relayConnect(string $username, string $password): string
{
    return relayPacket(0x10, relayString('MQTT').chr(4).chr(0xC2).pack('n', 60).relayString('ug65').relayString($username).relayString($password));
}

/** @return array{server: MqttRelayServer, inbox: RelayInbox, directory: string} */
function relayFixture(): array
{
    $directory = collectorTempDirectory('relay');
    $inbox = new RelayInbox($directory.'/relay-inbox', RelayInbox::keyFor(collectorIdentitySecretKey()));
    $relay = [
        'listen_port' => 1883,
        'max_payload_bytes' => 4096,
        'logins' => [[
            'username' => RELAY_TEST_USERNAME,
            'password_hash' => password_hash(RELAY_TEST_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'site_id' => 9,
            'topic_prefix' => 'oblivion/9/'.RELAY_TEST_USERNAME.'/',
        ]],
    ];
    $server = new MqttRelayServer($inbox, static fn (): array => $relay, static fn (): int => 1_784_808_000);
    $server->listen('127.0.0.1', 0);

    return ['server' => $server, 'inbox' => $inbox, 'directory' => $directory];
}

/** @return resource */
function relayClient(MqttRelayServer $server)
{
    $client = stream_socket_client('tcp://127.0.0.1:'.$server->port(), $errorNumber, $errorText, 2);
    stream_set_timeout($client, 2);

    return $client;
}

/** Let the server handle what the client sent, then read what it answered. */
function relayExchange(MqttRelayServer $server, $client, string $bytes, int $expect): string
{
    if ($bytes !== '') {
        fwrite($client, $bytes);
    }
    $answer = '';
    for ($round = 0; $round < 40 && strlen($answer) < $expect; $round++) {
        $server->poll(0.05);
        stream_set_blocking($client, false);
        $answer .= (string) fread($client, 1024);
        stream_set_blocking($client, true);
    }

    return $answer;
}

it('takes a QoS 1 reading under the gateway’s own topic and acknowledges it once it is kept', function () {
    ['server' => $server, 'inbox' => $inbox, 'directory' => $directory] = relayFixture();
    try {
        $client = relayClient($server);
        expect(relayExchange($server, $client, relayConnect(RELAY_TEST_USERNAME, RELAY_TEST_PASSWORD), 4))->toBe("\x20\x02\x00\x00");

        $topic = 'oblivion/9/'.RELAY_TEST_USERNAME.'/fridge';
        $reading = '{"temperature":4.6,"battery":81}';
        expect(relayExchange($server, $client, relayPacket(0x32, relayString($topic)."\x00\x07".$reading), 4))->toBe("\x40\x02\x00\x07");

        $held = $inbox->take(10);
        expect($held)->toHaveCount(1)
            ->and($held[0]['item'])->toBe([
                'item_type' => 'sensor_reading',
                'username' => RELAY_TEST_USERNAME,
                'topic' => $topic,
                'payload_base64' => base64_encode($reading),
                'received_at' => '2026-07-23T12:00:00+00:00',
            ])
            ->and(file_get_contents($directory.'/relay-inbox/'.$held[0]['file']))->not->toContain('temperature');
        fclose($client);
    } finally {
        $server->close();
        removeCollectorDirectory($directory);
    }
});

it('refuses a wrong password, and keeps nothing a gateway sends to another topic', function () {
    ['server' => $server, 'inbox' => $inbox, 'directory' => $directory] = relayFixture();
    try {
        $wrong = relayClient($server);
        expect(relayExchange($server, $wrong, relayConnect(RELAY_TEST_USERNAME, 'not-the-password'), 4))->toBe("\x20\x02\x00\x04");
        fclose($wrong);

        $client = relayClient($server);
        relayExchange($server, $client, relayConnect(RELAY_TEST_USERNAME, RELAY_TEST_PASSWORD), 4);
        relayExchange($server, $client, relayPacket(0x32, relayString('oblivion/9/gw-otherdevice1/fridge')."\x00\x08".'{"t":1}'), 4);
        expect($inbox->take(10))->toBe([]);
        fclose($client);
    } finally {
        $server->close();
        removeCollectorDirectory($directory);
    }
});

it('ignores an address for 10 minutes after 5 wrong logins', function () {
    ['server' => $server, 'directory' => $directory] = relayFixture();
    try {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $client = relayClient($server);
            relayExchange($server, $client, relayConnect(RELAY_TEST_USERNAME, 'guess-'.$attempt), 4);
            fclose($client);
        }
        $client = relayClient($server);
        expect(relayExchange($server, $client, relayConnect(RELAY_TEST_USERNAME, RELAY_TEST_PASSWORD), 4))->toBe('');
        fclose($client);
    } finally {
        $server->close();
        removeCollectorDirectory($directory);
    }
});

it('refuses subscriptions and anonymous connections', function () {
    ['server' => $server, 'directory' => $directory] = relayFixture();
    try {
        $anonymous = relayClient($server);
        $noLogin = relayPacket(0x10, relayString('MQTT').chr(4).chr(0x02).pack('n', 60).relayString('ug65'));
        expect(relayExchange($server, $anonymous, $noLogin, 4))->toBe("\x20\x02\x00\x05");
        fclose($anonymous);

        $client = relayClient($server);
        relayExchange($server, $client, relayConnect(RELAY_TEST_USERNAME, RELAY_TEST_PASSWORD), 4);
        relayExchange($server, $client, relayPacket(0x82, "\x00\x01".relayString('oblivion/#')."\x00"), 2);
        expect(feof($client) || fread($client, 1) === '')->toBeTrue();
        fclose($client);
    } finally {
        $server->close();
        removeCollectorDirectory($directory);
    }
});
