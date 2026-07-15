<?php

namespace App\Console\Commands;

use App\Models\ControlRoomAlert;
use App\Models\ControlRoom\AlertTask;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

    public function handle(ControlRoomAlertLifecycleService $lifecycle): int
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
                    ->whereNull('acknowledged_at')
                    ->whereNull('escalated_at')
                    ->whereDoesntHave('tasks', fn ($tasks) => $tasks
                        ->whereNotIn('status', AlertTask::TERMINAL_STATUSES));

                $count = $query->count();

                if ($count === 0) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [DRY RUN] {$alertType}: {$count} stale (>{$ttlHours}h)");
                    $skipped += $count;
                    continue;
                }

                $query->chunkById(50, function ($alerts) use ($lifecycle, $ttlHours, &$resolved) {
                    foreach ($alerts as $alert) {
                        if ($this->resolveAsStale($alert, $ttlHours, $lifecycle)) {
                            $resolved++;
                        }
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

    protected function resolveAsStale(
        ControlRoomAlert $alert,
        int $ttlHours,
        ControlRoomAlertLifecycleService $lifecycle,
    ): bool
    {
        $selectedAlertType = (string) $alert->alert_type;

        return DB::transaction(function () use ($alert, $lifecycle, $selectedAlertType, $ttlHours): bool {
            $locked = ControlRoomAlert::query()
                ->whereKey($alert->id)
                ->lockForUpdate()
                ->first();
            $cutoff = now()->subHours($ttlHours);

            if (! $locked
                || $locked->alert_type !== $selectedAlertType
                || ! in_array($locked->status, self::ELIGIBLE_STATUSES, true)
                || $locked->triggered_at === null
                || ! $locked->triggered_at->lt($cutoff)
                || $locked->acknowledged_at !== null
                || $locked->escalated_at !== null
                || $locked->tasks()
                    ->whereNotIn('status', AlertTask::TERMINAL_STATUSES)
                    ->limit(1)
                    ->lockForUpdate()
                    ->get(['id'])
                    ->isNotEmpty()) {
                return false;
            }

            $reason = "Auto-resolved: alert exceeded {$ttlHours}-hour staleness threshold without resolution or escalation.";
            $lifecycle->resolveAutomatically(
                $locked,
                $reason,
                self::RESOLUTION_SOURCE,
                self::RESOLUTION_SOURCE,
                [
                    'ttl_hours' => $ttlHours,
                    'alert_type' => $locked->alert_type,
                    'triggered_at' => $locked->triggered_at->toISOString(),
                ],
            );

            return true;
        }, 3);
    }
}
