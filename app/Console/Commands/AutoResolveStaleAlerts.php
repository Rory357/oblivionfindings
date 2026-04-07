<?php

namespace App\Console\Commands;

use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoResolveStaleAlerts extends Command
{
    protected $signature = 'control-room:auto-resolve-stale-alerts
        {--dry-run : Report eligible alerts without resolving them}';

    protected $description = 'Auto-resolve stale control room alerts that have exceeded their TTL';

    public const RESOLUTION_SOURCE = 'stale_auto_resolution';

    /**
     * Alert types safe for automatic stale resolution, with TTL in hours.
     *
     * These are operational shift/fleet alerts where the underlying window
     * has passed and the alert is no longer actionable. Manual/escalated
     * alerts are excluded.
     */
    protected const STALE_TTL_HOURS = [
        // Shift operational alerts — window has passed
        'Shift No Show' => 24,
        'Shift Late Start' => 24,
        'Shift Not Completed' => 24,
        'Shift Uncovered' => 48,

        // Orphan detection alerts — flagged for review, stale after a week
        'Completed Shift Missing Timesheet' => 168,
        'Attendance Session Missing Timesheet' => 168,
        'Timesheet Without Valid Shift' => 168,
    ];

    /**
     * Statuses that are safe to auto-resolve.
     * Escalated or acknowledged alerts under active investigation are excluded.
     */
    protected const ELIGIBLE_STATUSES = ['open'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $resolved = 0;
        $skipped = 0;

        try {
            foreach (self::STALE_TTL_HOURS as $alertType => $ttlHours) {
                $cutoff = $now->copy()->subHours($ttlHours);

                $query = ControlRoomAlert::query()
                    ->where('alert_type', $alertType)
                    ->whereIn('status', self::ELIGIBLE_STATUSES)
                    ->where('triggered_at', '<', $cutoff)
                    ->whereNull('escalated_at');

                $count = $query->count();

                if ($count === 0) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [DRY RUN] {$alertType}: {$count} stale (>{$ttlHours}h)");
                    $skipped += $count;
                    continue;
                }

                $query->chunkById(50, function ($alerts) use ($alertType, $ttlHours, &$resolved) {
                    foreach ($alerts as $alert) {
                        $this->resolveAsStale($alert, $ttlHours);
                        $resolved++;
                    }
                });
            }
        } catch (\Throwable $e) {
            Log::error('control-room:auto-resolve-stale-alerts failed', [
                'exception' => $e->getMessage(),
                'resolved_before_failure' => $resolved,
            ]);

            $this->error("Failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        Log::info('control-room:auto-resolve-stale-alerts completed', [
            'resolved' => $resolved,
            'skipped_dry_run' => $skipped,
        ]);

        $this->info("Resolved: {$resolved}" . ($dryRun ? " | Would resolve: {$skipped}" : ''));

        return self::SUCCESS;
    }

    protected function resolveAsStale(ControlRoomAlert $alert, int $ttlHours): void
    {
        $resolvedAt = now();
        $reason = "Auto-resolved: alert exceeded {$ttlHours}-hour staleness threshold without resolution or escalation.";

        $context = $alert->context ?? [];
        $resolution = [
            'resolved_at' => $resolvedAt->toISOString(),
            'reason' => $reason,
            'source' => self::RESOLUTION_SOURCE,
            'actor' => 'system',
            'ttl_hours' => $ttlHours,
        ];

        $history = $context['resolution_history'] ?? [];
        $history[] = $resolution;

        $alert->update([
            'status' => 'resolved',
            'resolved_at' => $resolvedAt,
            'resolved_by_user_id' => null,
            'notes' => $reason,
            'context' => array_merge($context, [
                'resolution' => $resolution,
                'resolution_history' => $history,
            ]),
        ]);

        $alert->sla?->recordResolution();

        AuditLogger::log('controlRoom.alert.stale_auto_resolved', $alert, [
            'resolution_source' => self::RESOLUTION_SOURCE,
            'ttl_hours' => $ttlHours,
            'alert_type' => $alert->alert_type,
            'triggered_at' => $alert->triggered_at?->toISOString(),
        ]);
    }
}
