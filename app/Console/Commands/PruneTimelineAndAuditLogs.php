<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DataRetentionPolicy;
use App\Models\TimelineEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Phase 3 retention policy: prune ageing audit_logs and timeline_events
 * beyond their configured retention window.
 *
 * Resolution order (first match wins):
 *   1. --audit-years / --timeline-years CLI flags
 *   2. Active `data_retention_policies` row for the matching model_type
 *      (`audit_logs` / `timeline_events`) — this is what the Settings >
 *      Data & Privacy UI (DataSettingsController) writes
 *   3. `config/retention.php` defaults (themselves env-overridable via
 *      RETENTION_AUDIT_LOG_YEARS / RETENTION_TIMELINE_EVENT_YEARS)
 *   4. Hard-coded fallbacks (2 years audit, 5 years timeline)
 */
class PruneTimelineAndAuditLogs extends Command
{
    protected $signature = 'oblivion:prune-retention
        {--dry-run : Show what would be pruned without deleting}
        {--audit-years= : Override audit log retention in years}
        {--timeline-years= : Override timeline event retention in years}';

    protected $description = 'Prune audit_logs and timeline_events older than the retention window.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $auditYears = $this->resolveYears(
            cliValue: $this->option('audit-years'),
            modelType: 'audit_logs',
            configKey: 'retention.audit_log_years',
            fallback: 2,
        );
        $timelineYears = $this->resolveYears(
            cliValue: $this->option('timeline-years'),
            modelType: 'timeline_events',
            configKey: 'retention.timeline_event_years',
            fallback: 5,
        );

        $auditCutoff = Carbon::now()->subYears($auditYears);
        $timelineCutoff = Carbon::now()->subYears($timelineYears);

        $auditQuery = AuditLog::query()->where('created_at', '<', $auditCutoff);
        $timelineQuery = TimelineEvent::query()
            ->where('occurred_at', '<', $timelineCutoff)
            ->where(function ($q) {
                $q->where('is_pinned', false)->orWhereNull('is_pinned');
            });

        $auditCount = $auditQuery->count();
        $timelineCount = $timelineQuery->count();

        $this->info(sprintf('Audit logs older than %d years: %d', $auditYears, $auditCount));
        $this->info(sprintf('Timeline events older than %d years: %d (excludes pinned)', $timelineYears, $timelineCount));

        if ($dryRun) {
            $this->warn('Dry run — no rows deleted.');

            return self::SUCCESS;
        }

        $auditDeleted = $auditQuery->delete();
        $timelineDeleted = $timelineQuery->delete();

        $this->info(sprintf(
            'Pruned %d audit log row(s) and %d timeline event row(s).',
            $auditDeleted,
            $timelineDeleted,
        ));

        return self::SUCCESS;
    }

    private function resolveYears(
        mixed $cliValue,
        string $modelType,
        string $configKey,
        int $fallback,
    ): int {
        if ($cliValue !== null) {
            return (int) $cliValue;
        }

        $policyYears = DataRetentionPolicy::query()
            ->where('model_type', $modelType)
            ->where('active', true)
            ->whereNotNull('retention_period_years')
            ->value('retention_period_years');

        if ($policyYears !== null) {
            return (int) $policyYears;
        }

        return (int) (config($configKey) ?? $fallback);
    }
}
