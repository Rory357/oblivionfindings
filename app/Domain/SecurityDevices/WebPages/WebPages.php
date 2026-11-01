<?php

namespace App\Domain\SecurityDevices\WebPages;

use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Services\HubJobTypes;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * S&D Phase G (pop-up 61): a device's own web page (a printer's or router's settings page), opened
 * through the Site's hub. Nothing is opened to the internet. IT admins only (webPage.open).
 *
 * The page is served at an unguessable link under Oblivion, inside a sandbox: the device's scripts
 * run in an origin of their own, so they can't reach Oblivion. Each page request waits here for the
 * hub to fetch it from the device, and the answer is kept only until it is served. https is used
 * only with the device's trusted certificate key.
 */
final class WebPages
{
    public const string HUB_FEATURE = 'web_page_v1';

    public const int IDLE_SECONDS = 900;

    public const int MAX_BODY = 3_145_728;

    public const int WAIT_SECONDS = 15;

    /** Request headers the browser may pass on to the device. */
    public const array PASS_REQUEST = ['accept', 'accept-language', 'content-type', 'if-none-match', 'if-modified-since', 'cache-control'];

    /** Response headers the device may pass back to the browser (cookies stay with the hub). */
    public const array PASS_RESPONSE = ['content-type', 'cache-control', 'etag', 'last-modified', 'content-disposition', 'location'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CoveringHubResolver $hubs,
        private readonly EgressPolicy $egress,
        private readonly CameraEndpointTrustService $trust,
    ) {}

    /** @return array{ok: bool, reason: string|null, hub: string|null} */
    public function readiness(User $viewer, Device $device): array
    {
        if (! $viewer->canDo('securityDevices.webPage.open')) {
            return ['ok' => false, 'reason' => null, 'hub' => null];
        }
        try {
            $siteId = $this->sites->resolve((int) $device->id);
        } catch (Throwable) {
            return ['ok' => false, 'reason' => 'Put this device at a Site first.', 'hub' => null];
        }
        $hub = $siteId > 0 ? $this->hubs->forSite($siteId) : null;
        $label = $hub instanceof MonitoringCollector ? CoveringHubResolver::label($hub) : null;
        $reason = match (true) {
            ! $hub instanceof MonitoringCollector => 'No hub covers this Site. Device pages only open through a hub.',
            ! in_array((string) $hub->status, ['online', 'degraded'], true) => "{$label} isn’t connected right now.",
            ! HubJobTypes::runs($hub, self::HUB_FEATURE) => "{$label} needs its software update before it can open device pages.",
            default => $this->targetOrReason($device, $siteId),
        };

        return ['ok' => $reason === null, 'reason' => $reason, 'hub' => $label];
    }

    /** @return array{url: string, session: string} */
    public function open(User $actor, Device $device): array
    {
        abort_unless($actor->canDo('securityDevices.devices.view') && $actor->canDo('securityDevices.webPage.open'), 403);
        $this->access->assertCanViewDevice($actor, $device);
        $ready = $this->readiness($actor, $device);
        if (! $ready['ok']) {
            throw ValidationException::withMessages(['web' => (string) $ready['reason']]);
        }
        $siteId = $this->sites->resolve((int) $device->id);
        $hub = $this->hubs->forSite($siteId) ?? throw new RuntimeException('No hub covers this Site.');
        $target = $this->target($device, $siteId);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = DB::transaction(function () use ($actor, $device, $siteId, $hub, $target, $token): Models\WebSession {
            $session = Models\WebSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'token_hash' => hash('sha256', $token),
                'device_id' => $device->id,
                'site_id' => $siteId,
                'collector_id' => $hub->id,
                'user_id' => $actor->id,
                'scheme' => $target['scheme'],
                'address' => $target['address'],
                'port' => $target['port'],
                'trusted_pin' => $target['pin'],
                'state' => 'open',
                'last_used_at' => now(),
            ]);
            AuditLogger::logOrFail('security_devices.web_page.opened', $session, [
                'actor_id' => (int) $actor->id, 'device_id' => (int) $device->id, 'site_id' => $siteId, 'collector_id' => (int) $hub->id,
            ]);

            return $session;
        });

        return ['url' => self::prefix($token).'/', 'session' => $session->uuid];
    }

    public static function prefix(string $token): string
    {
        return '/security-devices/web/'.$token;
    }

    /**
     * One page request from the sandboxed frame: queued for the hub, then its answer (or a plain
     * page saying why not).
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function serve(string $token, string $method, string $path, array $headers, string $body): array
    {
        $session = Models\WebSession::query()->where('token_hash', hash('sha256', $token))->first();
        $user = $session?->user;
        if (! $session instanceof Models\WebSession || $session->state !== 'open' || ! $user instanceof User
            || ! $user->canDo('securityDevices.webPage.open')) {
            return self::plain(404, 'This device page has closed. Open it again from the device in Oblivion.');
        }
        if ($session->last_used_at->addSeconds(self::IDLE_SECONDS)->lte(now())) {
            $this->close($session, 'idle');

            return self::plain(410, 'This device page closed after 15 minutes without use. Open it again from the device in Oblivion.');
        }
        if (! in_array($method, ['GET', 'POST', 'HEAD'], true) || strlen($path) > 2048 || ! str_starts_with($path, '/') || str_starts_with($path, '//')
            || strlen($body) > 262_144) {
            return self::plain(400, 'That request can’t be passed to the device.');
        }
        $pass = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (in_array($lower, self::PASS_REQUEST, true) && strlen($value) <= 1024 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
                $pass[$lower] = $value;
            }
        }
        $request = Models\WebRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'session_id' => $session->id,
            'method' => $method,
            'path' => $path,
            'headers' => $pass,
            'body_base64' => $body === '' ? null : base64_encode($body),
            'state' => 'queued',
        ]);
        $session->forceFill(['last_used_at' => now()])->save();

        $deadline = microtime(true) + max(0, (int) config('security_devices.web_page_wait_seconds', self::WAIT_SECONDS));
        while (in_array($request->state, ['queued', 'sent'], true) && microtime(true) < $deadline) {
            usleep(150_000);
            $request->refresh();
        }

        if ($request->state !== 'done') {
            $request->forceFill(['state' => 'failed', 'failure' => $request->failure ?? 'timeout', 'body_base64' => null])->save();

            return self::plain(504, match ($request->failure) {
                'certificate' => 'The device’s certificate isn’t the trusted one, so the hub didn’t connect.',
                'unreachable' => 'The hub couldn’t reach the device.',
                'too_large' => 'The device’s page was larger than 3 MB.',
                default => 'The device didn’t answer through the hub in time. Try again.',
            });
        }
        return $this->answer($session, $token, $request);
    }

    /**
     * A finished request's answer for the frame: safe headers, the sandbox, and the device's own
     * links pointing back through Oblivion. Served once: nothing about the answer stays.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function answer(Models\WebSession $session, string $token, Models\WebRequest $request): array
    {
        $responseBody = base64_decode((string) $request->response_body_base64, true) ?: '';
        $responseHeaders = (array) ($request->response_headers ?? []);
        $request->forceFill(['response_body_base64' => null, 'body_base64' => null])->save();

        return [
            'status' => (int) $request->response_status,
            'headers' => $this->safeResponseHeaders($session, $token, $responseHeaders),
            'body' => $this->rewrite($session, $token, (string) ($responseHeaders['content-type'] ?? ''), $responseBody),
        ];
    }

    public function close(Models\WebSession $session, string $reason = 'closed'): void
    {
        if ($session->state !== 'open') {
            return;
        }
        $session->forceFill(['state' => 'closed', 'closed_at' => now(), 'close_reason' => $reason])->save();
        Models\WebRequest::query()->where('session_id', $session->id)->delete();
        AuditLogger::logOrFail('security_devices.web_page.closed', $session, ['device_id' => (int) $session->device_id, 'reason' => $reason]);
    }

    /**
     * The hub's poll: answers in, page requests out (each with its session's signed details).
     *
     * @param  array<string, mixed>  $report
     * @return array{requests: list<array<string, mixed>>, closed: list<string>}
     */
    public function hubWork(MonitoringCollector $hub, array $report): array
    {
        foreach (array_slice(is_array($report['responses'] ?? null) ? $report['responses'] : [], 0, 20) as $row) {
            if (! is_array($row) || ! is_string($row['uuid'] ?? null)) {
                continue;
            }
            $request = Models\WebRequest::query()->where('uuid', strtolower($row['uuid']))->where('state', 'sent')
                ->whereHas('session', fn ($query) => $query->where('collector_id', $hub->id))->first();
            if (! $request instanceof Models\WebRequest) {
                continue;
            }
            if (is_string($row['failure'] ?? null)) {
                $request->forceFill(['state' => 'failed', 'failure' => in_array($row['failure'], ['certificate', 'unreachable', 'too_large', 'timeout'], true) ? $row['failure'] : 'unreachable', 'done_at' => now()])->save();

                continue;
            }
            $body = is_string($row['body_base64'] ?? null) ? $row['body_base64'] : '';
            $status = is_int($row['status'] ?? null) && $row['status'] >= 100 && $row['status'] <= 599 ? $row['status'] : 502;
            $headers = [];
            foreach (is_array($row['headers'] ?? null) ? $row['headers'] : [] as $name => $value) {
                if (is_string($name) && is_string($value) && in_array(strtolower($name), self::PASS_RESPONSE, true) && strlen($value) <= 2048) {
                    $headers[strtolower($name)] = $value;
                }
            }
            $request->forceFill([
                'state' => strlen($body) > (int) ceil(self::MAX_BODY * 4 / 3) + 4 ? 'failed' : 'done',
                'failure' => strlen($body) > (int) ceil(self::MAX_BODY * 4 / 3) + 4 ? 'too_large' : null,
                'response_status' => $status,
                'response_headers' => $headers,
                'response_body_base64' => strlen($body) > (int) ceil(self::MAX_BODY * 4 / 3) + 4 ? null : $body,
                'done_at' => now(),
            ])->save();
        }

        $out = [];
        Models\WebRequest::query()->where('state', 'queued')
            ->whereHas('session', fn ($query) => $query->where('collector_id', $hub->id)->where('state', 'open'))
            ->with('session')->orderBy('id')->limit(8)->get()
            ->each(function (Models\WebRequest $request) use (&$out): void {
                $session = $request->session;
                $details = [
                    'session' => $session->uuid,
                    'scheme' => $session->scheme,
                    'address' => $session->address,
                    'port' => (int) $session->port,
                    'trusted_pin' => $session->trusted_pin,
                    'expires_at' => CarbonImmutable::now('UTC')->addMinutes(2)->format(DATE_ATOM),
                ];
                $details['signature'] = base64_encode(sodium_crypto_sign_detached(self::canonical($details), $this->signingKey()));
                $out[] = [
                    'uuid' => $request->uuid,
                    'details' => $details,
                    'method' => $request->method,
                    'path' => $request->path,
                    'headers' => (array) $request->headers,
                    'body_base64' => $request->body_base64,
                ];
                $request->forceFill(['state' => 'sent'])->save();
            });
        $closed = Models\WebSession::query()->where('collector_id', $hub->id)->where('state', 'closed')
            ->where('closed_at', '>=', now()->subMinutes(10))->pluck('uuid')->all();

        return ['requests' => $out, 'closed' => array_values($closed)];
    }

    /** Close idle sessions and drop any answer that was never served. */
    public function sweep(): int
    {
        $closed = 0;
        Models\WebSession::query()->where('state', 'open')->where('last_used_at', '<', now()->subSeconds(self::IDLE_SECONDS))->get()
            ->each(function (Models\WebSession $session) use (&$closed): void {
                $this->close($session, 'idle');
                $closed++;
            });
        Models\WebRequest::query()->where('created_at', '<', now()->subMinutes(5))->delete();

        return $closed;
    }

    /** The exact bytes the server signs and the hub verifies. */
    public static function canonical(array $details): string
    {
        unset($details['signature']);

        return json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function safeResponseHeaders(Models\WebSession $session, string $token, array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if ($name === 'location') {
                $value = $this->rewriteUrl($session, $token, $value);
                if ($value === null) {
                    continue; // A redirect off the device stays out.
                }
            }
            $out[$name] = $value;
        }

        return [
            ...$out,
            // The device's page runs in an origin of its own: it can't read Oblivion or its cookies.
            'Content-Security-Policy' => 'sandbox allow-scripts allow-forms allow-popups-to-escape-sandbox; frame-ancestors \'self\'',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ];
    }

    /** The device's own links point back through Oblivion; links to anywhere else are left alone. */
    private function rewrite(Models\WebSession $session, string $token, string $contentType, string $body): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        if (! in_array($type, ['text/html', 'text/css', 'application/javascript', 'text/javascript', 'application/xhtml+xml'], true) || $body === '') {
            return $body;
        }
        $prefix = self::prefix($token);
        $host = preg_quote($session->address, '#');
        $default = ($session->scheme === 'http' && (int) $session->port === 80) || ($session->scheme === 'https' && (int) $session->port === 443);
        $port = $default ? '(?::'.(int) $session->port.')?' : ':'.(int) $session->port;
        // Absolute links to the device itself.
        $body = (string) preg_replace('#https?://'.$host.$port.'(?=[/"\'\s?#)]|$)#i', $prefix, $body);
        // Root-relative links in attributes and CSS (not protocol-relative ones).
        $body = (string) preg_replace('#((?:href|src|action|formaction)\s*=\s*["\'])/(?!/)#i', '$1'.$prefix.'/', $body);

        return (string) preg_replace('#(url\(\s*["\']?)/(?!/)#i', '$1'.$prefix.'/', $body);
    }

    private function rewriteUrl(Models\WebSession $session, string $token, string $url): ?string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return self::prefix($token).$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80));
        if (strtolower(trim((string) $parts['host'], '[]')) !== strtolower($session->address) || $port !== (int) $session->port) {
            return null;
        }

        return self::prefix($token).($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private static function plain(int $status, string $message): array
    {
        $html = '<!doctype html><meta charset="utf-8"><title>Device page</title><p style="font:14px system-ui;margin:24px">'.e($message).'</p>';

        return ['status' => $status, 'headers' => [
            'content-type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => 'sandbox; frame-ancestors \'self\'',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ], 'body' => $html];
    }

    private function targetOrReason(Device $device, int $siteId): ?string
    {
        try {
            $this->target($device, $siteId);

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage() === 'certificate'
                ? 'This device’s page is on https and its certificate isn’t trusted yet, so it won’t open.'
                : 'This device has no web page address inside the Site’s networks.';
        }
    }

    /** @return array{scheme: string, address: string, port: int, pin: string|null} */
    private function target(Device $device, int $siteId): array
    {
        $url = data_get($device->config ?? [], 'web.url');
        $parts = is_string($url) ? parse_url($url) : null;
        $host = trim((string) (is_array($parts) ? ($parts['host'] ?? '') : ($device->ip_address ?? '')), '[]');
        $scheme = is_array($parts) && ($parts['scheme'] ?? null) === 'https' ? 'https' : 'http';
        $port = (int) (is_array($parts) && isset($parts['port']) ? $parts['port'] : ($scheme === 'https' ? 443 : 80));
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('address');
        }
        $target = $this->egress->authorise($siteId, (int) $device->id, ProbeTarget::tcp($host, $port));
        $address = $target->addresses[0] ?? null;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('address');
        }
        $pin = null;
        if ($scheme === 'https') {
            $pin = $this->trust->record($siteId, $address, $port)?->trusted_pin;
            if (! is_string($pin) || preg_match(CameraEndpointTrustService::PIN_PATTERN, $pin) !== 1) {
                throw new RuntimeException('certificate');
            }
        }

        return ['scheme' => $scheme, 'address' => $address, 'port' => $port, 'pin' => $pin];
    }

    private function signingKey(): string
    {
        $key = base64_decode((string) config('monitoring.collector.signing_secret_key'), true);
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The collector signing key is not set.');
        }

        return $key;
    }
}
