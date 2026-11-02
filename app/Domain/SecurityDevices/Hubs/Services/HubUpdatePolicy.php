<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Hub updates (D3): automatic overnight, rolls back if it fails, and an admin can hold
 * updates for every hub.
 *
 * Each hub is told on its heartbeat which release it should run and when it may update. The
 * hub downloads it, checks its signature, swaps it in, restarts, and rolls back by itself if
 * no heartbeat gets through within ROLLBACK_AFTER_MINUTES. "Test first" hubs get a release
 * as soon as it's published. Everyone else waits for the test hub (Stephan, 3 Nov: "Wait for
 * the test hub"): a release goes to them only once a test-first hub has installed it and a day
 * has passed since it was published. A test-first hub whose update failed or rolled back holds
 * the others on their version until a test hub has it working. With no test-first hub, the
 * others simply wait the day.
 */
final class HubUpdatePolicy
{
    public const SETTING_KEY = 'security_devices.hubs.updates';

    public const ROLLBACK_AFTER_MINUTES = 5;

    public const RINGS = ['everyone', 'early'];

    private const EVERYONE_DELAY_HOURS = 24;

    /** A "Check now" that the hub never answered stops counting after a day. */
    private const CHECK_NOW_HOURS = 24;

    public function __construct(private ?EndpointReleaseCatalogue $catalogue = null) {}

    /** @return array{held: bool, held_at: string|null, held_by: string|null} */
    public function hold(): array
    {
        $value = AppSetting::query()->where('key', self::SETTING_KEY)->value('value');
        $value = is_array($value) ? $value : [];

        return [
            'held' => (bool) ($value['held'] ?? false),
            'held_at' => is_string($value['held_at'] ?? null) ? $value['held_at'] : null,
            'held_by' => is_string($value['held_by'] ?? null) ? $value['held_by'] : null,
        ];
    }

    public function setHold(bool $held, User $actor): void
    {
        if (! $actor->canDo('securityDevices.hubs.approve')) {
            abort(403);
        }
        AppSetting::query()->updateOrCreate(['key' => self::SETTING_KEY], ['value' => [
            'held' => $held,
            'held_at' => $held ? now()->toIso8601String() : null,
            'held_by' => $held ? (string) $actor->name : null,
        ]]);
        AuditLogger::logOrFail($held ? 'security_devices.hubs.updates_held' : 'security_devices.hubs.updates_resumed', $actor, [
            'actor_id' => (int) $actor->id,
        ]);
    }

    /**
     * What a hub is told on its heartbeat.
     *
     * @return array{
     *     held: bool,
     *     ring: string,
     *     window: array{start: string, end: string, timezone: string},
     *     rollback_after_minutes: int,
     *     check_now: bool,
     *     target: array{version: string, release_revision: int, artifacts: list<array<string, mixed>>}|null
     * }
     */
    public function instructionFor(MonitoringCollector $collector, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $hold = $this->hold();
        $ring = in_array($collector->update_ring, self::RINGS, true) ? (string) $collector->update_ring : 'everyone';

        return [
            'held' => $hold['held'],
            'ring' => $ring,
            'window' => [
                'start' => $this->clock($collector->update_window_start, '02:00'),
                'end' => $this->clock($collector->update_window_end, '05:00'),
                // The hubs' local night: app.timezone is UTC (storage), so it can't be used here.
                'timezone' => (string) config('app.worker_timezone', 'Pacific/Auckland'),
            ],
            'rollback_after_minutes' => self::ROLLBACK_AFTER_MINUTES,
            // "Check now": the hub may start this update outside its night window, once. Still
            // only when approved, not held, and for the release its ring allows.
            'check_now' => $this->checkNow($collector, $now),
            'target' => ($hold['held'] || $collector->approved_at === null) ? null : $this->target($collector, $ring, $now),
        ];
    }

    /** @return array{version: string, release_revision: int, artifacts: list<array<string, mixed>>, release_list?: string}|null */
    private function target(MonitoringCollector $collector, string $ring, CarbonImmutable $now): ?array
    {
        try {
            $signed = ($this->catalogue ?? EndpointReleaseCatalogue::configured())->signedCatalogue($now->toDateTimeImmutable());
        } catch (EndpointReleaseUnavailable) {
            return null;
        }
        $catalogue = $signed['catalogue'];
        $issuedAt = CarbonImmutable::parse((string) $catalogue['issued_at'])->utc();
        if ($ring === 'everyone' && $issuedAt->gt($now->subHours(self::EVERYONE_DELAY_HOURS))) {
            // Test-first hubs have had it for less than a day: everyone else waits.
            return null;
        }

        $platform = $this->platform($collector);
        $artifacts = array_values(array_filter(
            $catalogue['artifacts'],
            fn (array $artifact): bool => $artifact['platform'] === $platform && in_array('site', $artifact['roles'], true),
        ));
        if ($artifacts === []) {
            return null;
        }
        usort($artifacts, fn (array $a, array $b): int => $b['release_revision'] <=> $a['release_revision']);
        $latest = $artifacts[0];
        if ($ring === 'everyone' && $this->testHubs((string) $latest['version'])['state'] !== 'clear') {
            // Waiting for a test-first hub to have it working, or held because its update failed.
            return null;
        }

        $target = [
            'version' => (string) $latest['version'],
            'release_revision' => (int) $latest['release_revision'],
            'artifacts' => array_values(array_map(fn (array $artifact): array => [
                'id' => $artifact['id'],
                'architecture' => $artifact['architecture'],
                'filename' => $artifact['filename'],
                'size' => $artifact['size'],
                'sha256' => $artifact['sha256'],
            ], array_filter($artifacts, fn (array $artifact): bool => $artifact['release_revision'] === $latest['release_revision']))),
        ];
        if ($platform === 'linux') {
            // Linux packages aren't signed themselves; the signed release list pins their exact
            // bytes, so the hub checks the list's signature before it installs. Windows packages
            // carry their own signature, so Windows hubs don't need it.
            $target['release_list'] = base64_encode($signed['signed']);
        }

        return $target;
    }

    /**
     * The hub's report after an update attempt. A roll-back is kept on the hub and raised
     * as an alert so IT is told.
     *
     * @param  array<string, mixed>  $report
     */
    public function recordReport(MonitoringCollector $collector, array $report): MonitoringCollector
    {
        $outcome = $report['outcome'] ?? null;
        $from = $report['from_version'] ?? null;
        $to = $report['to_version'] ?? null;
        $detail = $report['detail'] ?? null;
        if (! in_array($outcome, ['installed', 'rolled_back', 'failed'], true)
            || ! is_string($to) || ! $this->isVersion($to)
            || ($from !== null && (! is_string($from) || ! $this->isVersion($from)))
            || ($detail !== null && (! is_string($detail) || mb_strlen($detail) > 500))) {
            throw new DomainException('Hub update report is invalid.');
        }
        $state = [
            'outcome' => $outcome,
            'from_version' => $from,
            'to_version' => $to,
            'detail' => $detail === null ? null : trim(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $detail) ?? ''),
            'reported_at' => now()->toIso8601String(),
        ];
        // Any report answers a "Check now".
        $collector->forceFill(['update_state' => $state, 'update_check_requested_at' => null])->save();
        AuditLogger::logOrFail('security_devices.hub.update_'.$outcome, $collector, [
            'site_id' => (int) $collector->site_id,
            'from_version' => $from,
            'to_version' => $to,
        ]);
        if ($outcome !== 'installed' && $collector->collector_device_id !== null) {
            \App\Domain\SecurityDevices\Models\DeviceEvent::query()->create([
                'device_id' => $collector->collector_device_id,
                'event_type' => 'hub_update_rolled_back',
                'severity' => 'warning',
                'source' => 'oblivion_monitoring',
                'occurred_at' => now(),
                'payload' => [
                    'collector_id' => (int) $collector->id,
                    'site_id' => (int) $collector->site_id,
                    'summary' => $outcome === 'rolled_back'
                        ? "Update to {$to} failed, rolled back".($from ? ", still on {$from}" : '')
                        : "Update to {$to} failed".($from ? ", still on {$from}" : ''),
                    'from_version' => $from,
                    'to_version' => $to,
                ],
            ]);
        }

        return $collector->fresh();
    }

    /**
     * Where the test-first hubs are with a version, which decides whether everyone else may take it.
     *
     * - `clear`: no test-first hub failed it and at least one has it working, or there is no
     *   test-first hub at all (then only the day's wait applies);
     * - `waiting`: test-first hubs exist but none has reported it working yet;
     * - `held`: a test-first hub's latest report on it is a failure or a roll-back. Everyone else
     *   keeps their version until a test hub reports it working.
     *
     * @return array{state: 'clear'|'waiting'|'held', test_hubs: int, hub: MonitoringCollector|null}
     */
    public function testHubs(string $version): array
    {
        $tests = MonitoringCollector::query()
            ->where('update_ring', 'early')
            ->whereNotNull('approved_at')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();
        if ($tests->isEmpty()) {
            return ['state' => 'clear', 'test_hubs' => 0, 'hub' => null];
        }
        $outcome = fn (MonitoringCollector $hub): ?string => is_array($hub->update_state)
            && ($hub->update_state['to_version'] ?? null) === $version
            ? (is_string($hub->update_state['outcome'] ?? null) ? $hub->update_state['outcome'] : null)
            : null;
        $failed = $tests->first(fn (MonitoringCollector $hub): bool => in_array($outcome($hub), ['failed', 'rolled_back'], true));
        if ($failed !== null) {
            return ['state' => 'held', 'test_hubs' => $tests->count(), 'hub' => $failed];
        }
        // Working: it reported installing it, or it already runs it (a fresh install on the new version).
        $working = $tests->first(fn (MonitoringCollector $hub): bool => $outcome($hub) === 'installed' || $hub->softwareVersion() === $version);

        return $working !== null
            ? ['state' => 'clear', 'test_hubs' => $tests->count(), 'hub' => $working]
            : ['state' => 'waiting', 'test_hubs' => $tests->count(), 'hub' => null];
    }

    /**
     * For Setup: the newest release, and what a hub that isn't test-first is waiting for, if anything.
     * Null when the release list can't be read or the hub already runs it.
     *
     * @return array{version: string, state: 'day'|'waiting'|'held'|'ready', ready_at: string, test_hub: MonitoringCollector|null}|null
     */
    public function waitingFor(MonitoringCollector $collector, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now('UTC');
        try {
            $catalogue = ($this->catalogue ?? EndpointReleaseCatalogue::configured())->signedCatalogue($now->toDateTimeImmutable())['catalogue'];
        } catch (EndpointReleaseUnavailable) {
            return null;
        }
        $platform = $this->platform($collector);
        $versions = array_values(array_filter($catalogue['artifacts'], fn (array $artifact): bool => $artifact['platform'] === $platform && in_array('site', $artifact['roles'], true)));
        if ($versions === []) {
            return null;
        }
        usort($versions, fn (array $a, array $b): int => $b['release_revision'] <=> $a['release_revision']);
        $version = (string) $versions[0]['version'];
        if ($collector->softwareVersion() === $version) {
            return null;
        }
        $readyAt = CarbonImmutable::parse((string) $catalogue['issued_at'])->utc()->addHours(self::EVERYONE_DELAY_HOURS);
        $tests = $this->testHubs($version);
        $state = match (true) {
            $tests['state'] === 'held' => 'held',
            $tests['state'] === 'waiting' => 'waiting',
            $readyAt->gt($now) => 'day',
            default => 'ready',
        };

        return ['version' => $version, 'state' => $state, 'ready_at' => $readyAt->toIso8601String(), 'test_hub' => $tests['hub']];
    }

    /** An admin asked this hub to check now (in the last day) and it hasn't reported since. */
    private function checkNow(MonitoringCollector $collector, CarbonImmutable $now): bool
    {
        $requested = $collector->update_check_requested_at;

        return $requested !== null && $requested->gt($now->subHours(self::CHECK_NOW_HOURS));
    }

    private function platform(MonitoringCollector $collector): string
    {
        $runtime = is_array($collector->runtime_status) ? $collector->runtime_status : [];
        $platform = $runtime['platform'] ?? null;

        return $platform === 'linux' ? 'linux' : 'windows';
    }

    private function clock(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $value) === 1 ? $value : $fallback;
    }

    private function isVersion(string $value): bool
    {
        return strlen($value) <= 32 && preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/', $value) === 1;
    }
}
