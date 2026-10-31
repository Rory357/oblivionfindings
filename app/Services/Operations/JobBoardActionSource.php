<?php

namespace App\Services\Operations;

use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\User;
use App\Services\Eligibility\AssignmentEligibilityDecision;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/** Private semantic source; only the digest and allowlisted display leave the command. */
final class JobBoardActionSource
{
    public static function values(Model $model, ?array $fields = null): array
    {
        $fields ??= array_values(array_unique(['id', ...$model->getFillable()]));
        sort($fields, SORT_STRING);
        $values = [];
        foreach ($fields as $field) {
            $value = $model->getAttribute($field);
            if ($value instanceof DateTimeInterface) {
                $value = CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
            } elseif ($value !== null && ($field === 'id' || $field === 'version' || str_ends_with($field, '_id')
                || in_array($field, ['claimed_by', 'approved_by', 'requested_by', 'cancelled_by', 'started_by', 'completed_by', 'created_by', 'handover_waived_by'], true))) {
                $value = (int) $value;
            }
            $values[$field] = self::json($value);
        }

        return $values;
    }

    public static function revision(string $action, User $actor, array $source): string
    {
        if ($action === 'set_alert_preference') {
            return self::hash(['action' => $action, 'actor_id' => (int) $actor->id,
                'enabled' => (bool) $actor->job_board_alerts_enabled]);
        }
        $shift = $source['shift'];

        return self::hash(['action' => $action, 'actor_id' => (int) $actor->id,
            'position' => self::values($source['position']),
            'shift' => self::values($shift, ['id', ...$shift->getFillable(), 'version', 'roster_period_id', 'published_at', 'publish_dirty_at']),
            'replacement' => $source['replacement'] ? self::values($source['replacement']) : null,
            'siblings' => $source['siblings']->map(fn (Model $row) => self::values($row))->all(),
            'holds' => $source['holds']->map(fn (Model $row) => self::values($row))->all(),
            // Bind exactly the permitted displayed labels too, without exposing their source rows.
            'display' => self::display($source, $actor)]);
    }

    public static function display(array $source, User $actor): array
    {
        $position = $source['position'];
        $shift = $source['shift'];
        $client = $shift->client;
        $sensitive = $actor->canDo('job_board.approve') || $actor->canDo('shifts.manageAny') || $actor->canDo('shifts.viewAny')
            || (int) $shift->user_id === (int) $actor->id
            || ($position->status === 'filled' && (int) $position->claimed_by === (int) $actor->id);
        $first = $sensitive ? $client?->first_name : self::initial($client?->first_name);
        $last = $sensitive ? $client?->last_name : self::initial($client?->last_name);

        return ['position_id' => (int) $position->id, 'shift_id' => (int) $shift->id,
            'status' => $position->status, 'starts_at' => self::instant($shift->starts_at), 'ends_at' => self::instant($shift->ends_at),
            'client' => trim($first.' '.$last) ?: 'Client', 'client_redacted' => ! $sensitive,
            'site' => $source['site']->name ?: null,
            'claimed_by' => $position->claimed_by === null ? null : (int) $position->claimed_by,
            'claimant' => $source['claimant']?->name ?: null];
    }

    public static function position(ShiftOpenPosition $position): array
    {
        return ['id' => (int) $position->id, 'shift_id' => (int) $position->shift_id, 'status' => $position->status,
            'claimed_by' => $position->claimed_by === null ? null : (int) $position->claimed_by,
            'claimed_at' => self::instant($position->claimed_at),
            'approved_by' => $position->approved_by === null ? null : (int) $position->approved_by,
            'approved_at' => self::instant($position->approved_at)];
    }

    public static function shift(Shift $shift): array
    {
        return ['id' => (int) $shift->id, 'status' => $shift->status,
            'assigned' => $shift->user_id !== null, 'starts_at' => self::instant($shift->starts_at), 'ends_at' => self::instant($shift->ends_at)];
    }

    public static function eligibility(?AssignmentEligibilityDecision $decision): ?array
    {
        if (! $decision) {
            return null;
        }
        $checks = $decision->result?->checked_rules ?? [];
        $warnings = [];
        $blocks = [];
        foreach ($checks as $check) {
            if (($check['passed'] ?? true) === true) {
                continue;
            }
            $message = match ($check['rule'] ?? '') {
                'conflict' => 'Another duty overlaps this Shift.',
                'turnaround' => 'The gap between duties needs review.',
                'availability' => 'The Shift is outside declared availability.',
                'time_off', 'availability_leave' => 'Time off overlaps this Shift.',
                'availability_employment' => 'The Shift falls outside active employment dates.',
                'client_qualification' => 'A required Client qualification needs review.',
                'house_qualification', 'house_qualification_coverage', 'house_qualification_configuration' => 'A House qualification requirement needs review.',
                'coverage_roles', 'overfill' => 'Staffing coverage needs review.',
                default => 'An eligibility requirement needs review.',
            };
            if (($check['severity'] ?? '') === 'block') {
                $blocks[] = $message;
            } elseif (($check['severity'] ?? '') === 'warning') {
                $warnings[] = $message;
            }
        }
        if ($decision->isWarning() && $warnings === []) {
            $warnings[] = 'An eligibility warning needs review.';
        }
        if ($decision->status->value === 'hard_block' && $blocks === []) {
            $blocks[] = 'The worker is not eligible for this Shift.';
        }

        return ['assessment_status' => $decision->result ? 'assessed' : 'unknown',
            'warning_reasons' => array_values(array_unique($warnings)), 'blocked_reasons' => array_values(array_unique($blocks)),
            'acknowledgement_required' => false, 'reason_required' => false];
    }

    public static function instant(?DateTimeInterface $date): ?string
    {
        return $date ? CarbonImmutable::instance($date)->utc()->toISOString() : null;
    }

    private static function initial(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : mb_strtoupper(mb_substr($name, 0, 1)).'.';
    }

    private static function hash(array $values): string
    {
        return hash('sha256', json_encode(self::json($values), JSON_THROW_ON_ERROR));
    }

    private static function json(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::json(...), $value);
    }
}
