<?php

namespace App\Console\Commands;

use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\HealthSafety\HsSignalService;
use App\Services\Medication\DoseSlots\OverdueDoses;
use App\Services\Medication\MedicationSignalService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One-off clean-up (30 Oct 2026): the daily overdue checks opened a new
 * Control Room alert for the same item every day. The checks now refresh the
 * open alert instead (standing-condition key). This closes the extra open
 * alerts so each item keeps exactly one — through the normal automated
 * resolve, with a reason naming the alert that was kept.
 *
 * The kept alert is the one already carrying the condition key, else one a
 * person has worked on, else the newest. It gets the condition key so the
 * next daily check refreshes it. Alerts with open tasks are never closed.
 */
class CloseDuplicateControlRoomAlerts extends Command
{
    protected $signature = 'control-room:close-duplicate-alerts
        {--dry-run : Count what would change; change nothing}
        {--manifest= : Write every in-scope open alert (before the change) to this JSON-lines file}
        {--type=* : Only these alert types}';

    protected $description = 'Close duplicate open Control Room alerts raised daily for the same overdue item, keeping one per item';

    public const RESOLUTION_CODE = 'duplicate';

    public const RESOLUTION_SOURCE = 'duplicate_alert_cleanup';

    public const SUPERSEDED_CODE = 'superseded';

    /** The --type name for medication overdue alerts from before per-dose alerts (C6f). */
    public const LEGACY_MEDICATION_TYPE = 'Medication Overdue (no dose named)';

    public function handle(ControlRoomAlertLifecycleService $lifecycle): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $manifestPath = $this->option('manifest');
        if (! $dryRun && ! is_string($manifestPath)) {
            $this->error('A --manifest path is required when changing data.');

            return self::FAILURE;
        }

        $only = array_filter((array) $this->option('type'));
        $manifest = is_string($manifestPath) ? fopen($manifestPath, 'xb') : null;
        if ($manifestPath !== null && $manifest === false) {
            $this->error("Could not create {$manifestPath} (it must not exist yet).");

            return self::FAILURE;
        }

        $totals = ['items' => 0, 'kept' => 0, 'closed' => 0, 'skipped' => 0];
        try {
            foreach ($this->groups() as $alertType => $group) {
                if ($only !== [] && ! in_array($alertType, $only, true)) {
                    continue;
                }

                $counts = $this->cleanType($alertType, $group, $lifecycle, $dryRun, $manifest);
                foreach ($counts as $key => $value) {
                    $totals[$key] += $value;
                }
                $this->line(sprintf(
                    '%s: %d items, %d kept, %d %s, %d skipped (open tasks)',
                    $alertType,
                    $counts['items'],
                    $counts['kept'],
                    $counts['closed'],
                    $dryRun ? 'would close' : 'closed',
                    $counts['skipped'],
                ));
            }

            if ($only === [] || in_array(self::LEGACY_MEDICATION_TYPE, $only, true)) {
                $counts = $this->cleanLegacyMedicationOverdue($lifecycle, $dryRun, $manifest);
                foreach ($counts as $key => $value) {
                    $totals[$key] += $value;
                }
                $this->line(sprintf(
                    '%s: %d people, %d kept open, %d %s, %d skipped (open tasks)',
                    self::LEGACY_MEDICATION_TYPE,
                    $counts['items'],
                    $counts['kept'],
                    $counts['closed'],
                    $dryRun ? 'would close' : 'closed',
                    $counts['skipped'],
                ));
            }
        } finally {
            if (is_resource($manifest)) {
                fclose($manifest);
            }
        }

        $this->info(sprintf(
            'Total: %d items, %d kept, %d %s, %d skipped.',
            $totals['items'],
            $totals['kept'],
            $totals['closed'],
            $dryRun ? 'would close' : 'closed',
            $totals['skipped'],
        ));

        return self::SUCCESS;
    }

    /**
     * Per alert type: its source, which open alerts are in scope, the item
     * each one is about, and the condition key its producer now sends (null
     * when the producer no longer runs).
     *
     * @return array<string, array{source: string, scope: callable, item: callable, key: callable}>
     */
    private function groups(): array
    {
        $normalized = fn (string $key) => fn (ControlRoomAlert $alert) => data_get($alert->context, "normalized_data.{$key}");
        $hs = fn (string $type, string $field, string $prefix) => [
            'source' => 'health_safety',
            'scope' => fn ($query) => $query,
            'item' => $normalized($field),
            'key' => fn (ControlRoomAlert $alert, $item) => SignalProcessingService::conditionKey($type, 'entity', $prefix.$item),
        ];
        $shift = [
            'source' => 'shift_operations',
            'scope' => fn ($query) => $query,
            'item' => fn (ControlRoomAlert $alert) => ($shiftId = data_get($alert->context, 'normalized_data.shift_id')) === null
                ? null
                : $shiftId.'|'.(data_get($alert->context, 'normalized_data.staff_id') ?? data_get($alert->context, 'normalized_data.user_id') ?? ''),
            'key' => fn () => null,
        ];

        return [
            'Fleet Maintenance Overdue' => [
                'source' => 'queclink_fleet',
                'scope' => fn ($query) => $query->whereNotNull('asset_id'),
                'item' => fn (ControlRoomAlert $alert) => $alert->asset_id,
                'key' => fn (ControlRoomAlert $alert, $item) => SignalProcessingService::conditionKey('fleet_maintenance_overdue', 'asset', (int) $item),
            ],
            'Hs Investigation Overdue' => $hs(HsSignalService::TYPE_INVESTIGATION_OVERDUE, 'hs_investigation_id', 'investigation_'),
            'Hs Corrective Action Overdue' => $hs(HsSignalService::TYPE_CORRECTIVE_ACTION_OVERDUE, 'hs_corrective_action_id', 'corrective_action_'),
            'Hs Risk Review Overdue' => $hs(HsSignalService::TYPE_RISK_REVIEW_OVERDUE, 'hs_risk_assessment_id', 'risk_assessment_'),
            // Only the daily per-person check (it lists discrepancy_ids); an
            // alert raised for one recorded discrepancy is its own item.
            'Medication Controlled Discrepancy' => [
                'source' => 'medication',
                'scope' => fn ($query) => $query
                    ->whereNotNull('client_id')
                    ->whereRaw("JSON_EXTRACT(context, '$.normalized_data.discrepancy_ids') IS NOT NULL")
                    ->whereRaw("JSON_EXTRACT(context, '$.normalized_data.discrepancy_id') IS NULL"),
                'item' => fn (ControlRoomAlert $alert) => $alert->client_id,
                'key' => fn (ControlRoomAlert $alert, $item) => SignalProcessingService::conditionKey(MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY, 'client', (int) $item),
            ],
            'Shift No Show' => $shift,
            'Shift Not Completed' => $shift,
            'Shift Eligibility Changed' => $shift,
            'Shift Eligibility Escalation' => $shift,
        ];
    }

    /**
     * @param  array{source: string, scope: callable, item: callable, key: callable}  $group
     * @param  resource|null  $manifest
     * @return array{items: int, kept: int, closed: int, skipped: int}
     */
    private function cleanType(string $alertType, array $group, ControlRoomAlertLifecycleService $lifecycle, bool $dryRun, $manifest): array
    {
        $counts = ['items' => 0, 'kept' => 0, 'closed' => 0, 'skipped' => 0];

        $alerts = ($group['scope'])(ControlRoomAlert::query()
            ->unresolved()
            ->where('alert_type', $alertType)
            ->where('source', $group['source']))
            ->withCount(['tasks as open_task_count' => fn ($tasks) => $tasks->whereNotIn('status', AlertTask::TERMINAL_STATUSES)])
            ->orderBy('id')
            ->get();

        /** @var Collection<string, Collection<int, ControlRoomAlert>> $items */
        $items = $alerts
            ->filter(fn (ControlRoomAlert $alert) => ($group['item'])($alert) !== null)
            ->groupBy(fn (ControlRoomAlert $alert) => implode('|', [
                $alert->site_id ?? '-',
                $alert->client_id ?? '-',
                $alert->asset_id ?? '-',
                ($group['item'])($alert),
            ]));

        foreach ($items as $itemAlerts) {
            $counts['items']++;
            $first = $itemAlerts->first();
            $item = ($group['item'])($first);
            $conditionKey = ($group['key'])($first, $item);
            $keeper = $this->keeper($itemAlerts, $conditionKey);
            $counts['kept']++;

            foreach ($itemAlerts as $alert) {
                $keep = $alert->is($keeper);
                $blocked = ! $keep && $alert->open_task_count > 0;
                $this->writeManifest($manifest, $alert, $item, $keep ? 'keep' : ($blocked ? 'skip_open_tasks' : 'close'), $keeper->id, $conditionKey);

                if ($keep) {
                    continue;
                }
                if ($blocked) {
                    $counts['skipped']++;

                    continue;
                }
                if ($dryRun) {
                    $counts['closed']++;

                    continue;
                }

                try {
                    $lifecycle->resolveAutomatically(
                        $alert,
                        "Duplicate of alert #{$keeper->id}: the same item was raised again by a daily check. Closed by the duplicate alert clean-up; alert #{$keeper->id} stays open for it.",
                        self::RESOLUTION_CODE,
                        self::RESOLUTION_SOURCE,
                        ['kept_alert_id' => $keeper->id, 'alert_type' => $alertType],
                    );
                    $counts['closed']++;
                } catch (InvalidArgumentException) {
                    $counts['skipped']++;
                }
            }

            if (! $dryRun && $conditionKey !== null
                && data_get($keeper->context, 'normalized_data.'.SignalProcessingService::CONDITION_KEY) !== $conditionKey) {
                DB::transaction(function () use ($keeper, $conditionKey): void {
                    $locked = ControlRoomAlert::query()->whereKey($keeper->id)->lockForUpdate()->first();
                    if ($locked === null || ! $locked->isActionable()) {
                        return;
                    }
                    $context = $locked->context ?? [];
                    data_set($context, 'normalized_data.'.SignalProcessingService::CONDITION_KEY, $conditionKey);
                    $locked->forceFill(['context' => $context])->save();
                });
            }
        }

        return $counts;
    }

    /**
     * Medication overdue alerts from before per-dose alerts (C6f) name no
     * dose. One closes only when the person has a current per-dose alert (it
     * now stands for their overdue doses) or no dose overdue at all. If the
     * person has an overdue dose but no per-dose alert, every one stays open:
     * it may be the only alert for that dose.
     *
     * @param  resource|null  $manifest
     * @return array{items: int, kept: int, closed: int, skipped: int}
     */
    private function cleanLegacyMedicationOverdue(ControlRoomAlertLifecycleService $lifecycle, bool $dryRun, $manifest): array
    {
        $counts = ['items' => 0, 'kept' => 0, 'closed' => 0, 'skipped' => 0];
        $overdueAlerts = fn () => ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'medication')
            ->whereNotNull('client_id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_OVERDUE]);

        $legacy = $overdueAlerts()
            ->whereRaw("JSON_EXTRACT(context, '$.normalized_data.dose_key') IS NULL")
            ->withCount(['tasks as open_task_count' => fn ($tasks) => $tasks->whereNotIn('status', AlertTask::TERMINAL_STATUSES)])
            ->orderBy('id')
            ->get();
        $now = now();

        foreach ($legacy->groupBy('client_id') as $clientId => $alerts) {
            $counts['items']++;
            $perDoseAlertId = $overdueAlerts()
                ->where('client_id', (int) $clientId)
                ->whereRaw("JSON_EXTRACT(context, '$.normalized_data.dose_key') IS NOT NULL")
                ->orderByDesc('id')
                ->value('id');
            $overdueNow = $perDoseAlertId === null
                && app(OverdueDoses::class)->at($now, [(int) $clientId])->isNotEmpty();

            foreach ($alerts as $alert) {
                $action = match (true) {
                    $overdueNow => 'keep_may_be_only_alert',
                    $alert->open_task_count > 0 => 'skip_open_tasks',
                    default => 'close',
                };
                $this->writeManifest($manifest, $alert, (int) $clientId, $action, $perDoseAlertId, null);

                if ($action === 'keep_may_be_only_alert') {
                    $counts['kept']++;

                    continue;
                }
                if ($action === 'skip_open_tasks') {
                    $counts['skipped']++;

                    continue;
                }
                if ($dryRun) {
                    $counts['closed']++;

                    continue;
                }

                try {
                    $lifecycle->resolveAutomatically(
                        $alert,
                        $perDoseAlertId !== null
                            ? "Replaced by per-dose alerts: this older alert names no dose, and the person's overdue doses are now each raised on their own (for example alert #{$perDoseAlertId}). Closed by the duplicate alert clean-up."
                            : 'Replaced by per-dose alerts: this older alert names no dose, and none of the person\'s doses is overdue now. Closed by the duplicate alert clean-up.',
                        self::SUPERSEDED_CODE,
                        self::RESOLUTION_SOURCE,
                        [
                            'replaced_by' => $perDoseAlertId !== null ? 'per_dose_alerts' : 'no_overdue_dose',
                            'per_dose_alert_id' => $perDoseAlertId,
                        ],
                    );
                    $counts['closed']++;
                } catch (InvalidArgumentException) {
                    $counts['skipped']++;
                }
            }
        }

        return $counts;
    }

    /** @param Collection<int, ControlRoomAlert> $alerts */
    private function keeper(Collection $alerts, ?string $conditionKey): ControlRoomAlert
    {
        return $alerts->sortByDesc(fn (ControlRoomAlert $alert): array => [
            $conditionKey !== null && data_get($alert->context, 'normalized_data.'.SignalProcessingService::CONDITION_KEY) === $conditionKey ? 1 : 0,
            $alert->open_task_count > 0 ? 1 : 0,
            ($alert->status !== ControlRoomAlert::STATUS_OPEN || $alert->assigned_to_user_id !== null) ? 1 : 0,
            $alert->triggered_at?->getTimestamp() ?? 0,
            $alert->id,
        ])->first();
    }

    /** @param resource|null $manifest */
    private function writeManifest($manifest, ControlRoomAlert $alert, mixed $item, string $action, ?int $keptId, ?string $conditionKey): void
    {
        if (! is_resource($manifest)) {
            return;
        }

        fwrite($manifest, json_encode([
            'id' => $alert->id,
            'action' => $action,
            'kept_alert_id' => $keptId,
            'alert_type' => $alert->alert_type,
            'source' => $alert->source,
            'item' => $item,
            'condition_key' => $conditionKey,
            'status' => $alert->status,
            'severity' => $alert->severity,
            'site_id' => $alert->site_id,
            'client_id' => $alert->client_id,
            'asset_id' => $alert->asset_id,
            'assigned_to_user_id' => $alert->assigned_to_user_id,
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'escalation_level' => $alert->escalation_level,
            'open_task_count' => $alert->open_task_count,
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
            'created_at' => $alert->created_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES)."\n");
    }
}
