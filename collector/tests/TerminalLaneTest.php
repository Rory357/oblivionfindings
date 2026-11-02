<?php

use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Oblivion\Collector\Terminal\TerminalLane;
use Oblivion\Collector\Terminal\TerminalShell;

/*
 * S&D Phase G (D5): the hub side of a device's Terminal tab. A terminal opens only when Oblivion
 * signed the request, to a private address, with a login sealed to this hub and device, and only
 * after the device's SSH key matches the trusted one.
 */

const TERMINAL_TRUSTED_KEY = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const TERMINAL_OTHER_KEY = 'SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

final class FakeTerminalShell implements TerminalShell
{
    public array $written = [];

    public array $logins = [];

    public bool $open = true;

    /** @param list<string> $outputs */
    public function __construct(public ?string $key = TERMINAL_TRUSTED_KEY, public array $outputs = [], public bool $loginOk = true) {}

    public function hostKeyFingerprint(): ?string
    {
        return $this->key;
    }

    public function login(string $username, array $material): bool
    {
        $this->logins[] = $username;

        return $this->loginOk;
    }

    public function write(string $text): void
    {
        $this->written[] = $text;
    }

    public function read(): string
    {
        return array_shift($this->outputs) ?? '';
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }
}

function terminalKeys(): string
{
    static $pair;

    return $pair ??= sodium_crypto_sign_seed_keypair(str_repeat("\x37", SODIUM_CRYPTO_SIGN_SEEDBYTES));
}

/** @return array<string, mixed> */
function terminalOpen(array $overrides = [], bool $sign = true): array
{
    $open = array_replace([
        'uuid' => '3f1e2d4c-5b6a-4789-9abc-def012345678',
        'device_id' => '1',
        'site_id' => 9,
        'address' => '10.44.0.10',
        'port' => 22,
        'expected_host_key' => TERMINAL_TRUSTED_KEY,
        'idle_seconds' => 900,
        'max_seconds' => 3600,
        'expires_at' => '2026-07-23T12:02:00+00:00',
        'credential_lease' => sealedCollectorCredentialLease(
            ['username' => 'netadmin', 'password' => 'TERMINAL-TEST-ONLY-PASSWORD'],
            ['device_id' => '1', 'protocol' => 'terminal.ssh', 'target' => '10.44.0.10'],
        ),
    ], $overrides);
    $open['signature'] = $sign ? base64_encode(sodium_crypto_sign_detached(TerminalLane::canonical($open), sodium_crypto_sign_secretkey(terminalKeys()))) : base64_encode(str_repeat("\0", 64));

    return $open;
}

/**
 * @param  list<array<string, mixed>>  $responses
 * @param  list<array<string, mixed>>  $reports
 */
function terminalLane(array &$responses, array &$reports, FakeTerminalShell $shell, float &$now): TerminalLane
{
    return new TerminalLane(
        function (array $report) use (&$responses, &$reports): array {
            $reports[] = $report;

            return array_shift($responses) ?? ['open' => [], 'input' => [], 'close' => []];
        },
        new CredentialLeaseDecryptor(collectorIdentitySecretKey()),
        new EnvelopeVerifier(sodium_crypto_sign_publickey(terminalKeys())),
        '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        fn (string $address, int $port): TerminalShell => $shell,
        function () use (&$now): float {
            return $now;
        },
    );
}

it('ignores a terminal Oblivion didn’t sign', function () {
    $responses = [['open' => [terminalOpen([], false)], 'input' => [], 'close' => []]];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $shell = new FakeTerminalShell;
    $lane = terminalLane($responses, $reports, $shell, $now);

    $lane->tick();

    expect($lane->active())->toBe(false)
        ->and($shell->logins)->toBe([]);
});

it('sends no login to a device whose key changed, and says so', function () {
    $responses = [['open' => [terminalOpen()], 'input' => [], 'close' => []]];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $shell = new FakeTerminalShell(TERMINAL_OTHER_KEY);
    $lane = terminalLane($responses, $reports, $shell, $now);

    $lane->tick();
    $now += 11;
    $lane->tick();

    expect($shell->logins)->toBe([])
        ->and($reports[1]['sessions'][0]['state'])->toBe('closed')
        ->and($reports[1]['sessions'][0]['reason'])->toBe('host_key_changed');
});

it('refuses a public address before connecting', function () {
    $responses = [['open' => [terminalOpen(['address' => '8.8.8.8'])], 'input' => [], 'close' => []]];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $shell = new FakeTerminalShell;
    $lane = terminalLane($responses, $reports, $shell, $now);

    $lane->tick();
    $now += 11;
    $lane->tick();

    expect($shell->logins)->toBe([])
        ->and($reports[1]['sessions'][0]['reason'])->toBe('unreachable');
});

it('opens with the saved login, types each line once and sends back what the device says', function () {
    $responses = [
        ['open' => [terminalOpen()], 'input' => [], 'close' => []],
        ['open' => [], 'input' => [['uuid' => '3f1e2d4c-5b6a-4789-9abc-def012345678', 'lines' => [['seq' => 1, 'text' => 'show version'], ['seq' => 2, 'text' => "\x03"]]]], 'close' => []],
        ['open' => [], 'input' => [['uuid' => '3f1e2d4c-5b6a-4789-9abc-def012345678', 'lines' => [['seq' => 1, 'text' => 'show version']]]], 'close' => []],
    ];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $shell = new FakeTerminalShell(TERMINAL_TRUSTED_KEY, ['', "\e[1mRouterOS 7.16\e[0m\r\nok"]);
    $lane = terminalLane($responses, $reports, $shell, $now);

    $lane->tick(); // poll: opens
    $now += 1;
    $lane->tick(); // poll: types two lines
    $now += 1;
    $lane->tick(); // reads output, polls: the same line again is not typed twice

    expect($shell->logins)->toBe(['netadmin'])
        ->and($shell->written)->toBe(["show version\n", "\x03"])
        ->and($reports[1]['sessions'][0]['state'])->toBe('open')
        ->and($reports[1]['sessions'][0]['login'])->toBe('netadmin')
        ->and($reports[2]['sessions'][0]['out'])->toBe([['seq' => 1, 'text' => "RouterOS 7.16\nok"]])
        ->and($reports[2]['sessions'][0]['in_ack'])->toBe(2)
        ->and(json_encode($reports))->not->toContain('TERMINAL-TEST-ONLY-PASSWORD');
});

it('closes a terminal after 15 minutes without typing, even if Oblivion can’t be reached', function () {
    $responses = [['open' => [terminalOpen()], 'input' => [], 'close' => []]];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $shell = new FakeTerminalShell;
    $lane = terminalLane($responses, $reports, $shell, $now);

    $lane->tick();
    $now += 901;
    $lane->tick();

    expect($shell->open)->toBe(false)
        ->and(end($reports)['sessions'][0]['reason'])->toBe('idle');
});

it('closes a terminal when Oblivion says so', function () {
    $responses = [
        ['open' => [terminalOpen()], 'input' => [], 'close' => []],
        ['open' => [], 'input' => [], 'close' => ['3f1e2d4c-5b6a-4789-9abc-def012345678']],
    ];
    $reports = [];
    $now = (float) collectorNow()->getTimestamp();
    $shell = new FakeTerminalShell;
    $lane = terminalLane($responses, $reports, $shell, $now);

    $lane->tick();
    $now += 1;
    $lane->tick();

    expect($shell->open)->toBe(false)
        ->and($lane->active())->toBe(false);
});
