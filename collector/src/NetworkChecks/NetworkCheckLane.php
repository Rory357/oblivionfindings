<?php

namespace Oblivion\Collector\NetworkChecks;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Throwable;

/**
 * S&D Phase I (pop-up 97): Obli's network checks, run inside the hub's always-on relay process.
 * Each poll sends what was found and gets the next checks.
 *
 * Every check only looks. One runs only when:
 * - Oblivion signed it and it hasn't expired;
 * - its kind is one of the four below, with exactly its parameters (anything else is refused);
 * - the address the hub contacts is one this hub's own signed work list already lists for that
 *   device, inside its allowed networks, for one of its Sites (the scope file the normal cycle
 *   writes from the verified config, as ScopeGuard checks);
 * - a UniFi check's sealed login is for exactly that console and Site, and the console shows the
 *   trusted key.
 * One check at a time, each bounded to 20 seconds and a small answer. Re-running a read is
 * harmless, so a check asked for again is answered again from memory.
 */
final class NetworkCheckLane
{
    private const array DETAIL_KEYS = ['job', 'kind', 'site_id', 'device_id', 'params', 'expires_at', 'credential_lease', 'signature'];

    /** Each kind's parameters, exactly (the server sends the same). */
    private const array KINDS = [
        'delay' => ['address'],
        'gateway_speed' => ['console', 'port', 'trusted_pin'],
        'switch_ports' => ['console', 'port', 'trusted_pin', 'address', 'mac'],
        'wifi' => ['console', 'port', 'trusted_pin', 'address', 'mac', 'hours'],
    ];

    private const int CHECK_SECONDS = 20;

    private const int MAX_RESULT_BYTES = 16_384;

    /** The scope file is rewritten every cycle; one older than this means the hub's work list is stale. */
    private const int SCOPE_MAX_AGE = 3600;

    /** @var list<array<string, mixed>> results to send with the next poll */
    private array $pending = [];

    /** @var list<array<string, mixed>> signed checks waiting to run, oldest first */
    private array $queue = [];

    /** @var array<string, array<string, mixed>> job => the answer already given */
    private array $answered = [];

    private float $nextPollAt = 0.0;

    private float $activeUntil = 0.0;

    /** @var array{at: float, scope: ?array<string, mixed>} */
    private array $scope = ['at' => 0.0, 'scope' => null];

    private readonly DelayProbe $delay;

    private readonly UnifiNetworkReader $unifi;

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $work
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly Closure $work,
        private readonly EnvelopeVerifier $verifier,
        private readonly CredentialLeaseDecryptor $credentials,
        private readonly string $scopeFile,
        ?DelayProbe $delay = null,
        ?UnifiNetworkReader $unifi = null,
        private ?Closure $clock = null,
    ) {
        $this->delay = $delay ?? new DelayProbe;
        $this->unifi = $unifi ?? new UnifiNetworkReader;
        $this->clock ??= static fn (): float => microtime(true);
    }

    public function active(): bool
    {
        return $this->queue !== [] || ($this->clock)() < $this->activeUntil;
    }

    public function tick(): void
    {
        $now = ($this->clock)();
        if ($this->queue !== []) {
            $details = array_shift($this->queue);
            $this->finish($this->run($details, $now));
        }
        if ($now < $this->nextPollAt && $this->pending === []) {
            return;
        }
        try {
            $response = ($this->work)(['results' => $this->pending]);
        } catch (Throwable) {
            $this->nextPollAt = $now + 5.0;

            return;
        }
        $this->pending = [];
        $jobs = array_slice(is_array($response['jobs'] ?? null) ? $response['jobs'] : [], 0, 3);
        foreach ($jobs as $details) {
            if (! is_array($details) || array_keys($details) !== self::DETAIL_KEYS || ! is_string($details['signature'])
                || ! $this->verifier->verifyDetached(self::canonical($details), $details['signature'])) {
                continue; // Not from Oblivion: nothing is run and nothing is said.
            }
            $uuid = strtolower((string) $details['job']);
            if (isset($this->answered[$uuid])) {
                $this->pending[] = $this->answered[$uuid];

                continue;
            }
            if (count($this->queue) < 6 && ! in_array($uuid, array_map(static fn (array $queued): string => strtolower((string) $queued['job']), $this->queue), true)) {
                $this->queue[] = $details;
            }
        }
        if ($jobs !== []) {
            $this->activeUntil = $now + 60.0;
        }
        $this->nextPollAt = $now + ($this->active() ? 1.0 : 5.0);
    }

    /** @param array<string, mixed> $row */
    private function finish(array $row): void
    {
        $this->answered[(string) $row['job']] = $row;
        if (count($this->answered) > 200) {
            $this->answered = array_slice($this->answered, -100, null, true);
        }
        $this->pending[] = $row;
        $this->nextPollAt = 0.0;
    }

    /**
     * One signed check: refused, not available, or done with its bounded result.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function run(array $details, float $now): array
    {
        $uuid = strtolower((string) $details['job']);
        $refusal = $this->refusal($details, $now);
        if ($refusal !== null) {
            return ['job' => $uuid, 'state' => 'not_available', 'reason' => $refusal];
        }
        $kind = (string) $details['kind'];
        $params = (array) $details['params'];
        $deadline = microtime(true) + self::CHECK_SECONDS;
        if ($kind === 'delay') {
            $measured = $this->delay->measure((string) $params['address'], self::CHECK_SECONDS);
            $outcome = ($measured['measured'] ?? false) === true
                ? ['state' => 'done', 'result' => array_diff_key($measured, ['measured' => true])]
                : ['state' => 'not_available', 'reason' => 'not_measured'];
        } else {
            $material = [];
            try {
                $material = $this->credentials->open($details, new DateTimeImmutable('@'.(int) $now));
            } catch (Throwable) {
                $this->erase($material);

                return ['job' => $uuid, 'state' => 'not_available', 'reason' => 'no_login'];
            }
            try {
                $outcome = $this->unifi->read($kind, $params, $material, $deadline, new DateTimeImmutable('@'.(int) $now));
            } catch (Throwable) {
                $outcome = ['state' => 'not_available', 'reason' => 'unreachable'];
            } finally {
                $this->erase($material);
            }
        }
        if (microtime(true) > $deadline + 2.0) {
            return ['job' => $uuid, 'state' => 'not_available', 'reason' => 'timeout'];
        }
        if ($outcome['state'] !== 'done') {
            return ['job' => $uuid, 'state' => 'not_available', 'reason' => (string) $outcome['reason']];
        }
        $encoded = json_encode($outcome['result'], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (! is_string($encoded) || strlen($encoded) > self::MAX_RESULT_BYTES) {
            return ['job' => $uuid, 'state' => 'not_available', 'reason' => 'refused'];
        }

        return ['job' => $uuid, 'state' => 'done', 'result' => $outcome['result']];
    }

    /** Why a signed check still can't run (a reason code), or null. @param array<string, mixed> $details */
    private function refusal(array $details, float $now): ?string
    {
        try {
            $expires = new DateTimeImmutable((string) $details['expires_at']);
        } catch (Throwable) {
            return 'refused';
        }
        if ($expires->getTimestamp() < (int) $now) {
            return 'expired';
        }
        $kind = $details['kind'];
        $params = $details['params'];
        if (! is_string($kind) || ! isset(self::KINDS[$kind]) || ! is_array($params) || array_keys($params) !== self::KINDS[$kind]
            || ! self::validParams($kind, $params) || ! is_int($details['site_id']) || ! is_string($details['device_id'])
            || preg_match('/\A[1-9][0-9]{0,18}\z/', $details['device_id']) !== 1) {
            return 'refused';
        }
        $contact = (string) ($kind === 'delay' ? $params['address'] : $params['console']);
        $scope = $this->scope($now);
        $listed = $scope['devices'][$details['device_id']] ?? null;
        if ($scope === null || ! in_array($details['site_id'], $scope['allowed_site_ids'], true)
            || ! is_array($listed) || ! in_array($contact, $listed, true) || ! self::inside($contact, $scope['cidrs'])) {
            return 'out_of_scope';
        }
        $lease = $details['credential_lease'];
        if ($kind === 'delay') {
            return $lease === null ? null : 'refused';
        }
        if (! is_array($lease) || ($lease['protocol'] ?? null) !== 'https' || ($lease['target'] ?? null) !== $contact
            || ($lease['device_id'] ?? null) !== $details['device_id'] || ($lease['site_id'] ?? null) !== $details['site_id']) {
            return 'no_login';
        }

        return null;
    }

    /** @param array<string, mixed> $params */
    private static function validParams(string $kind, array $params): bool
    {
        $private = static fn (mixed $value): bool => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false
            && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if ($kind === 'delay') {
            return $private($params['address']);
        }
        $address = $params['address'] ?? '';
        $mac = $params['mac'] ?? '';

        return $private($params['console']) && $params['port'] === 443
            && is_string($params['trusted_pin']) && preg_match('/\Asha256\/\/[A-Za-z0-9+\/]{43}=\z/', $params['trusted_pin']) === 1
            && ($kind === 'gateway_speed' || (
                is_string($address) && ($address === '' || $private($address))
                && is_string($mac) && ($mac === '' || preg_match('/\A[0-9a-f]{2}(:[0-9a-f]{2}){5}\z/', $mac) === 1)
                && ($address !== '' || $mac !== '')
            ))
            && ($kind !== 'wifi' || $params['hours'] === 72);
    }

    /**
     * The hub's own allowed Sites, networks and device addresses, from its verified work list.
     *
     * @return array{allowed_site_ids: list<int>, cidrs: list<string>, devices: array<string, list<string>>}|null
     */
    private function scope(float $now): ?array
    {
        if ($now - $this->scope['at'] < 30.0) {
            return $this->scope['scope'];
        }
        $scope = null;
        try {
            if (is_file($this->scopeFile) && filesize($this->scopeFile) <= 2_097_152) {
                $stored = json_decode((string) file_get_contents($this->scopeFile), true, 16, JSON_THROW_ON_ERROR);
                // Only while the signed work allows network checks and hasn't expired.
                if (is_array($stored) && ($stored['version'] ?? null) === 1 && is_int($stored['written_at'] ?? null)
                    && $now - $stored['written_at'] <= self::SCOPE_MAX_AGE
                    && is_int($stored['expires_at'] ?? null) && $stored['expires_at'] > (int) $now
                    && is_array($stored['protocols'] ?? null) && in_array('network_checks', $stored['protocols'], true)
                    && is_array($stored['allowed_site_ids'] ?? null) && is_array($stored['cidrs'] ?? null) && is_array($stored['devices'] ?? null)) {
                    $scope = [
                        'allowed_site_ids' => array_values(array_filter($stored['allowed_site_ids'], 'is_int')),
                        'cidrs' => array_values(array_filter($stored['cidrs'], 'is_string')),
                        'devices' => array_map(static fn (mixed $targets): array => is_array($targets) ? array_values(array_filter($targets, 'is_string')) : [], $stored['devices']),
                    ];
                }
            }
        } catch (Throwable) {
            $scope = null;
        }
        $this->scope = ['at' => $now, 'scope' => $scope];

        return $scope;
    }

    /** @param list<string> $cidrs */
    private static function inside(string $address, array $cidrs): bool
    {
        $candidate = @inet_pton($address);
        if ($candidate === false) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            $parts = explode('/', $cidr, 2);
            $network = count($parts) === 2 && ctype_digit($parts[1]) ? @inet_pton($parts[0]) : false;
            if ($network === false || strlen($network) !== strlen($candidate)) {
                continue;
            }
            $prefix = (int) $parts[1];
            if ($prefix > strlen($network) * 8) {
                continue;
            }
            $whole = intdiv($prefix, 8);
            $bits = $prefix % 8;
            if (substr($network, 0, $whole) !== substr($candidate, 0, $whole)) {
                continue;
            }
            if ($bits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            if ((ord($network[$whole]) & $mask) === (ord($candidate[$whole]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** The exact bytes Oblivion signs: the details without their signature. @param array<string, mixed> $details */
    public static function canonical(array $details): string
    {
        unset($details['signature']);

        return json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, scalar|null> $material */
    private function erase(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                sodium_memzero($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
