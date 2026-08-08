<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Models\AppSetting;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkRawFrame;
use App\Services\Queclink\Listener\QueclinkListenerRuntimeProbe;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnostic / status check, also used as the data source for the
 * "Listener status" panel on the integration hub page.
 *
 *   php artisan queclink:status
 *   php artisan queclink:status --json
 */
class QueclinkStatus extends Command
{
    protected $signature = 'queclink:status
        {--json : Output JSON instead of human-readable text}
        {--require-live : Fail unless the supervised listener and a canonical paired tracker have current evidence}
        {--evidence-json : Emit only value-free live acceptance evidence and require it to pass}
        {--max-frame-age=900 : Maximum acceptable age in seconds for a parsed inbound tracker frame}';

    protected $description = 'Report Queclink listener service status, port, and recent activity';

    public function handle(
        QueclinkListenerRuntimeProbe $runtime,
        CanonicalDeviceSiteResolver $siteResolver,
    ): int {
        $maxFrameAge = filter_var($this->option('max-frame-age'), FILTER_VALIDATE_INT);
        if ($maxFrameAge === false || $maxFrameAge < 60 || $maxFrameAge > 86400) {
            $this->error('Max frame age must be an integer between 60 and 86400 seconds.');

            return self::INVALID;
        }

        $port = (int) (AppSetting::query()->where('key', 'queclink.listener.port')->value('value')
            ?? config('services.queclink.port', 8090));

        $hostname = AppSetting::query()->where('key', 'queclink.public_hostname')->value('value')
            ?? config('services.queclink.public_hostname')
            ?? gethostname();

        $serviceState = $runtime->serviceState();

        $devices = [
            'total' => QueclinkDevice::count(),
            'paired' => QueclinkDevice::paired()->count(),
            'pending' => QueclinkDevice::pending()->count(),
            'rejected' => QueclinkDevice::rejected()->count(),
            'connected_now' => QueclinkDevice::connected()->count(),
        ];

        $recentFrames = QueclinkRawFrame::query()
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $lastFrameAt = QueclinkRawFrame::query()
            ->latest('created_at')
            ->first()
            ?->created_at;

        $acceptance = $this->liveAcceptance(
            $serviceState,
            (int) $maxFrameAge,
            $siteResolver,
        );

        $status = [
            'port' => $port,
            'public_hostname' => $hostname,
            'service_state' => $serviceState,
            'devices' => $devices,
            'frames_last_hour' => $recentFrames,
            'last_frame_at' => $lastFrameAt,
            'acceptance' => $acceptance,
        ];

        if ($this->option('evidence-json')) {
            $this->line(json_encode([
                'observed_at' => now()->utc()->toIso8601String(),
                'acceptance' => $acceptance,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $acceptance['state'] === 'verified' ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ! $this->option('require-live') || $acceptance['state'] === 'verified'
                ? self::SUCCESS
                : self::FAILURE;
        }

        $this->info('Queclink listener status');
        $this->line('  Service:        '.$serviceState);
        $this->line('  Port:           '.$port);
        $this->line('  Public host:    '.$hostname);
        $this->line('  Devices total:  '.$devices['total']);
        $this->line('  ├─ paired:      '.$devices['paired']);
        $this->line('  ├─ pending:     '.$devices['pending']);
        $this->line('  ├─ rejected:    '.$devices['rejected']);
        $this->line('  └─ connected:   '.$devices['connected_now']);
        $this->line('  Frames (1h):    '.$recentFrames);
        $this->line('  Last frame:     '.($lastFrameAt?->diffForHumans() ?? 'never'));
        $this->line('  Live evidence:  '.$acceptance['state']);

        if ($acceptance['reason_codes'] !== []) {
            $this->line('  Evidence gaps:  '.implode(', ', $acceptance['reason_codes']));
        }

        return ! $this->option('require-live') || $acceptance['state'] === 'verified'
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function liveAcceptance(
        string $serviceState,
        int $maxFrameAge,
        CanonicalDeviceSiteResolver $siteResolver,
    ): array {
        $canonicalTrackers = 0;
        $observedTrackers = 0;
        $siteIds = [];
        $canonicalRoster = [];
        $frameExecutions = [];
        $oldestFrameAt = null;
        $freshestFrameAt = null;
        $observedAt = now();
        $cutoff = $observedAt->copy()->subSeconds($maxFrameAge);

        $trackers = QueclinkDevice::query()
            ->paired()
            ->whereNotNull('device_id')
            ->with('device:id,status')
            ->get();

        foreach ($trackers as $tracker) {
            if ($tracker->device === null) {
                continue;
            }

            try {
                $siteId = $siteResolver->resolve((int) $tracker->device_id);
            } catch (Throwable) {
                continue;
            }

            $canonicalTrackers++;
            $canonicalRoster[] = implode(':', [
                $tracker->id,
                $tracker->device_id,
                $siteId,
            ]);
            $latestFrame = QueclinkRawFrame::query()
                ->where('queclink_device_id', $tracker->id)
                ->inbound()
                ->where('parse_ok', true)
                ->where('created_at', '>=', $cutoff)
                ->where('created_at', '<=', $observedAt)
                ->latest('created_at')
                ->latest('id')
                ->first(['id', 'created_at']);
            if ($latestFrame === null) {
                continue;
            }

            $observedTrackers++;
            $siteIds[$siteId] = true;
            $frameExecutions[] = implode(':', [
                $tracker->id,
                $latestFrame->id,
                $latestFrame->created_at->utc()->format('U.u'),
            ]);
            if ($oldestFrameAt === null || $latestFrame->created_at->lt($oldestFrameAt)) {
                $oldestFrameAt = $latestFrame->created_at;
            }
            if ($freshestFrameAt === null || $latestFrame->created_at->gt($freshestFrameAt)) {
                $freshestFrameAt = $latestFrame->created_at;
            }
        }

        $canonicalRosterFingerprint = $this->evidenceFingerprint('canonical-roster', $canonicalRoster);
        $frameExecutionFingerprint = $this->evidenceFingerprint('frame-execution', $frameExecutions);

        $reasonCodes = [];
        if ($serviceState !== 'active') {
            $reasonCodes[] = 'listener_not_active';
        }
        if ($canonicalRosterFingerprint === null || $frameExecutionFingerprint === null) {
            $reasonCodes[] = 'evidence_key_missing';
        }
        if ($canonicalTrackers === 0) {
            $reasonCodes[] = 'canonical_paired_tracker_missing';
        } elseif ($observedTrackers === 0) {
            $reasonCodes[] = 'current_canonical_frame_missing';
        } elseif ($observedTrackers !== $canonicalTrackers) {
            $reasonCodes[] = 'canonical_tracker_evidence_incomplete';
        }

        return [
            'state' => $reasonCodes === [] ? 'verified' : 'unverified',
            'listener_state' => $serviceState,
            'max_frame_age_seconds' => $maxFrameAge,
            'canonical_paired_trackers' => $canonicalTrackers,
            'canonical_sites_observed' => count($siteIds),
            'fresh_trackers_observed' => $observedTrackers,
            'canonical_roster_fingerprint' => $canonicalRosterFingerprint,
            'frame_execution_fingerprint' => $frameExecutionFingerprint,
            'frame_window' => [
                'oldest_observed_at' => $oldestFrameAt?->utc()->toIso8601String(),
                'newest_observed_at' => $freshestFrameAt?->utc()->toIso8601String(),
            ],
            'freshest_frame_age_seconds' => $freshestFrameAt === null
                ? null
                : (int) $freshestFrameAt->diffInSeconds($observedAt),
            'reason_codes' => $reasonCodes,
        ];
    }

    /** @param list<string> $members */
    private function evidenceFingerprint(string $purpose, array $members): ?string
    {
        $key = (string) config('app.key', '');
        if ($key === '') {
            return null;
        }

        sort($members, SORT_STRING);

        return hash_hmac('sha256', $purpose."\n".implode("\n", $members), $key);
    }
}
