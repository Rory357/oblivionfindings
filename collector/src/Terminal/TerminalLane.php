<?php

namespace Oblivion\Collector\Terminal;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Throwable;

/**
 * S&D Phase G (D5): the hub side of a device's Terminal tab. It runs inside the hub's always-on
 * relay process (the Windows service and the Linux launcher already keep that running).
 *
 * Each poll reports what the open terminals sent back and gets lines to type, terminals to open
 * and to close. A terminal opens only when:
 * - the server signed the request with its configuration key;
 * - the address is a private one;
 * - the sealed login is bound to this hub, this device and this address;
 * - the device's SSH key matches the trusted key.
 * The hub closes a terminal after its idle and hour limits even if Oblivion can't be reached.
 */
final class TerminalLane
{
    private const array OPEN_KEYS = ['uuid', 'device_id', 'site_id', 'address', 'port', 'expected_host_key', 'idle_seconds', 'max_seconds', 'expires_at', 'credential_lease', 'signature'];

    private const int MAX_SESSIONS = 4;

    private const int MAX_OUT_PER_POLL = 65_536;

    /** @var array<string, array{shell: TerminalShell, in_ack: int, out_seq: int, pending: list<array{seq: int, text: string}>, state: string, reason: ?string, started: float, last_input: float, idle: int, max: int, login: ?string}> */
    private array $sessions = [];

    private float $nextPollAt = 0.0;

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $work  the hub's authenticated terminal poll
     * @param  (Closure(string, int): TerminalShell)|null  $connect
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly Closure $work,
        private readonly CredentialLeaseDecryptor $credentials,
        private readonly EnvelopeVerifier $verifier,
        private readonly string $collectorId,
        private ?Closure $connect = null,
        private ?Closure $clock = null,
    ) {
        $this->connect ??= static fn (string $address, int $port): TerminalShell => new PhpseclibTerminalShell($address, $port);
        $this->clock ??= static fn (): float => microtime(true);
    }

    public function active(): bool
    {
        return $this->sessions !== [];
    }

    /** One round: read what devices sent, then (when due) poll Oblivion and act on its answer. */
    public function tick(): void
    {
        $now = ($this->clock)();
        $hasOutput = false;
        foreach ($this->sessions as $uuid => &$session) {
            if ($session['state'] !== 'open') {
                continue;
            }
            $text = self::plain($session['shell']->read());
            if ($text !== '') {
                $session['pending'][] = ['seq' => ++$session['out_seq'], 'text' => $text];
                $hasOutput = true;
            }
            $reason = match (true) {
                ! $session['shell']->isOpen() => 'ended',
                $now - $session['started'] >= $session['max'] => 'too_long',
                $now - $session['last_input'] >= $session['idle'] => 'idle',
                default => null,
            };
            if ($reason !== null) {
                $session['shell']->close();
                $session['state'] = 'closed';
                $session['reason'] = $reason;
                $hasOutput = true;
            }
        }
        unset($session);
        if ($now < $this->nextPollAt && ! $hasOutput) {
            return;
        }
        $this->poll($now);
        // Idle: ask every 10 seconds whether a terminal should open. Live: every half second.
        $this->nextPollAt = $now + ($this->sessions === [] ? 10.0 : 0.5);
    }

    /** Close every terminal (the relay is stopping). */
    public function closeAll(): void
    {
        foreach ($this->sessions as $session) {
            $session['shell']->close();
        }
        $this->sessions = [];
    }

    private function poll(float $now): void
    {
        $report = [];
        $sent = [];
        foreach ($this->sessions as $uuid => $session) {
            $out = [];
            $bytes = 0;
            foreach ($session['pending'] as $chunk) {
                $bytes += strlen($chunk['text']);
                if ($bytes > self::MAX_OUT_PER_POLL) {
                    break;
                }
                $out[] = $chunk;
            }
            $sent[$uuid] = count($out);
            $report[] = [
                'uuid' => $uuid, 'state' => $session['state'], 'reason' => $session['reason'],
                'out' => $out, 'in_ack' => $session['in_ack'], 'login' => $session['login'],
            ];
        }
        try {
            $response = ($this->work)(['sessions' => $report]);
        } catch (Throwable) {
            return; // Kept; sent again next time.
        }
        foreach ($sent as $uuid => $count) {
            if (! isset($this->sessions[$uuid])) {
                continue;
            }
            array_splice($this->sessions[$uuid]['pending'], 0, $count);
            if ($this->sessions[$uuid]['state'] === 'closed' && $this->sessions[$uuid]['pending'] === []) {
                unset($this->sessions[$uuid]);
            }
        }
        foreach (array_slice(is_array($response['close'] ?? null) ? $response['close'] : [], 0, 20) as $uuid) {
            if (is_string($uuid) && isset($this->sessions[$uuid])) {
                $this->sessions[$uuid]['shell']->close();
                unset($this->sessions[$uuid]);
            }
        }
        foreach (array_slice(is_array($response['open'] ?? null) ? $response['open'] : [], 0, self::MAX_SESSIONS) as $open) {
            if (is_array($open)) {
                $this->open($open, $now);
            }
        }
        foreach (array_slice(is_array($response['input'] ?? null) ? $response['input'] : [], 0, 20) as $row) {
            $uuid = is_array($row) && is_string($row['uuid'] ?? null) ? $row['uuid'] : null;
            if ($uuid === null || ! isset($this->sessions[$uuid]) || $this->sessions[$uuid]['state'] !== 'open') {
                continue;
            }
            foreach (array_slice(is_array($row['lines'] ?? null) ? $row['lines'] : [], 0, 50) as $line) {
                if (! is_array($line) || ! is_int($line['seq'] ?? null) || ! is_string($line['text'] ?? null)
                    || $line['seq'] <= $this->sessions[$uuid]['in_ack'] || strlen($line['text']) > 1024) {
                    continue;
                }
                $text = $line['text'] === "\x03" ? "\x03" : preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/', '', $line['text'])."\n";
                $this->sessions[$uuid]['shell']->write((string) $text);
                $this->sessions[$uuid]['in_ack'] = $line['seq'];
                $this->sessions[$uuid]['last_input'] = $now;
            }
        }
    }

    /** @param array<string, mixed> $open */
    private function open(array $open, float $now): void
    {
        $uuid = is_string($open['uuid'] ?? null) ? strtolower($open['uuid']) : '';
        if ($uuid === '' || isset($this->sessions[$uuid]) || count($this->sessions) >= self::MAX_SESSIONS) {
            return;
        }
        $refuse = function (string $reason) use ($uuid, $now): void {
            $this->sessions[$uuid] = $this->record(new ClosedShell, $now, 'closed', $reason, 0, 0, null);
        };
        $keys = array_keys($open);
        if ($keys !== self::OPEN_KEYS || ! $this->verifier->verifyDetached(self::canonical($open), $open['signature'])) {
            return; // Not from Oblivion: say nothing and do nothing.
        }
        $address = $open['address'];
        $lease = $open['credential_lease'];
        try {
            $expires = new DateTimeImmutable((string) $open['expires_at']);
        } catch (Throwable) {
            return;
        }
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || ! is_int($open['port']) || $open['port'] < 1 || $open['port'] > 65535
            || ! is_string($open['expected_host_key']) || preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/', $open['expected_host_key']) !== 1
            || ! is_int($open['idle_seconds']) || $open['idle_seconds'] < 60 || $open['idle_seconds'] > 3600
            || ! is_int($open['max_seconds']) || $open['max_seconds'] < 60 || $open['max_seconds'] > 14_400
            || $expires->getTimestamp() < (int) $now
            || ! is_array($lease) || ($lease['protocol'] ?? null) !== 'terminal.ssh' || ($lease['target'] ?? null) !== $address
            || ($lease['device_id'] ?? null) !== $open['device_id'] || ($lease['site_id'] ?? null) !== $open['site_id']
            || ! hash_equals(strtolower($this->collectorId), strtolower((string) ($lease['collector_id'] ?? '')))) {
            $refuse('unreachable');

            return;
        }
        $material = [];
        try {
            $material = $this->credentials->open(['credential_lease' => $lease], new DateTimeImmutable('@'.(int) $now));
            $username = is_string($material['username'] ?? null) ? trim($material['username']) : '';
            if ($username === '' || strlen($username) > 64) {
                $refuse('login_failed');

                return;
            }
            $shell = ($this->connect)($address, $open['port']);
            $offered = $shell->hostKeyFingerprint();
            if ($offered === null) {
                $shell->close();
                $refuse('unreachable');

                return;
            }
            if (! hash_equals($open['expected_host_key'], $offered)) {
                // No login is sent to a device whose key changed.
                $shell->close();
                $refuse('host_key_changed');

                return;
            }
            if (! $shell->login($username, $material)) {
                $shell->close();
                $refuse('login_failed');

                return;
            }
            $this->sessions[$uuid] = $this->record($shell, $now, 'open', null, (int) $open['idle_seconds'], (int) $open['max_seconds'], $username);
        } catch (Throwable) {
            $refuse('unreachable');
        } finally {
            foreach ($material as &$value) {
                if (is_string($value) && $value !== '') {
                    sodium_memzero($value);
                }
                $value = null;
            }
            unset($value);
        }
    }

    /** @return array{shell: TerminalShell, in_ack: int, out_seq: int, pending: list<array{seq: int, text: string}>, state: string, reason: ?string, started: float, last_input: float, idle: int, max: int, login: ?string} */
    private function record(TerminalShell $shell, float $now, string $state, ?string $reason, int $idle, int $max, ?string $login): array
    {
        return [
            'shell' => $shell, 'in_ack' => 0, 'out_seq' => 0, 'pending' => [], 'state' => $state, 'reason' => $reason,
            'started' => $now, 'last_input' => $now, 'idle' => max(60, $idle), 'max' => max(60, $max), 'login' => $login,
        ];
    }

    /** The exact bytes the server signed: the "open" without its signature, as the server encoded it. */
    public static function canonical(array $open): string
    {
        unset($open['signature']);

        return json_encode($open, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** Text without terminal colour and cursor codes, at most 8 KB per piece. */
    private static function plain(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = (string) preg_replace('/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[@-Z\\\\-_])/', '', $text);
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        return mb_strcut($text, 0, 8192, 'UTF-8');
    }
}
