<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoomAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AlertPriorityService
{
    /** @var array<string, int> */
    private const LEVEL_RANK = [
        'critical' => 0,
        'urgent' => 0,
        'high' => 1,
        'medium' => 2,
        'normal' => 2,
        'low' => 3,
    ];

    /**
     * Apply the canonical deterministic worklist order to a database query.
     */
    public function apply(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $priorityLevel = "COALESCE(NULLIF(LOWER(`{$table}`.`priority`), ''), LOWER(`{$table}`.`severity`))";
        $applicableSla = "`worklist_sla`.`id` IS NOT NULL AND `worklist_sla`.`sla_definition_id` IS NOT NULL AND (`worklist_sla`.`ended_as` IS NULL OR `worklist_sla`.`ended_as` NOT IN ('reconciled_no_match', 'dismissed'))";
        $breached = <<<'SQL'
CASE WHEN `worklist_sla`.`id` IS NOT NULL
    AND `worklist_sla`.`sla_definition_id` IS NOT NULL
    AND (`worklist_sla`.`ended_as` IS NULL OR `worklist_sla`.`ended_as` NOT IN ('reconciled_no_match', 'dismissed'))
    AND (
        `worklist_sla`.`acknowledge_breached` = 1
        OR `worklist_sla`.`response_breached` = 1
        OR `worklist_sla`.`resolution_breached` = 1
        OR (`worklist_sla`.`acknowledged_at` IS NULL AND `worklist_sla`.`acknowledge_deadline` < CURRENT_TIMESTAMP)
        OR (`worklist_sla`.`responded_at` IS NULL AND `worklist_sla`.`response_deadline` < CURRENT_TIMESTAMP)
        OR (`worklist_sla`.`resolved_at` IS NULL AND `worklist_sla`.`resolution_deadline` < CURRENT_TIMESTAMP)
        OR (`worklist_sla`.`acknowledged_at` > `worklist_sla`.`acknowledge_deadline`)
        OR (`worklist_sla`.`responded_at` > `worklist_sla`.`response_deadline`)
        OR (`worklist_sla`.`resolved_at` > `worklist_sla`.`resolution_deadline`)
    ) THEN 0 ELSE 1 END
SQL;
        $nextDeadline = 'LEAST('
            ."COALESCE(CASE WHEN {$applicableSla} AND `worklist_sla`.`acknowledged_at` IS NULL THEN `worklist_sla`.`acknowledge_deadline` END, '9999-12-31 23:59:59'),"
            ."COALESCE(CASE WHEN {$applicableSla} AND `worklist_sla`.`responded_at` IS NULL THEN `worklist_sla`.`response_deadline` END, '9999-12-31 23:59:59'),"
            ."COALESCE(CASE WHEN {$applicableSla} AND `worklist_sla`.`resolved_at` IS NULL THEN `worklist_sla`.`resolution_deadline` END, '9999-12-31 23:59:59'),"
            ."COALESCE(`{$table}`.`due_at`, '9999-12-31 23:59:59')"
            .')';

        return $query
            ->leftJoin('control_room_alert_sla as worklist_sla', 'worklist_sla.alert_id', '=', "{$table}.id")
            ->orderByRaw($breached)
            ->orderByRaw("CASE {$priorityLevel} WHEN 'critical' THEN 0 WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByDesc("{$table}.escalation_level")
            ->orderByRaw("CASE WHEN {$nextDeadline} = '9999-12-31 23:59:59' THEN 1 ELSE 0 END")
            ->orderByRaw("{$nextDeadline} ASC")
            ->orderBy("{$table}.triggered_at")
            ->orderBy("{$table}.id");
    }

    /**
     * Apply the escalation workspace order. Queue tier and time in the current
     * queue are explicit here because they are not part of the general alert
     * worklist contract.
     */
    public function applyEscalation(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $applicableSla = "`escalation_sla`.`id` IS NOT NULL AND `escalation_sla`.`sla_definition_id` IS NOT NULL AND (`escalation_sla`.`ended_as` IS NULL OR `escalation_sla`.`ended_as` NOT IN ('reconciled_no_match', 'dismissed'))";
        $breached = <<<'SQL'
CASE WHEN `escalation_sla`.`id` IS NOT NULL
    AND `escalation_sla`.`sla_definition_id` IS NOT NULL
    AND (`escalation_sla`.`ended_as` IS NULL OR `escalation_sla`.`ended_as` NOT IN ('reconciled_no_match', 'dismissed'))
    AND (
        `escalation_sla`.`acknowledge_breached` = 1
        OR `escalation_sla`.`response_breached` = 1
        OR `escalation_sla`.`resolution_breached` = 1
        OR (`escalation_sla`.`acknowledged_at` IS NULL AND `escalation_sla`.`acknowledge_deadline` < CURRENT_TIMESTAMP)
        OR (`escalation_sla`.`responded_at` IS NULL AND `escalation_sla`.`response_deadline` < CURRENT_TIMESTAMP)
        OR (`escalation_sla`.`resolved_at` IS NULL AND `escalation_sla`.`resolution_deadline` < CURRENT_TIMESTAMP)
        OR (`escalation_sla`.`acknowledged_at` > `escalation_sla`.`acknowledge_deadline`)
        OR (`escalation_sla`.`responded_at` > `escalation_sla`.`response_deadline`)
        OR (`escalation_sla`.`resolved_at` > `escalation_sla`.`resolution_deadline`)
    ) THEN 0 ELSE 1 END
SQL;
        $nextDeadline = 'LEAST('
            ."COALESCE(CASE WHEN {$applicableSla} AND `escalation_sla`.`acknowledged_at` IS NULL THEN `escalation_sla`.`acknowledge_deadline` END, '9999-12-31 23:59:59'),"
            ."COALESCE(CASE WHEN {$applicableSla} AND `escalation_sla`.`responded_at` IS NULL THEN `escalation_sla`.`response_deadline` END, '9999-12-31 23:59:59'),"
            ."COALESCE(CASE WHEN {$applicableSla} AND `escalation_sla`.`resolved_at` IS NULL THEN `escalation_sla`.`resolution_deadline` END, '9999-12-31 23:59:59'),"
            ."COALESCE(`{$table}`.`due_at`, '9999-12-31 23:59:59')"
            .')';

        return $query
            ->leftJoin('control_room_alert_sla as escalation_sla', 'escalation_sla.alert_id', '=', "{$table}.id")
            ->orderByRaw($breached)
            ->orderByDesc('worklist_queue.tier')
            ->orderByRaw("CASE LOWER(`{$table}`.`severity`) WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByRaw("CASE WHEN {$nextDeadline} = '9999-12-31 23:59:59' THEN 1 ELSE 0 END")
            ->orderByRaw("{$nextDeadline} ASC")
            ->orderByRaw('CASE WHEN `current_queue_entry`.`entered_queue_at` IS NULL THEN 1 ELSE 0 END')
            ->orderBy('current_queue_entry.entered_queue_at')
            ->orderBy("{$table}.triggered_at")
            ->orderBy("{$table}.id");
    }

    /**
     * Mirror the database order for already-loaded alerts and compact widgets.
     *
     * @param  Collection<int, ControlRoomAlert>  $alerts
     * @return Collection<int, ControlRoomAlert>
     */
    public function sortActionable(Collection $alerts): Collection
    {
        return $alerts
            ->filter(fn (ControlRoomAlert $alert) => $alert->isActionable() && ! $alert->isSnoozed())
            ->sort(fn (ControlRoomAlert $left, ControlRoomAlert $right) => $this->sortKey($left) <=> $this->sortKey($right))
            ->values();
    }

    /** @return array{level: string, rank: int, reason: string} */
    public function describe(ControlRoomAlert $alert): array
    {
        $sla = $this->sla($alert);
        if ($sla?->isBreached()) {
            $milestone = collect(['acknowledge', 'response', 'resolution'])
                ->first(fn (string $name) => $sla->isMilestoneBreached($name));

            return [
                'level' => 'critical',
                'rank' => 0,
                'reason' => ucfirst((string) $milestone).' SLA breached',
            ];
        }

        $explicit = strtolower(trim((string) $alert->priority));
        if ($explicit !== '' && array_key_exists($explicit, self::LEVEL_RANK)) {
            return [
                'level' => $explicit,
                'rank' => self::LEVEL_RANK[$explicit],
                'reason' => ucfirst($explicit).' priority',
            ];
        }

        $severity = strtolower(trim((string) $alert->severity));

        return [
            'level' => $severity !== '' ? $severity : 'unknown',
            'rank' => self::LEVEL_RANK[$severity] ?? 4,
            'reason' => $severity !== '' ? ucfirst($severity).' severity' : 'Priority not set',
        ];
    }

    public function nextDeadline(ControlRoomAlert $alert): ?Carbon
    {
        $candidates = collect([$alert->due_at]);
        $sla = $this->sla($alert);

        if ($sla instanceof AlertSla && $sla->isApplicable()) {
            foreach ([
                ['acknowledge_deadline', 'acknowledged_at'],
                ['response_deadline', 'responded_at'],
                ['resolution_deadline', 'resolved_at'],
            ] as [$deadline, $completed]) {
                if ($sla->{$completed} === null && $sla->{$deadline} !== null) {
                    $candidates->push($sla->{$deadline});
                }
            }
        }

        return $candidates
            ->filter()
            ->map(fn ($value) => $value instanceof Carbon ? $value : Carbon::parse($value))
            ->sortBy(fn (Carbon $value) => $value->getTimestamp())
            ->first();
    }

    /** @return array<int, int> */
    private function sortKey(ControlRoomAlert $alert): array
    {
        $description = $this->describe($alert);
        $deadline = $this->nextDeadline($alert);

        return [
            $this->isBreached($alert) ? 0 : 1,
            $description['rank'],
            -((int) $alert->escalation_level),
            $deadline === null ? 1 : 0,
            $deadline?->getTimestamp() ?? PHP_INT_MAX,
            $alert->triggered_at?->getTimestamp() ?? PHP_INT_MAX,
            (int) $alert->getKey(),
        ];
    }

    private function isBreached(ControlRoomAlert $alert): bool
    {
        $sla = $this->sla($alert);

        return $sla instanceof AlertSla && $sla->isBreached();
    }

    private function sla(ControlRoomAlert $alert): ?AlertSla
    {
        $sla = $alert->relationLoaded('sla') ? $alert->sla : null;
        if (! $sla instanceof AlertSla) {
            return null;
        }

        if (! $sla->relationLoaded('alert')) {
            $sla->setRelation('alert', $alert);
        }

        return $sla;
    }
}
