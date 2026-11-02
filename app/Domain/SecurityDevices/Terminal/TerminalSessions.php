<?php

namespace App\Domain\SecurityDevices\Terminal;

use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Services\HubJobTypes;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\NetworkConfig\Services\SshHostKeyTrust;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Terminal\Models\TerminalLine;
use App\Domain\SecurityDevices\Terminal\Models\TerminalSession;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * S&D Phase G (D5): a device's Terminal tab, through the Site's hub. Only IT admins
 * (securityDevices.terminal.use), only to a switch, router or other network device whose SSH key
 * is trusted, only with a saved login meant for a command line (device.remote_shell), and only
 * through a covering hub that runs the terminal. Every line typed is kept; the session closes
 * after 15 minutes without typing, and after an hour in any case.
 *
 * The hub asks for its work over its signed, mutually authenticated link. Each "open" is signed
 * with the server's configuration key, so the hub connects only where Oblivion asked.
 */
final class TerminalSessions
{
    public const string HUB_FEATURE = 'terminal_v1';

    public const string PROTOCOL = 'terminal.ssh';

    public const string CAPABILITY = 'device.remote_shell';

    public const int IDLE_SECONDS = 900;

    public const int MAX_SECONDS = 3600;

    public const int MAX_LINE = 1024;

    private const array KINDS = [DeviceKind::Switch, DeviceKind::Router, DeviceKind::WifiPoint, DeviceKind::NetworkDevice];

    private const array HUB_REASONS = ['ended', 'host_key_changed', 'host_key_untrusted', 'login_failed', 'unreachable', 'hub_restarted', 'too_long', 'idle'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CoveringHubResolver $hubs,
        private readonly EgressPolicy $egress,
        private readonly SshHostKeyTrust $hostKeys,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly CollectorCredentialLeaseSealer $sealer,
    ) {}

    public static function eligible(Device $device): bool
    {
        return in_array(DeviceKind::of($device), self::KINDS, true);
    }

    /**
     * Whether the Terminal tab can open for this viewer and device, and if not, why (in words).
     *
     * @return array{ok: bool, reason: string|null, hub: string|null}
     */
    public function readiness(User $viewer, Device $device): array
    {
        if (! self::eligible($device) || ! $viewer->canDo('securityDevices.terminal.use')) {
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
            ! $hub instanceof MonitoringCollector => 'No hub covers this Site. The terminal only opens through a hub.',
            ! in_array((string) $hub->status, ['online', 'degraded'], true) => "{$label} isn’t connected right now.",
            ! HubJobTypes::runs($hub, self::HUB_FEATURE) => "{$label} needs its software update before it can open a terminal.",
            $this->hostKeys->trusted($device) === null => 'Trust this device’s SSH key first: back up its settings once and choose Trust.',
            ! $this->credentials->available($device, $siteId, self::CAPABILITY) => 'Save a command-line login for this device first.',
            default => $this->targetOrReason($device, $siteId),
        };

        return ['ok' => $reason === null, 'reason' => $reason, 'hub' => $label];
    }

    public function open(User $actor, Device $device): TerminalSession
    {
        abort_unless($actor->canDo('securityDevices.devices.view') && $actor->canDo('securityDevices.terminal.use'), 403);
        $this->access->assertCanViewDevice($actor, $device);
        abort_unless(self::eligible($device), 404);
        $ready = $this->readiness($actor, $device);
        if (! $ready['ok']) {
            throw ValidationException::withMessages(['terminal' => (string) $ready['reason']]);
        }
        $siteId = $this->sites->resolve((int) $device->id);
        $hub = $this->hubs->forSite($siteId) ?? throw new RuntimeException('No hub covers this Site.');
        $target = $this->target($device, $siteId);

        return DB::transaction(function () use ($actor, $device, $siteId, $hub, $target): TerminalSession {
            Device::query()->whereKey($device->id)->lockForUpdate()->first();
            // One terminal per device at a time; the same person reopening gets theirs back.
            $live = TerminalSession::query()->live()->where('device_id', $device->id)->latest('id')->first();
            if ($live instanceof TerminalSession) {
                if ((int) $live->user_id === (int) $actor->id) {
                    return $live;
                }
                throw ValidationException::withMessages(['terminal' => ($live->user?->name ?? 'Someone').' already has a terminal open on this device.']);
            }
            $session = TerminalSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'device_id' => $device->id,
                'site_id' => $siteId,
                'collector_id' => $hub->id,
                'user_id' => $actor->id,
                'state' => 'requested',
                'address' => $target['address'],
                'port' => $target['port'],
                'last_input_at' => now(),
            ]);
            AuditLogger::logOrFail('security_devices.terminal.opened', $session, [
                'actor_id' => (int) $actor->id, 'device_id' => (int) $device->id, 'site_id' => $siteId, 'collector_id' => (int) $hub->id,
            ]);

            return $session;
        });
    }

    /** A line typed (or Ctrl+C). Kept as the terminal's history. */
    public function input(User $actor, TerminalSession $session, string $line, bool $interrupt = false): void
    {
        $this->assertOwner($actor, $session);
        if (! $session->isLive()) {
            throw ValidationException::withMessages(['line' => 'This terminal has closed. Open a new one.']);
        }
        if (! $interrupt && (mb_strlen($line) > self::MAX_LINE || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $line) === 1)) {
            throw ValidationException::withMessages(['line' => 'Type one line of plain text (up to 1,024 characters).']);
        }
        DB::transaction(function () use ($session, $line, $interrupt): void {
            $locked = TerminalSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $seq = (int) TerminalLine::query()->where('session_id', $locked->id)->where('direction', 'in')->max('seq') + 1;
            TerminalLine::query()->create([
                'session_id' => $locked->id, 'direction' => 'in', 'seq' => $seq,
                'text' => $interrupt ? "\x03" : $line, 'at' => now(),
            ]);
            $locked->forceFill(['last_input_at' => now()])->save();
        });
    }

    /**
     * What the device sent back after $after, and the session's state.
     *
     * @return array{state: string, reason: string|null, lines: list<array{seq: int, text: string}>}
     */
    public function output(User $actor, TerminalSession $session, int $after): array
    {
        $this->assertOwner($actor, $session);
        $this->closeIfOver($session->fresh() ?? $session);
        $session->refresh();

        return [
            'state' => $session->state,
            'reason' => $session->close_reason,
            'lines' => TerminalLine::query()->where('session_id', $session->id)->where('direction', 'out')->where('seq', '>', max(0, $after))
                ->orderBy('seq')->limit(200)->get(['seq', 'text'])
                ->map(fn (TerminalLine $line): array => ['seq' => (int) $line->seq, 'text' => (string) $line->text])->all(),
        ];
    }

    public function close(?User $actor, TerminalSession $session, string $reason = 'closed'): void
    {
        if ($actor !== null) {
            $this->assertOwner($actor, $session);
        }
        DB::transaction(function () use ($actor, $session, $reason): void {
            $locked = TerminalSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if (! $locked instanceof TerminalSession || ! $locked->isLive()) {
                return;
            }
            $locked->forceFill(['state' => 'closed', 'closed_at' => now(), 'close_reason' => mb_substr($reason, 0, 24)])->save();
            AuditLogger::logOrFail('security_devices.terminal.closed', $locked, [
                'actor_id' => $actor?->id, 'device_id' => (int) $locked->device_id, 'reason' => $reason,
                'lines_typed' => TerminalLine::query()->where('session_id', $locked->id)->where('direction', 'in')->count(),
            ]);
        });
    }

    /**
     * The hub's poll: what it saw (output, opened, ended) in; what to open, type and close out.
     *
     * @param  array<string, mixed>  $report
     * @return array{open: list<array<string, mixed>>, input: list<array{uuid: string, lines: list<array{seq: int, text: string}>}>, close: list<string>}
     */
    public function hubWork(MonitoringCollector $hub, array $report): array
    {
        $reported = [];
        foreach (array_slice(is_array($report['sessions'] ?? null) ? $report['sessions'] : [], 0, 20) as $row) {
            if (is_array($row) && is_string($row['uuid'] ?? null)) {
                $reported[strtolower($row['uuid'])] = $row;
            }
        }
        $sessions = TerminalSession::query()->where('collector_id', $hub->id)
            ->where(fn ($query) => $query->live()->orWhereIn('uuid', array_keys($reported)))
            ->orderBy('id')->limit(50)->get();

        $open = [];
        $input = [];
        $close = [];
        foreach ($sessions as $session) {
            $this->closeIfOver($session);
            $row = $reported[strtolower($session->uuid)] ?? null;
            if (is_array($row)) {
                $this->takeReport($session, $row);
            }
            $session->refresh();
            if ($session->state === 'requested') {
                $payload = $this->openPayload($hub, $session);
                if ($payload === null) {
                    $this->close(null, $session, 'unreachable');
                    $close[] = $session->uuid;

                    continue;
                }
                $session->forceFill(['state' => 'opening'])->save();
                $open[] = $payload;
            }
            if (in_array($session->state, ['opening', 'open'], true)) {
                $ack = is_array($row) && is_int($row['in_ack'] ?? null) ? $row['in_ack'] : 0;
                $lines = TerminalLine::query()->where('session_id', $session->id)->where('direction', 'in')->where('seq', '>', max(0, $ack))
                    ->orderBy('seq')->limit(50)->get(['seq', 'text'])
                    ->map(fn (TerminalLine $line): array => ['seq' => (int) $line->seq, 'text' => (string) $line->text])->all();
                if ($lines !== []) {
                    $input[] = ['uuid' => $session->uuid, 'lines' => $lines];
                }
            } elseif ($session->state === 'closed' && is_array($row) && ($row['state'] ?? null) !== 'closed') {
                $close[] = $session->uuid;
            }
        }

        return ['open' => $open, 'input' => $input, 'close' => array_values(array_unique($close))];
    }

    /** Close sessions past their idle or hour limit, and drop output an hour after a session closed. */
    public function sweep(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $closed = 0;
        TerminalSession::query()->live()->orderBy('id')->limit(500)->get()->each(function (TerminalSession $session) use (&$closed): void {
            if ($this->closeIfOver($session)) {
                $closed++;
            }
        });
        TerminalLine::query()->where('direction', 'out')
            ->whereIn('session_id', TerminalSession::query()->where('state', 'closed')->where('closed_at', '<', $now->subHour())->select('id'))
            ->delete();

        return $closed;
    }

    /** @param array<string, mixed> $row */
    private function takeReport(TerminalSession $session, array $row): void
    {
        $state = $row['state'] ?? null;
        if ($session->isLive() && is_array($row['out'] ?? null)) {
            foreach (array_slice($row['out'], 0, 50) as $chunk) {
                if (! is_array($chunk) || ! is_int($chunk['seq'] ?? null) || $chunk['seq'] < 1 || ! is_string($chunk['text'] ?? null)) {
                    continue;
                }
                TerminalLine::query()->insertOrIgnore([
                    'session_id' => $session->id, 'direction' => 'out', 'seq' => $chunk['seq'],
                    'text' => mb_substr($chunk['text'], 0, 8192), 'at' => now(),
                ]);
            }
        }
        if ($state === 'open' && $session->state === 'opening') {
            $session->forceFill(['state' => 'open', 'opened_at' => now(), 'login_name' => is_string($row['login'] ?? null) ? mb_substr($row['login'], 0, 64) : null])->save();
        } elseif ($state === 'closed' && $session->isLive()) {
            $reason = is_string($row['reason'] ?? null) && in_array($row['reason'], self::HUB_REASONS, true) ? $row['reason'] : 'ended';
            $this->close(null, $session, $reason);
        }
    }

    /** @return array<string, mixed>|null */
    private function openPayload(MonitoringCollector $hub, TerminalSession $session): ?array
    {
        try {
            $device = $session->device;
            $siteId = (int) $session->site_id;
            $trusted = $device instanceof Device ? $this->hostKeys->trusted($device) : null;
            if (! $device instanceof Device || $trusted === null || preg_match(SshHostKeyTrust::FINGERPRINT_PATTERN, $trusted) !== 1
                || ! in_array($siteId, $hub->authorisedSiteIds(), true) || ! HubJobTypes::runs($hub, self::HUB_FEATURE)) {
                return null;
            }
            $lease = $this->credentials->acquireFor($device, $siteId, self::CAPABILITY);
            $sealed = $this->sealer->seal($hub, (string) $device->id, self::PROTOCOL, $session->address, $lease, $siteId);
            $payload = [
                'uuid' => $session->uuid,
                'device_id' => (string) $device->id,
                'site_id' => $siteId,
                'address' => $session->address,
                'port' => (int) $session->port,
                'expected_host_key' => $trusted,
                'idle_seconds' => self::IDLE_SECONDS,
                'max_seconds' => self::MAX_SECONDS,
                'expires_at' => CarbonImmutable::now('UTC')->addMinutes(2)->format(DATE_ATOM),
                'credential_lease' => $sealed,
            ];
            $payload['signature'] = base64_encode(sodium_crypto_sign_detached(self::canonical($payload), $this->signingKey()));

            return $payload;
        } catch (Throwable) {
            return null;
        }
    }

    /** The exact bytes the server signs and the hub verifies: the payload without its signature. */
    public static function canonical(array $payload): string
    {
        unset($payload['signature']);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function closeIfOver(TerminalSession $session): bool
    {
        if (! $session->isLive()) {
            return false;
        }
        $now = CarbonImmutable::now('UTC');
        $since = $session->last_input_at ?? $session->created_at?->toImmutable() ?? $now;
        $reason = match (true) {
            $session->created_at !== null && $session->created_at->toImmutable()->addSeconds(self::MAX_SECONDS)->lte($now) => 'too_long',
            $since->addSeconds(self::IDLE_SECONDS)->lte($now) => 'idle',
            $session->state === 'requested' && $session->created_at !== null && $session->created_at->toImmutable()->addMinutes(2)->lte($now) => 'hub_offline',
            default => null,
        };
        if ($reason === null) {
            return false;
        }
        $this->close(null, $session, $reason);

        return true;
    }

    private function assertOwner(User $actor, TerminalSession $session): void
    {
        // Only the person who opened it can type into it or read it.
        abort_unless((int) $session->user_id === (int) $actor->id && $actor->canDo('securityDevices.terminal.use'), 404);
    }

    private function targetOrReason(Device $device, int $siteId): ?string
    {
        try {
            $this->target($device, $siteId);

            return null;
        } catch (Throwable) {
            return 'This device has no SSH address inside the Site’s networks.';
        }
    }

    /** @return array{address: string, port: int} */
    private function target(Device $device, int $siteId): array
    {
        $host = trim((string) (data_get($device->config ?? [], 'ssh.host') ?? $device->ip_address ?? ''));
        $port = (int) (data_get($device->config ?? [], 'ssh.port') ?? 22);
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('No usable SSH address.');
        }
        $target = $this->egress->authorise($siteId, (int) $device->id, ProbeTarget::tcp($host, $port));
        $address = $target->addresses[0] ?? null;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('The SSH address did not resolve to one approved address.');
        }

        return ['address' => $address, 'port' => $port];
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
