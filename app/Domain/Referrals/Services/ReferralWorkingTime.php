<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

final class ReferralWorkingTime
{
    public function __construct(private readonly ReferralWorkQueue $work) {}

    /** Original intake provenance is immutable, unlike a later explicit settings migration. */
    public function calendars(Builder $query): array
    {
        $versions = [];
        $events = ReferralEvent::query()->whereIn('id', ReferralEvent::query()
            ->whereIn('referral_id', (clone $query)->select('id'))->whereIn('kind', ['referral.received', 'support.preparation_opened'])
            ->selectRaw('MIN(id)')->groupBy('referral_id'));
        foreach ($events->select(['referral_id', 'payload'])->cursor() as $event) {
            $versions[$event->referral_id] = $event->payload['settings_version_id'] ?? null;
        }
        $settings = ReferralSettingsVersion::query()->whereIn('id', array_filter(array_unique($versions)))
            ->where('state', 'published')->get()
            ->mapWithKeys(fn ($version) => [$version->id => $version->configuration['business_calendar'] ?? null]);
        $result = [];
        foreach ($versions as $id => $version) {
            $result[$id] = $settings->get($version);
        }

        return $result;
    }

    /** Actual seconds within local office windows; never invent a missing opening time. */
    public static function seconds(DateTimeInterface $from, DateTimeInterface $to, ?array $calendar): ?float
    {
        if (! $calendar || ! in_array($calendar['timezone'] ?? '', ['Pacific/Auckland', 'Pacific/Chatham'], true)
            || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $calendar['opening_time'] ?? '')
            || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $calendar['closing_time'] ?? '')
            || $calendar['opening_time'] >= $calendar['closing_time']
            || ! is_array($calendar['weekdays'] ?? null) || ! $calendar['weekdays'] || array_diff($calendar['weekdays'], range(1, 7))
            || ! is_array($calendar['holidays'] ?? null)) {
            return null;
        }
        $start = CarbonImmutable::instance($from)->timezone($calendar['timezone']);
        $weekdays = array_values(array_unique(array_map('intval', $calendar['weekdays'])));
        $end = CarbonImmutable::instance($to)->timezone($calendar['timezone']);
        if ($end->lt($start)) {
            return null;
        }
        $firstDay = $start->startOfDay();
        $lastDay = $end->startOfDay();
        if ($firstDay->equalTo($lastDay)) {
            return self::daySeconds($firstDay, $start, $end, $calendar, $weekdays);
        }
        $first = self::daySeconds($firstDay, $start, $end, $calendar, $weekdays);
        $last = self::daySeconds($lastDay, $start, $end, $calendar, $weekdays);
        if ($first === null || $last === null) {
            return null;
        }

        // Count whole local weeks arithmetically; only holidays and offset-change days need individual work.
        $middle = $firstDay->addDay();
        $days = (int) CarbonImmutable::parse($middle->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($lastDay->toDateString(), 'UTC'));
        $workingDays = intdiv($days, 7) * count($weekdays);
        for ($i = 0; $i < $days % 7; $i++) {
            if (in_array(($middle->isoWeekday() - 1 + $i) % 7 + 1, $weekdays, true)) {
                $workingDays++;
            }
        }
        foreach (array_unique($calendar['holidays']) as $holiday) {
            if ($holiday >= $middle->toDateString() && $holiday < $lastDay->toDateString()
                && in_array(CarbonImmutable::parse($holiday, $calendar['timezone'])->isoWeekday(), $weekdays, true)) {
                $workingDays--;
            }
        }
        $minutes = fn ($time) => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
        $nominal = ($minutes($calendar['closing_time']) - $minutes($calendar['opening_time'])) * 60;
        $seconds = $first + $last + $workingDays * $nominal;
        if ($days > 0) {
            $changedDays = [];
            foreach (array_slice($middle->getTimezone()->getTransitions($middle->timestamp, $lastDay->timestamp), 1) as $transition) {
                $day = CarbonImmutable::createFromTimestampUTC($transition['ts'])->timezone($calendar['timezone'])->startOfDay();
                if (isset($changedDays[$day->toDateString()]) || ! in_array($day->isoWeekday(), $weekdays, true) || in_array($day->toDateString(), $calendar['holidays'], true)) {
                    continue;
                }
                $actual = self::daySeconds($day, $start, $end, $calendar, $weekdays);
                if ($actual === null) {
                    return null;
                }
                $seconds += $actual - $nominal;
                $changedDays[$day->toDateString()] = true;
            }
        }

        return (float) $seconds;
    }

    private static function daySeconds(CarbonImmutable $day, CarbonImmutable $start, CarbonImmutable $end, array $calendar, array $weekdays): ?float
    {
        if ($end->lte($day) || ! in_array($day->isoWeekday(), $weekdays, true) || in_array($day->toDateString(), $calendar['holidays'], true)) {
            return 0.0;
        }
        $open = $day->setTimeFromTimeString($calendar['opening_time']);
        $close = $day->setTimeFromTimeString($calendar['closing_time']);
        // An office boundary in a skipped DST hour is ambiguous; do not normalize it silently.
        if ($open->format('H:i') !== $calendar['opening_time'] || $close->format('H:i') !== $calendar['closing_time']) {
            return null;
        }
        $transitions = $day->getTimezone()->getTransitions($day->timestamp, $day->addDay()->timestamp);
        foreach (array_slice($transitions, 1) as $index => $transition) {
            $previousOffset = $transitions[$index]['offset'];
            if ($transition['offset'] >= $previousOffset) {
                continue;
            }
            $repeatedFrom = gmdate('H:i', $transition['ts'] + $transition['offset']);
            $repeatedTo = gmdate('H:i', $transition['ts'] + $previousOffset);
            foreach ([$calendar['opening_time'], $calendar['closing_time']] as $boundary) {
                if ($boundary >= $repeatedFrom && $boundary < $repeatedTo) {
                    return null;
                }
            }
        }
        $left = $start->max($open);
        $right = $end->min($close);

        return $right->gt($left) ? (float) $left->diffInSeconds($right) : 0.0;
    }

    public function report(User $actor, Builder $query, CarbonImmutable $cutoff, ?array $calendars = null): array
    {
        $calendars ??= $this->calendars($query);
        $holdStarts = ReferralHoldHistory::starts($query, $cutoff);
        $overdue = $this->work->work($actor)->whereIn('referral_id', (clone $query)->select('id'))
            ->where('status', 'open')->where('due_at', '<', $cutoff)->select('referral_id')->selectRaw('MIN(due_at) AS earliest')->groupBy('referral_id')
            ->pluck('earliest', 'referral_id');
        $rows = [];
        foreach (['attempt' => 'First human attempt', 'contact' => 'Successful human contact', 'stage' => 'Current open stage', 'hold' => 'Current hold', 'unassigned' => 'Open, unassigned intake', 'overdue' => 'Oldest overdue action per referral'] as $key => $label) {
            $rows[$key] = ['key' => $key, 'label' => $label, 'elapsed' => ['hours' => 0, 'count' => 0], 'working' => ['hours' => 0, 'count' => 0], 'unknown_dates' => 0, 'unknown_calendar' => 0];
        }
        $intervals = [];
        $add = function ($key, $from, $to, $calendar, $minimum = null) use (&$rows, &$intervals, $cutoff) {
            if (! $from || ! $to || $from->gt($to) || $to->gt($cutoff) || ($minimum && $from->lt($minimum))) {
                $rows[$key]['unknown_dates']++;

                return;
            }
            $rows[$key]['elapsed']['hours'] += $from->diffInSeconds($to) / 3600;
            $rows[$key]['elapsed']['count']++;
            // Reuse identical intervals only within this referral and its original calendar.
            $interval = $from->format('U.u').':'.$to->format('U.u');
            if (! array_key_exists($interval, $intervals)) {
                $intervals[$interval] = self::seconds($from, $to, $calendar);
            }
            $seconds = $intervals[$interval];
            if ($seconds === null) {
                $rows[$key]['unknown_calendar']++;
            } else {
                $rows[$key]['working']['hours'] += $seconds / 3600;
                $rows[$key]['working']['count']++;
            }
        };
        foreach ((clone $query)->select(['id', 'stage', 'hold_reason', 'owner_id', 'created_at', 'stage_changed_at', 'next_due_at', 'first_contact_attempt_at', 'first_human_contact_at'])->cursor() as $record) {
            $intervals = [];
            $calendar = $calendars[$record->id] ?? null;
            $created = $record->created_at;
            $nextDue = $record->next_due_at;
            foreach (['attempt' => 'first_contact_attempt_at', 'contact' => 'first_human_contact_at'] as $key => $column) {
                // No recorded contact is not a zero-hour contact.
                if ($contact = $record->$column) {
                    $add($key, $created, $contact, $calendar);
                }
            }
            if (! in_array($record->stage, ['closed', 'converted'], true)) {
                $add('stage', $record->stage_changed_at, $cutoff, $calendar, $created);
                if ($record->hold_reason !== null) {
                    $add('hold', $holdStarts[$record->id] ?? null, $cutoff, $calendar, $created);
                }
                if (! $record->owner_id) {
                    $add('unassigned', $created, $cutoff, $calendar);
                }
            }
            if ($record->stage !== 'closed') {
                $due = $overdue->get($record->id) ? CarbonImmutable::parse($overdue->get($record->id), 'UTC') : null;
                if ($nextDue && $nextDue->lt($cutoff)) {
                    $due = $due ? $due->min($nextDue) : $nextDue;
                }
                if ($due) {
                    $add('overdue', $due, $cutoff, $calendar);
                }
            }
        }
        foreach ($rows as &$row) {
            foreach (['elapsed', 'working'] as $type) {
                $row[$type]['mean_hours'] = $row[$type]['count'] ? round($row[$type]['hours'] / $row[$type]['count'], 1) : null;
                unset($row[$type]['hours']);
            }
        }

        return array_values($rows);
    }
}
