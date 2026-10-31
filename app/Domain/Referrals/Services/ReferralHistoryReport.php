<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Completed visits only; ongoing intervals are reported separately by ReferralWorkingTime. */
final class ReferralHistoryReport
{
    public const KINDS = ['referral.received', 'support.preparation_opened', 'stage.changed', 'readiness.review_required', 'handover.committed', 'referral.closed', 'referral.reopened', 'hold.recorded', 'hold.released'];

    public const DEFINITION = 'Completed visits and waiting-reason segments across the recorded history of referrals in this scope, through generation time. Received dates select referrals, not event dates. Repeated visits count separately; ongoing visits are excluded. Changing a waiting reason ends one segment and begins another, without resetting the uninterrupted current-hold clock. Means use their own valid-interval denominators and the original intake working calendar. Missing or inconsistent history is not treated as zero. Stage groups use stable lifecycle categories. Reopenings are events on existing referrals, not new referrals or new people.';

    public function build(Builder $query, CarbonImmutable $cutoff, ?array $calendars = null): array
    {
        $calendars ??= app(ReferralWorkingTime::class)->calendars($query);
        $records = (clone $query)->select(['id', 'created_at', 'stage', 'hold_reason'])->get()->keyBy('id');
        $events = ReferralEvent::query()->whereIn('referral_id', (clone $query)->select('id'))
            ->where('audience', 'intake')->whereIn('kind', self::KINDS)->where('occurred_at', '<=', $cutoff)
            ->orderBy('referral_id')->orderBy('occurred_at')->orderBy('id')->cursor();
        $result = ['stages' => [], 'holds' => [], 'reopenings' => 0, 'reopened_referrals' => 0, 'incomplete_referrals' => 0, 'referrals' => $records->count()];
        $process = function ($id, $history) use (&$result, $records, $calendars, $cutoff) {
            $record = $records->get($id);
            $visits = self::visits(CarbonImmutable::instance($record->created_at), $history, $cutoff);
            $result['reopenings'] += $visits['reopenings'];
            $result['reopened_referrals'] += (int) ($visits['reopenings'] > 0);
            $result['incomplete_referrals'] += (int) (! $visits['initial'] || $visits['gaps'] > 0 || $visits['stage'] !== $record->stage
                || (! in_array($record->stage, ['closed', 'converted'], true) && $visits['hold'] !== $record->hold_reason));
            foreach (['stages', 'holds'] as $kind) {
                foreach ($visits[$kind] as $visit) {
                    $key = $visit['key'];
                    $result[$kind][$key] ??= ['key' => $key, 'count' => 0, 'elapsed' => ['total_hours' => 0, 'count' => 0], 'working' => ['total_hours' => 0, 'count' => 0], 'unknown_dates' => 0, 'unknown_calendar' => 0];
                    $row = &$result[$kind][$key];
                    $row['count']++;
                    if (! $visit['from'] || $visit['from']->gt($visit['to'])) {
                        $row['unknown_dates']++;
                    } else {
                        $row['elapsed']['total_hours'] += $visit['from']->diffInSeconds($visit['to']) / 3600;
                        $row['elapsed']['count']++;
                        $working = ReferralWorkingTime::seconds($visit['from'], $visit['to'], $calendars[$id] ?? null);
                        if ($working === null) {
                            $row['unknown_calendar']++;
                        } else {
                            $row['working']['total_hours'] += $working / 3600;
                            $row['working']['count']++;
                        }
                    }
                    unset($row);
                }
            }
        };
        $id = null;
        $history = [];
        $seen = [];
        foreach ($events as $event) {
            if ($id !== null && $id !== $event->referral_id) {
                $process($id, $history);
                $history = [];
            }
            $id = $event->referral_id;
            $seen[$id] = true;
            $history[] = ['kind' => $event->kind, 'at' => $event->occurred_at, 'payload' => $event->payload];
        }
        if ($id !== null) {
            $process($id, $history);
        }
        $result['incomplete_referrals'] += $records->count() - count($seen);
        foreach (['stages', 'holds'] as $kind) {
            ksort($result[$kind]);
            foreach ($result[$kind] as &$row) {
                foreach (['elapsed', 'working'] as $clock) {
                    $row[$clock]['mean_hours'] = $row[$clock]['count'] ? round($row[$clock]['total_hours'] / $row[$clock]['count'], 6) : null;
                    $row[$clock]['total_hours'] = $row[$clock]['count'] ? round($row[$clock]['total_hours'], 6) : null;
                }
            }
            unset($row);
            $result[$kind] = array_values($result[$kind]);
        }

        return $result;
    }

    /** Events must be ordered by recorded time then id. No narrative fields leave this reducer. */
    public static function visits(CarbonImmutable $created, iterable $events, CarbonImmutable $cutoff): array
    {
        $result = ['stages' => [], 'holds' => [], 'stage' => null, 'hold' => null, 'reopenings' => 0, 'initial' => false, 'gaps' => 0];
        $stageStart = null;
        $holdStart = null;
        $endHold = function ($reason, $at) use (&$result, &$holdStart) {
            if ($reason !== null) {
                $matches = $result['hold'] === $reason;
                $result['holds'][] = ['key' => $reason, 'from' => $matches ? $holdStart : null, 'to' => $at];
                if (! $matches || ! $holdStart) {
                    $result['gaps']++;
                }
            }
            $result['hold'] = null;
            $holdStart = null;
        };
        $move = function ($from, $to, $at) use (&$result, &$stageStart) {
            if ($from !== null && ! in_array($from, ['closed', 'converted'], true)) {
                $matches = $result['stage'] === $from;
                $result['stages'][] = ['key' => $from, 'from' => $matches ? $stageStart : null, 'to' => $at];
                if (! $matches || ! $stageStart) {
                    $result['gaps']++;
                }
            } elseif ($from === null) {
                $result['gaps']++;
            }
            $result['stage'] = $to;
            $stageStart = $at;
        };
        foreach ($events as $event) {
            $at = CarbonImmutable::instance($event['at']);
            if ($at->lt($created) || $at->gt($cutoff)) {
                $result['gaps']++;

                continue;
            }
            $payload = $event['payload'];
            switch ($event['kind']) {
                case 'referral.received':
                case 'support.preparation_opened':
                    if ($result['initial'] || $result['stage'] !== null) {
                        $result['gaps']++;
                        break;
                    }
                    $result['initial'] = true;
                    $result['stage'] = $event['kind'] === 'referral.received' ? 'new' : 'assessment';
                    $stageStart = $created;
                    break;
                case 'stage.changed':
                    $move($payload['previous_stage'] ?? null, $payload['stage'] ?? null, $at);
                    break;
                case 'readiness.review_required':
                    $move('ready_for_handover', 'assessment', $at);
                    break;
                case 'handover.committed':
                    $move($payload['previous_stage'] ?? null, 'converted', $at);
                    $endHold($result['hold'], $at);
                    break;
                case 'referral.closed':
                    $move($payload['previous_stage'] ?? null, 'closed', $at);
                    $endHold($result['hold'], $at);
                    break;
                case 'referral.reopened':
                    $result['reopenings']++;
                    if ($result['stage'] !== 'closed' || ! isset($payload['stage'])) {
                        $result['gaps']++;
                    }
                    $move('closed', $payload['stage'] ?? null, $at);
                    $endHold($result['hold'], $at);
                    break;
                case 'hold.recorded':
                case 'hold.released':
                    if (! array_key_exists('previous_hold_reason', $payload)) {
                        $result['gaps']++;
                    }
                    if (array_key_exists('previous_hold_reason', $payload) && $payload['previous_hold_reason'] !== $result['hold']) {
                        $result['gaps']++;
                        $holdStart = null;
                    }
                    $endHold($payload['previous_hold_reason'] ?? $result['hold'], $at);
                    $result['hold'] = $event['kind'] === 'hold.recorded' ? ($payload['hold_reason'] ?? null) : null;
                    $holdStart = $result['hold'] !== null ? $at : null;
                    break;
            }
        }

        return $result;
    }
}
