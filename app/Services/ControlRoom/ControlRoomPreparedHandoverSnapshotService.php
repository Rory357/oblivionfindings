<?php

namespace App\Services\ControlRoom;

use App\Models\AuditLog;
use App\Models\ControlRoom\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class ControlRoomPreparedHandoverSnapshotService
{
    public const INVALID_MESSAGE = 'This prepared handover snapshot is incomplete or inconsistent. The outgoing shift remains active and the handover cannot be accepted.';

    /**
     * Validate and reduce a prepared snapshot to the fields the handover page
     * and atomic acceptance transition are allowed to trust.
     *
     * @return array<string, mixed>
     */
    public function validated(Shift $shift): array
    {
        $snapshot = $shift->handover_snapshot;
        if (! is_array($snapshot)) {
            $this->invalid();
        }

        $preparedBy = $this->person($snapshot['prepared_by'] ?? null);
        $preparedAt = $this->timestamp($snapshot['prepared_at'] ?? null);
        $override = $this->override(
            $snapshot['override'] ?? null,
            $preparedBy,
            $preparedAt,
        );
        if ($override !== null && ! $this->hasMatchingOverrideAudit(
            $shift,
            $preparedBy['id'],
            $override,
        )) {
            $this->invalid();
        }
        $incoming = $this->requiredArray($snapshot['incoming_shift'] ?? null);
        $incomingLead = $this->person($incoming['lead'] ?? null);
        if ($incomingLead['id'] !== (int) $shift->handed_over_to_user_id) {
            $this->invalid();
        }
        if (($override === null && $preparedBy['id'] !== (int) $shift->shift_lead_user_id)
            || ($override !== null && $preparedBy['id'] === (int) $shift->shift_lead_user_id)
        ) {
            $this->invalid();
        }

        $incomingTeam = $this->people($incoming['team_members'] ?? null);
        $participantIds = collect([
            $preparedBy['id'],
            $incomingLead['id'],
            ...collect($incomingTeam)->pluck('id')->all(),
        ])->unique()->values()->all();
        if (User::query()->whereIn('id', $participantIds)->count() !== count($participantIds)) {
            $this->invalid();
        }

        $criteria = $this->criteria($snapshot['criteria'] ?? null);
        $criteriaKeys = collect($criteria)->pluck('key');
        $requiredIds = $this->integerList($snapshot['required_alert_ids'] ?? null);
        $reviewedIds = $this->integerList($snapshot['reviewed_alert_ids'] ?? null);
        $priorityIds = $this->integerList($snapshot['priority_alert_ids'] ?? null);
        if ($requiredIds !== $reviewedIds
            || collect($priorityIds)->diff($requiredIds)->isNotEmpty()
        ) {
            $this->invalid();
        }

        $alerts = $this->alerts($snapshot['alerts'] ?? null, $criteriaKeys->all());
        $alertIds = collect($alerts)->pluck('id')->sort()->values()->all();
        if ($alertIds !== collect($requiredIds)->sort()->values()->all()) {
            $this->invalid();
        }

        $carryForwardIds = $this->integerList($snapshot['carry_forward_alert_ids'] ?? null);
        if (collect($carryForwardIds)->intersect($requiredIds)->isNotEmpty()) {
            $this->invalid();
        }
        $carryForward = $this->carryForward($snapshot['carry_forward'] ?? null);
        if ($carryForward['total'] !== count($carryForwardIds)) {
            $this->invalid();
        }

        $carryAcknowledged = ($snapshot['carry_forward_acknowledged'] ?? null) === true;
        $carryAcknowledgedBy = $this->person($snapshot['carry_forward_acknowledged_by'] ?? null);
        if (($carryForward['total'] > 0 && ! $carryAcknowledged)
            || $carryAcknowledgedBy['id'] !== $preparedBy['id']
        ) {
            $this->invalid();
        }

        return [
            'prepared_by' => $preparedBy,
            'prepared_at' => $preparedAt,
            'override' => $override,
            'criteria_at' => $this->timestamp($snapshot['criteria_at'] ?? null),
            'next_expected_shift_at' => $this->timestamp($snapshot['next_expected_shift_at'] ?? null),
            'criteria' => $criteria,
            'handover_notes' => $this->string($snapshot['handover_notes'] ?? null, true),
            'incoming_shift' => [
                'name' => $this->string($incoming['name'] ?? null),
                'lead' => $incomingLead,
                'team_members' => $incomingTeam,
            ],
            'required_alert_ids' => $requiredIds,
            'reviewed_alert_ids' => $reviewedIds,
            'priority_alert_ids' => $priorityIds,
            'alerts' => $alerts,
            'carry_forward_alert_ids' => $carryForwardIds,
            'carry_forward' => $carryForward,
            'carry_forward_acknowledged' => $carryForward['total'] === 0 || $carryAcknowledged,
            'carry_forward_acknowledged_by' => $carryAcknowledgedBy,
            'carry_forward_acknowledged_at' => $this->timestamp(
                $snapshot['carry_forward_acknowledged_at'] ?? null,
            ),
            'pinned_notes' => $this->notes($snapshot['pinned_notes'] ?? null),
            'followup_notes' => $this->notes($snapshot['followup_notes'] ?? null),
        ];
    }

    /**
     * @param  array{actor: array{id: int, name: string}, reason: string, at: string}  $override
     */
    private function hasMatchingOverrideAudit(
        Shift $shift,
        int $actorId,
        array $override,
    ): bool {
        return AuditLog::query()
            ->where('action', 'controlRoom.shift.handoverPrepared')
            ->where('auditable_type', $shift->getMorphClass())
            ->where('auditable_id', $shift->id)
            ->where('user_id', $actorId)
            ->get(['meta'])
            ->contains(fn (AuditLog $audit): bool => (
                (int) data_get($audit->meta, 'override.actor_id') === $actorId
                && data_get($audit->meta, 'override.reason') === $override['reason']
                && data_get($audit->meta, 'override.at') === $override['at']
            ));
    }

    /**
     * @param  array{id: int, name: string}  $preparedBy
     * @return array{actor: array{id: int, name: string}, reason: string, at: string}|null
     */
    private function override(
        mixed $value,
        array $preparedBy,
        string $preparedAt,
    ): ?array {
        if ($value === null) {
            return null;
        }

        $override = $this->requiredArray($value);
        $actor = $this->person($override['actor'] ?? null);
        $reason = $this->string($override['reason'] ?? null);
        $at = $this->timestamp($override['at'] ?? null);
        if ($actor['id'] !== $preparedBy['id']
            || $actor['name'] !== $preparedBy['name']
            || $reason !== trim($reason)
            || mb_strlen($reason) < 10
            || mb_strlen($reason) > 2000
            || ! Carbon::parse($at)->equalTo(Carbon::parse($preparedAt))
        ) {
            $this->invalid();
        }

        return [
            'actor' => $actor,
            'reason' => $reason,
            'at' => $at,
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function people(mixed $value): array
    {
        if (! is_array($value)) {
            $this->invalid();
        }

        $people = collect($value)->map(fn ($person): array => $this->person($person));
        if ($people->pluck('id')->unique()->count() !== $people->count()) {
            $this->invalid();
        }

        return $people->values()->all();
    }

    /** @return array{id: int, name: string} */
    private function person(mixed $value): array
    {
        $person = $this->requiredArray($value);
        if (! is_numeric($person['id'] ?? null) || (int) $person['id'] < 1) {
            $this->invalid();
        }

        return [
            'id' => (int) $person['id'],
            'name' => $this->string($person['name'] ?? null),
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function criteria(mixed $value): array
    {
        if (! is_array($value) || count($value) !== 7) {
            $this->invalid();
        }

        $criteria = collect($value)->map(function ($criterion): array {
            $criterion = $this->requiredArray($criterion);

            return [
                'key' => $this->string($criterion['key'] ?? null),
                'label' => $this->string($criterion['label'] ?? null),
            ];
        });
        if ($criteria->pluck('key')->unique()->count() !== $criteria->count()) {
            $this->invalid();
        }
        $taxonomy = $criteria
            ->mapWithKeys(fn (array $criterion): array => [
                $criterion['key'] => $criterion['label'],
            ])
            ->all();
        if ($taxonomy !== ControlRoomHandoverScopeService::CRITERIA) {
            $this->invalid();
        }

        return $criteria->values()->all();
    }

    /**
     * @param  list<string>  $criteriaKeys
     * @return list<array<string, mixed>>
     */
    private function alerts(mixed $value, array $criteriaKeys): array
    {
        if (! is_array($value)) {
            $this->invalid();
        }

        $alerts = collect($value)->map(
            fn ($alert): array => $this->alert($alert, $criteriaKeys),
        );
        if ($alerts->pluck('id')->unique()->count() !== $alerts->count()) {
            $this->invalid();
        }

        return $alerts->values()->all();
    }

    /**
     * @param  list<string>  $criteriaKeys
     * @return array<string, mixed>
     */
    private function alert(mixed $value, array $criteriaKeys): array
    {
        $alert = $this->requiredArray($value);
        if (! is_numeric($alert['id'] ?? null) || (int) $alert['id'] < 1) {
            $this->invalid();
        }
        $id = (int) $alert['id'];
        $expectedHref = '/control-room/alerts/'.$id;
        $href = $this->string($alert['href'] ?? null);
        $nextAction = $this->requiredArray($alert['next_action'] ?? null);
        if ($href !== $expectedHref
            || $this->string($nextAction['href'] ?? null) !== $expectedHref
        ) {
            $this->invalid();
        }

        $sla = $this->requiredArray($alert['sla'] ?? null);
        $journey = $this->requiredArray($alert['journey'] ?? null);
        $reasons = $this->reasons($alert['handover_reasons'] ?? null, $criteriaKeys);

        return [
            'id' => $id,
            'reference_number' => $this->nullableString($alert['reference_number'] ?? null),
            'summary' => $this->string($alert['summary'] ?? null),
            'severity' => $this->string($alert['severity'] ?? null),
            'site' => $this->nullablePerson($alert['site'] ?? null),
            'person' => $this->nullablePerson($alert['person'] ?? null),
            'assignee' => $this->nullablePerson($alert['assignee'] ?? null),
            'sla' => [
                'status' => $this->nullableString($sla['status'] ?? null),
                'next_deadline_at' => $this->nullableTimestamp($sla['next_deadline_at'] ?? null),
            ],
            'journey' => [
                'incident_reference' => $this->nullableString($journey['incident_reference'] ?? null),
                'health_safety_reference' => $this->nullableString($journey['health_safety_reference'] ?? null),
                'handover_status' => $this->nullableString($journey['handover_status'] ?? null),
            ],
            'next_action' => [
                'label' => $this->string($nextAction['label'] ?? null),
                'href' => $expectedHref,
            ],
            'href' => $expectedHref,
            'tasks' => $this->tasks($alert['tasks'] ?? null, $expectedHref),
            'handover_reasons' => $reasons,
        ];
    }

    /**
     * @param  list<string>  $criteriaKeys
     * @return list<array{key: string, label: string}>
     */
    private function reasons(mixed $value, array $criteriaKeys): array
    {
        if (! is_array($value) || $value === []) {
            $this->invalid();
        }

        $reasons = collect($value)->map(function ($reason) use ($criteriaKeys): array {
            $reason = $this->requiredArray($reason);
            $key = $this->string($reason['key'] ?? null);
            $label = $this->string($reason['label'] ?? null);
            if (! in_array($key, $criteriaKeys, true)
                || (ControlRoomHandoverScopeService::CRITERIA[$key] ?? null) !== $label
            ) {
                $this->invalid();
            }

            return [
                'key' => $key,
                'label' => $label,
            ];
        });
        if ($reasons->pluck('key')->unique()->count() !== $reasons->count()) {
            $this->invalid();
        }

        return $reasons->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function tasks(mixed $value, string $expectedHref): array
    {
        if (! is_array($value)) {
            $this->invalid();
        }

        return collect($value)->map(function ($task) use ($expectedHref): array {
            $task = $this->requiredArray($task);
            if (! is_numeric($task['id'] ?? null) || (int) $task['id'] < 1) {
                $this->invalid();
            }
            if (array_key_exists('href', $task)
                && $this->string($task['href']) !== $expectedHref
            ) {
                $this->invalid();
            }

            return [
                'id' => (int) $task['id'],
                'title' => $this->string($task['title'] ?? null),
                'status' => $this->string($task['status'] ?? null),
                'priority' => $this->nullableString($task['priority'] ?? null),
                'due_at' => $this->nullableTimestamp($task['due_at'] ?? null),
                'href' => $expectedHref,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function carryForward(mixed $value): array
    {
        $summary = $this->requiredArray($value);
        $total = $this->nonNegativeInteger($summary['total'] ?? null);
        $breached = $this->nonNegativeInteger($summary['breached_count'] ?? null);
        if ($breached > $total) {
            $this->invalid();
        }

        $severities = $this->requiredArray($summary['by_severity'] ?? null);
        $bySeverity = [];
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $bySeverity[$severity] = $this->nonNegativeInteger($severities[$severity] ?? null);
        }
        if (array_sum($bySeverity) !== $total) {
            $this->invalid();
        }

        $queues = $summary['by_queue'] ?? null;
        if (! is_array($queues)) {
            $this->invalid();
        }
        $byQueue = collect($queues)->map(function ($queue): array {
            $queue = $this->requiredArray($queue);
            $id = $queue['id'] ?? null;
            if ($id !== null && (! is_numeric($id) || (int) $id < 1)) {
                $this->invalid();
            }

            return [
                'id' => $id === null ? null : (int) $id,
                'name' => $this->string($queue['name'] ?? null),
                'total' => $this->nonNegativeInteger($queue['total'] ?? null),
            ];
        })->values()->all();
        if (collect($byQueue)->sum('total') !== $total) {
            $this->invalid();
        }

        $href = $this->string($summary['href'] ?? null);
        $signature = $this->string($summary['signature'] ?? null);
        if ($href !== '/control-room/alerts?lens=active&handover=carry-forward'
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1
        ) {
            $this->invalid();
        }

        return [
            'total' => $total,
            'by_severity' => $bySeverity,
            'by_queue' => $byQueue,
            'oldest_created_at' => $this->nullableTimestamp($summary['oldest_created_at'] ?? null),
            'breached_count' => $breached,
            'href' => $href,
            'signature' => $signature,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function notes(mixed $value): array
    {
        if (! is_array($value)) {
            $this->invalid();
        }

        return collect($value)->map(function ($note): array {
            $note = $this->requiredArray($note);
            if (! is_numeric($note['id'] ?? null) || (int) $note['id'] < 1) {
                $this->invalid();
            }

            return [
                'id' => (int) $note['id'],
                'type' => $this->string($note['type'] ?? null),
                'content' => $this->string($note['content'] ?? null),
                'is_pinned' => (bool) ($note['is_pinned'] ?? false),
                'requires_followup' => (bool) ($note['requires_followup'] ?? false),
                'followup_at' => $this->nullableTimestamp($note['followup_at'] ?? null),
                'user' => $this->nullablePerson($note['user'] ?? null),
                'created_at' => $this->timestamp($note['created_at'] ?? null),
            ];
        })->values()->all();
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        if (! is_array($value)) {
            $this->invalid();
        }

        $ids = collect($value)->map(function ($id): int {
            if (! is_numeric($id) || (int) $id < 1) {
                $this->invalid();
            }

            return (int) $id;
        });
        if ($ids->unique()->count() !== $ids->count()) {
            $this->invalid();
        }

        return $ids->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    private function requiredArray(mixed $value): array
    {
        if (! is_array($value)) {
            $this->invalid();
        }

        return $value;
    }

    /** @return array{id: int, name: string}|null */
    private function nullablePerson(mixed $value): ?array
    {
        return $value === null ? null : $this->person($value);
    }

    private function string(mixed $value, bool $allowEmpty = false): string
    {
        if (! is_string($value) || (! $allowEmpty && trim($value) === '')) {
            $this->invalid();
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $this->invalid();
        }

        return $value;
    }

    private function timestamp(mixed $value): string
    {
        if (! is_string($value)) {
            $this->invalid();
        }

        try {
            Carbon::parse($value);
        } catch (\Throwable) {
            $this->invalid();
        }

        return $value;
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        return $value === null ? null : $this->timestamp($value);
    }

    private function nonNegativeInteger(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (float) $value !== (float) (int) $value) {
            $this->invalid();
        }

        return (int) $value;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'handover' => self::INVALID_MESSAGE,
        ]);
    }
}
