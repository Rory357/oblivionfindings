<?php

namespace Oblivion\Collector\WebPages;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Throwable;

/**
 * S&D Phase G (pop-up 61): the hub side of a device's web page. It runs inside the hub's always-on
 * relay process. Each poll sends back the answers to the last page requests and gets new ones.
 *
 * A request is fetched only when:
 * - Oblivion signed its session details;
 * - the address is private;
 * - https uses the device's trusted key.
 * The hub keeps each session's cookies itself (they never reach the browser), doesn't follow
 * redirects, and passes at most 3 MB back.
 */
final class WebLane
{
    private const int MAX_BYTES = 3_145_728;

    private const array PASS_REQUEST = ['accept', 'accept-language', 'content-type', 'if-none-match', 'if-modified-since', 'cache-control'];

    private const array PASS_RESPONSE = ['content-type', 'cache-control', 'etag', 'last-modified', 'content-disposition', 'location'];

    /** @var array<string, array<string, string>> session => cookie name => value */
    private array $jars = [];

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    private float $nextPollAt = 0.0;

    private float $activeUntil = 0.0;

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $work
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly Closure $work,
        private readonly EnvelopeVerifier $verifier,
        private readonly WebFetcher $fetcher = new NativeWebFetcher,
        private ?Closure $clock = null,
    ) {
        $this->clock ??= static fn (): float => microtime(true);
    }

    public function active(): bool
    {
        return ($this->clock)() < $this->activeUntil;
    }

    public function tick(): void
    {
        $now = ($this->clock)();
        if ($now < $this->nextPollAt && $this->pending === []) {
            return;
        }
        try {
            $response = ($this->work)(['responses' => $this->pending]);
        } catch (Throwable) {
            $this->nextPollAt = $now + 2.0;

            return;
        }
        $this->pending = [];
        foreach (is_array($response['closed'] ?? null) ? $response['closed'] : [] as $uuid) {
            if (is_string($uuid)) {
                unset($this->jars[strtolower($uuid)]);
            }
        }
        $requests = array_slice(is_array($response['requests'] ?? null) ? $response['requests'] : [], 0, 8);
        foreach ($requests as $request) {
            if (is_array($request) && is_string($request['uuid'] ?? null)) {
                $answer = $this->handle($request, $now);
                if ($answer !== null) {
                    $this->pending[] = $answer;
                }
            }
        }
        if ($requests !== []) {
            // Someone is using a device page: keep the round quick for a minute.
            $this->activeUntil = $now + 60.0;
        }
        $this->nextPollAt = $now + ($this->active() ? 0.25 : 10.0);
    }

    /** @param array<string, mixed> $request @return array<string, mixed>|null */
    private function handle(array $request, float $now): ?array
    {
        $uuid = (string) $request['uuid'];
        $details = $request['details'] ?? null;
        if (! is_array($details) || array_keys($details) !== ['session', 'scheme', 'address', 'port', 'trusted_pin', 'expires_at', 'signature']
            || ! $this->verifier->verifyDetached(self::canonical($details), $details['signature'])) {
            return null; // Not from Oblivion: nothing is fetched and nothing is said.
        }
        try {
            $expires = new DateTimeImmutable((string) $details['expires_at']);
        } catch (Throwable) {
            return null;
        }
        $address = $details['address'];
        $scheme = $details['scheme'];
        $pin = $details['trusted_pin'];
        $method = $request['method'] ?? null;
        $path = $request['path'] ?? null;
        if ($expires->getTimestamp() < (int) $now
            || ! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || ! in_array($scheme, ['http', 'https'], true) || ! is_int($details['port']) || $details['port'] < 1 || $details['port'] > 65535
            || ($scheme === 'https' && (! is_string($pin) || preg_match('/\Asha256\/\/[A-Za-z0-9+\/]{43}=\z/', $pin) !== 1))
            || ! in_array($method, ['GET', 'POST', 'HEAD'], true)
            || ! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//') || strlen($path) > 2048
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            return ['uuid' => $uuid, 'failure' => 'unreachable'];
        }
        $session = strtolower((string) $details['session']);
        $headers = [];
        foreach (is_array($request['headers'] ?? null) ? $request['headers'] : [] as $name => $value) {
            if (is_string($name) && is_string($value) && in_array(strtolower($name), self::PASS_REQUEST, true)
                && strlen($value) <= 1024 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
                $headers[strtolower($name)] = $value;
            }
        }
        if (($this->jars[$session] ?? []) !== []) {
            $headers['cookie'] = implode('; ', array_map(static fn (string $name, string $value): string => $name.'='.$value, array_keys($this->jars[$session]), $this->jars[$session]));
        }
        $body = is_string($request['body_base64'] ?? null) ? (base64_decode($request['body_base64'], true) ?: '') : '';
        if (strlen($body) > 262_144) {
            return ['uuid' => $uuid, 'failure' => 'too_large'];
        }
        $host = str_contains($address, ':') ? '['.$address.']' : $address;
        $default = ($scheme === 'http' && $details['port'] === 80) || ($scheme === 'https' && $details['port'] === 443);
        $url = $scheme.'://'.$host.($default ? '' : ':'.$details['port']).$path;

        $answer = $this->fetcher->fetch($url, $method, $headers, $body, $scheme === 'https' ? $pin : null, self::MAX_BYTES);
        if ($answer['truncated']) {
            return ['uuid' => $uuid, 'failure' => 'too_large'];
        }
        if ($answer['status'] === 0) {
            return ['uuid' => $uuid, 'failure' => $scheme === 'https' ? 'certificate' : 'unreachable'];
        }
        foreach ($answer['headers']['set-cookie'] ?? [] as $cookie) {
            $this->keepCookie($session, $cookie);
        }
        $pass = [];
        foreach (self::PASS_RESPONSE as $name) {
            $value = $answer['headers'][$name][0] ?? null;
            if (is_string($value) && strlen($value) <= 2048) {
                $pass[$name] = $value;
            }
        }

        return ['uuid' => $uuid, 'status' => $answer['status'], 'headers' => $pass, 'body_base64' => base64_encode($answer['body'])];
    }

    /** name=value from a Set-Cookie line (attributes ignored); an expired or empty one is removed. */
    private function keepCookie(string $session, string $line): void
    {
        $pair = explode(';', $line, 2)[0];
        $at = strpos($pair, '=');
        if ($at === false) {
            return;
        }
        $name = trim(substr($pair, 0, $at));
        $value = trim(substr($pair, $at + 1));
        if ($name === '' || strlen($name) > 128 || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f;,]/', $name) === 1) {
            return;
        }
        $expired = preg_match('/;\s*max-age\s*=\s*(0|-\d+)/i', $line) === 1 || $value === '';
        if ($expired) {
            unset($this->jars[$session][$name]);

            return;
        }
        if (count($this->jars[$session] ?? []) < 50) {
            $this->jars[$session][$name] = $value;
        }
    }

    /** The exact bytes the server signed: the session details without their signature. */
    public static function canonical(array $details): string
    {
        unset($details['signature']);

        return json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
