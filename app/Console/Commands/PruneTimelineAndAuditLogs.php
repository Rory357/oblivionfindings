<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\TimelineEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Phase 3 retention policy: prune ageing audit_logs and timeline_events
 * beyond their configured retention window. Defaults are conservative
 * (2 years audit, 5 years timeline) and can be overridden per
 * organisation by setting `retention.audit_log_years` and
 * `retention.timeline_event_years` in config/retention.php (or via env
 * vars `RETENTION_AUDIT_LOG_YEARS` / `RETENTION_TIMELINE_EVENT_YEARS`).
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

        $auditYears = (int) ($this->option('audit-years')
            ?? config('retention.audit_log_years')
            ?? env('RETENTION_AUDIT_LOG_YEARS', 2));
        $timelineYears = (int) ($this->option('timeline-years')
            ?? config('retention.timeline_event_years')
            ?? env('RETENTION_TIMELINE_EVENT_YEARS', 5));

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
}
