<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DataRetentionPolicy;
use App\Models\MedicationAlert;
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
 *   4. Hard-coded fallbacks (5 years audit, 5 years timeline)
 *
 * A stored "Forever" policy (Settings > Data, no years) keeps everything of that kind: nothing is pruned for it.
 * H&S records' history (safety reviews, incidents, restraints, safeguarding, hazards and the rest listed in
 * AuditLog::HEALTH_SAFETY_EVIDENCE_TYPES) is kept at least 10 years whatever the audit setting (readiness audit B6).
 *
 * The medication alert log is kept as long as the audit log (eMAR P11 Q11):
 * dealt-with alerts raised before the audit cutoff go with their recipients
 * and history. An alert still open is never pruned.
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
            fallback: 5,
        );
        $timelineYears = $this->resolveYears(
            cliValue: $this->option('timeline-years'),
            modelType: 'timeline_events',
            configKey: 'retention.timeline_event_years',
            fallback: 5,
        );

        // null = "Forever": a cutoff before any row, so nothing of that kind is pruned.
        $auditCutoff = $auditYears === null ? Carbon::create(1970) : Carbon::now()->subYears($auditYears);
        $timelineCutoff = $timelineYears === null ? Carbon::create(1970) : Carbon::now()->subYears($timelineYears);

        // P09: medication evidence has its own reviewed retention policy.
        // Never apply the generic two-year audit cleanup to these records.
        $auditQuery = AuditLog::query()->where('created_at', '<', $auditCutoff)
            ->withoutMedicationEvidence()
            ->withoutHealthSafetyEvidence();
        // Answer 20 (Stephan, 1 Nov 2026): safety review evidence is kept at least 10 years, or longer when the
        // configured audit retention is longer. It is pruned on its own, later cutoff.
        $healthSafetyYears = $auditYears === null ? null : max($auditYears, AuditLog::HEALTH_SAFETY_MIN_RETENTION_YEARS);
        $healthSafetyQuery = AuditLog::query()->where('created_at', '<', $healthSafetyYears === null ? $auditCutoff : Carbon::now()->subYears($healthSafetyYears))
            ->withoutMedicationEvidence()
            ->healthSafetyEvidence();
        $timelineQuery = TimelineEvent::query()
            ->where('occurred_at', '<', $timelineCutoff)
            ->where(function ($q) {
                $q->where('is_pinned', false)->orWhereNull('is_pinned');
            });

        $alertQuery = MedicationAlert::query()
            ->whereNull('open_key')
            ->where('status', MedicationAlert::STATUS_DEALT_WITH)
            ->whereNotNull('dealt_with_at')
            ->where('raised_at', '<', $auditCutoff)
            ->where('dealt_with_at', '<', $auditCutoff);

        $auditCount = $auditQuery->count();
        $healthSafetyCount = $healthSafetyQuery->count();
        $timelineCount = $timelineQuery->count();
        $alertCount = $alertQuery->count();

        $years = fn (?int $value): string => $value === null ? 'forever (kept)' : $value.' years';
        $this->info(sprintf('Audit logs older than %s: %d', $years($auditYears), $auditCount));
        $this->info(sprintf('H&S record audit logs older than %s: %d', $years($healthSafetyYears), $healthSafetyCount));
        $this->info(sprintf('Timeline events older than %s: %d (excludes pinned)', $years($timelineYears), $timelineCount));
        $this->info(sprintf('Medication alerts dealt with, raised over %s ago: %d', $years($auditYears), $alertCount));

        if ($dryRun) {
            $this->warn('Dry run — no rows deleted.');

            return self::SUCCESS;
        }

        $auditDeleted = $auditQuery->delete();
        $auditDeleted += $healthSafetyQuery->delete();
        $timelineDeleted = $timelineQuery->delete();
        // Recipients and events go with each alert (foreign keys cascade).
        $alertsDeleted = $alertQuery->delete();

        $this->info(sprintf(
            'Pruned %d audit log row(s) and %d timeline event row(s).',
            $auditDeleted,
            $timelineDeleted,
        ));
        $this->info(sprintf('Pruned %d medication alert(s) from the alert log.', $alertsDeleted));

        return self::SUCCESS;
    }

    private function resolveYears(
        mixed $cliValue,
        string $modelType,
        string $configKey,
        int $fallback,
    ): ?int {
        if ($cliValue !== null) {
            return (int) $cliValue;
        }

        $policy = DataRetentionPolicy::query()
            ->where('model_type', $modelType)
            ->where('active', true)
            ->first(['retention_period_years', 'retention_conditions']);

        if ($policy?->retention_period_years !== null) {
            return (int) $policy->retention_period_years;
        }
        if (in_array(data_get($policy?->retention_conditions, 'setting_value'), ['forever', 'never'], true)) {
            return null;
        }

        return (int) (config($configKey) ?? $fallback);
    }
}
