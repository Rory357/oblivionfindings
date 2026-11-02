<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Services\MonitoringRecoveryRequestService;
use App\Domain\Monitoring\Services\MonitoringReplayService;
use App\Domain\Monitoring\Services\MonitoringRuntimeHealthService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use Throwable;

/**
 * Setup › Hubs & networks › Server check, the monitoring part (S&D rebuild C6; plan: "/monitoring
 * runtime queues … Hub/queue health → Setup › Hubs › Server check (admins)"). Today's runtime health
 * from the old Monitoring page in plain words: the monitoring service's heartbeat, work waiting,
 * failed deliveries and the outage watchdog. "Not available" when there's no reading.
 *
 * Each figure keeps the runtime health service's own scoping (queue figures need every Site), and
 * failed deliveries are listed with Try again / Stop trying only for people who manage connected
 * services, at their own Sites, exactly as the old page did.
 */
final class ServerHealth
{
    public const int LETTER_LIMIT = 20;

    /** The runtime's parts, in plain words. */
    private const array PARTS = [
        'events' => 'Device events',
        'checks' => 'Check results',
        'discovery' => 'Network scans',
        'provider' => 'Connected services',
        'topology' => 'Network maps',
        'maintenance' => 'Upkeep',
        'orchestration' => 'Scheduling',
        'commands' => 'Device actions',
    ];

    private const array CONSUMERS = [
        'event-projector' => 'Device events',
        'observation-projector' => 'Check results',
        'configuration-projector' => 'Settings copies',
        'projection-projector' => 'Network maps',
        'collector-intake' => 'From a hub',
    ];

    public function __construct(
        private readonly MonitoringRuntimeHealthService $runtime,
        private readonly SecurityDevicesAccessService $access,
        private readonly MonitoringReplayService $replay,
        private readonly MonitoringRecoveryRequestService $recovery,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        try {
            $runtime = $this->runtime->present($viewer);
        } catch (Throwable $exception) {
            report($exception);
            $runtime = null;
        }

        return [
            'checked_at' => now()->utc()->toIso8601String(),
            'actor_id' => (int) $viewer->id,
            'service' => $this->service($runtime),
            'waiting' => $this->waiting($runtime),
            'watchdog' => $this->watchdog($runtime),
            'failed' => $this->failed($viewer, $runtime),
        ];
    }

    /** @param array<string, mixed>|null $runtime @return array{tone: string, label: string, summary: string} */
    private function service(?array $runtime): array
    {
        $workers = $runtime['workers'] ?? null;
        $queues = is_array($runtime['queues'] ?? null) ? $runtime['queues'] : [];
        if (! is_array($workers) || (int) ($workers['total'] ?? 0) === 0) {
            return self::row('neutral', 'Not available', 'No reading from the monitoring service.');
        }
        $total = (int) $workers['total'];
        $ages = collect($queues)->pluck('heartbeat_age_seconds')->filter(fn (mixed $age): bool => is_int($age));
        $heard = $ages->isEmpty() ? '' : ' · last heard '.self::ago((int) $ages->min());
        $down = collect($queues)
            ->filter(fn (array $queue): bool => in_array($queue['worker_state'] ?? null, ['stale', 'unavailable'], true))
            ->keys()
            ->map(fn (string $key): string => self::PARTS[$key] ?? $key)
            ->values();

        return match ($workers['state'] ?? null) {
            'available' => self::row('success', 'Running', "All {$total} parts are answering{$heard}"),
            'not_observed' => self::row('neutral', 'Not available', 'No heartbeat from the monitoring service yet. Check the scheduler and background workers are running.'),
            default => self::row(
                'critical',
                'Needs fixing',
                ($down->isEmpty() ? "Some of its {$total} parts aren’t answering" : $down->join(', ', ' and ').' '.($down->count() === 1 ? 'isn’t' : 'aren’t').' answering').$heard,
            ),
        };
    }

    /** @param array<string, mixed>|null $runtime @return array{tone: string, label: string, summary: string} */
    private function waiting(?array $runtime): array
    {
        $queues = collect(is_array($runtime['queues'] ?? null) ? $runtime['queues'] : []);
        if ($queues->isEmpty() || $queues->contains(fn (array $queue): bool => ($queue['state'] ?? null) === 'scope_restricted')) {
            return self::row('neutral', 'Not available', $queues->isEmpty() ? 'No reading from the monitoring service.' : 'Shown only to people who can see every Site.');
        }
        $pending = (int) $queues->sum(fn (array $queue): int => (int) ($queue['pending'] ?? 0));
        $oldest = $queues->pluck('oldest_age_seconds')->filter(fn (mixed $age): bool => is_int($age))->max();
        $busy = $queues
            ->filter(fn (array $queue): bool => (int) ($queue['pending'] ?? 0) > 0)
            ->map(fn (array $queue, string $key): string => (self::PARTS[$key] ?? $key).' '.number_format((int) $queue['pending']))
            ->values()
            ->join(' · ');
        $stuck = $queues->contains(fn (array $queue): bool => ($queue['state'] ?? null) === 'worker_unavailable' && (int) ($queue['pending'] ?? 0) > 0);

        return match (true) {
            $pending === 0 => self::row('success', 'Clear', 'Nothing waiting'),
            $stuck => self::row('critical', 'Stuck', number_format($pending).' waiting'.($oldest !== null ? ', the oldest from '.self::ago((int) $oldest) : '').' · '.$busy),
            default => self::row('info', 'Working through', number_format($pending).' waiting'.($oldest !== null ? ', the oldest from '.self::ago((int) $oldest) : '').' · '.$busy),
        };
    }

    /** @param array<string, mixed>|null $runtime @return array{tone: string, label: string, summary: string} */
    private function watchdog(?array $runtime): array
    {
        $watchdog = $runtime['external_heartbeat'] ?? null;
        if (! is_array($watchdog)) {
            return self::row('neutral', 'Not available', 'No reading from the monitoring service.');
        }
        $sent = is_int($watchdog['last_sent_age_seconds'] ?? null) ? self::ago((int) $watchdog['last_sent_age_seconds']) : null;

        return match ($watchdog['state'] ?? null) {
            'sent' => self::row('success', 'Working', 'The outside watchdog last heard from this server '.($sent ?? 'recently')),
            'disabled' => self::row('warning', 'Off', 'No outside watchdog is set up, so nobody would be told if this whole server stopped'),
            'not_observed' => self::row('neutral', 'Not available', 'Set up, but not checked yet'),
            'suppressed' => self::row('warning', 'Paused', 'Not telling the outside watchdog, because the monitoring service isn’t fully running'),
            'failed' => self::row('critical', 'Needs fixing', 'The outside watchdog couldn’t be reached'.($sent !== null ? ' · last heard from this server '.$sent : '')),
            'stale' => self::row('critical', 'Needs fixing', $sent !== null ? 'The outside watchdog hasn’t heard from this server since '.$sent : 'The outside watchdog hasn’t heard from this server'),
            default => self::row('neutral', 'Not available', 'No reading from the outside watchdog.'),
        };
    }

    /**
     * Failed deliveries (dead letters). The count follows the runtime health's scope; the rows and
     * their actions follow the old page: securityDevices.integrations.manage, the person's own Sites
     * and application-wide intake.
     *
     * @param  array<string, mixed>|null  $runtime
     * @return array<string, mixed>
     */
    private function failed(User $viewer, ?array $runtime): array
    {
        $queues = collect(is_array($runtime['queues'] ?? null) ? $runtime['queues'] : []);
        $counted = $queues->isNotEmpty() && $queues->every(fn (array $queue): bool => is_int($queue['dead_letters'] ?? null));
        $count = $counted ? (int) $queues->sum('dead_letters') : null;
        $canAct = $viewer->canDo('securityDevices.integrations.manage');
        $base = [
            'can_act' => $canAct,
            'rows' => [],
            'truncated' => false,
        ];

        if (! $canAct) {
            return $base + match (true) {
                $count === null => self::row('neutral', 'Not available', 'Shown only to people who can see every Site.'),
                $count === 0 => self::row('success', 'None', 'No deliveries are waiting for a decision'),
                default => self::row('warning', number_format($count).' waiting', 'People who manage connected services can try them again or stop them'),
            };
        }

        $siteIds = $this->access->accessibleSiteIds($viewer);
        $letters = MonitoringDeadLetter::query()
            ->whereNull('resolved_at')
            ->where(function ($query) use ($siteIds): void {
                $query->whereNull('site_id');
                if ($siteIds !== []) {
                    $query->orWhereIn('site_id', $siteIds);
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LETTER_LIMIT + 1)
            ->get();
        $sites = Site::query()->whereKey($letters->pluck('site_id')->filter()->unique()->all())->pluck('name', 'id');
        $rows = $letters->map(function (MonitoringDeadLetter $letter) use ($viewer, $sites): ?array {
            $inspection = $this->replay->inspect($viewer, $letter);
            if ($inspection === null) {
                return null;
            }

            return [
                'id' => (int) $letter->id,
                'review_token' => $this->recovery->reviewToken($letter),
                'site_id' => $letter->site_id === null ? null : (int) $letter->site_id,
                'where' => $letter->site_id === null ? 'All Sites' : (string) ($sites[$letter->site_id] ?? 'A Site'),
                'what' => self::CONSUMERS[(string) $letter->consumer] ?? self::sentence((string) $letter->consumer),
                'why' => self::why((string) $letter->reason_code),
                'reference' => substr((string) $letter->message_id, -8),
                'at' => $letter->created_at?->toIso8601String(),
                'tries' => (int) $letter->replay_count,
                'can_retry' => (bool) $inspection['can_replay'],
                'can_stop' => (bool) $inspection['can_discard'],
                'retrying' => (bool) $inspection['pending_replay'],
            ];
        })->filter()->values();
        $truncated = $letters->count() > self::LETTER_LIMIT || $rows->count() > self::LETTER_LIMIT;
        $shown = $rows->take(self::LETTER_LIMIT)->values();

        $row = match (true) {
            $shown->isEmpty() => self::row('success', 'None', 'No deliveries are waiting for a decision'),
            default => self::row(
                'warning',
                ($truncated ? 'Over ' : '').number_format($shown->count()).' waiting',
                'Deliveries the monitoring service couldn’t process. Trying again uses the original message; it never repeats a device action',
            ),
        };

        return [...$base, ...$row, 'rows' => $shown->all(), 'truncated' => $truncated];
    }

    /** @return array{tone: string, label: string, summary: string} */
    private static function row(string $tone, string $label, string $summary): array
    {
        return ['tone' => $tone, 'label' => $label, 'summary' => $summary];
    }

    private static function why(string $code): string
    {
        return match ($code) {
            'sequence_gap' => 'Arrived out of order',
            'handler_failed' => 'Couldn’t be processed',
            'unsupported_version' => 'Sent in a format this server can’t read yet',
            'invalid_signature' => 'Its signature didn’t check out',
            'payload_invalid', 'collector_payload_invalid' => 'Its contents weren’t valid',
            'site_scope_violation', 'collector_scope_violation' => 'Came from outside the sender’s Sites',
            default => self::sentence($code),
        };
    }

    private static function sentence(string $key): string
    {
        $words = trim(str_replace(['_', '-', '.'], ' ', mb_strtolower($key)));

        return $words === '' ? 'Not recorded' : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }

    /** "under a minute ago", "4 min ago", "2 h 5 min ago", "3 days ago" (writing guide W4 durations). */
    public static function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60 => 'under a minute ago',
            $seconds < 3600 => intdiv($seconds, 60).' min ago',
            $seconds < 86400 => intdiv($seconds, 3600).' h'.(intdiv($seconds % 3600, 60) > 0 ? ' '.intdiv($seconds % 3600, 60).' min' : '').' ago',
            default => intdiv($seconds, 86400).' '.(intdiv($seconds, 86400) === 1 ? 'day' : 'days').' ago',
        };
    }
}
