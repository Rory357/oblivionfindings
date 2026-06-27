<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrCalendarEventReminder;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Dispatch HR calendar event reminders. Runs every minute: for each reminder it
 * finds an event/occurrence whose start minus the offset falls in the last
 * minute, then notifies the invited people (or the creator if nobody was
 * invited). `last_sent_at` de-duplicates so a reminder fires once per occurrence.
 */
class DispatchCalendarReminders extends Command
{
    protected $signature = 'hr:dispatch-calendar-reminders';

    protected $description = 'Send due reminders for HR calendar events to their invitees.';

    public function handle(NotificationService $notifications): int
    {
        $now = now();
        $windowStart = $now->copy()->subMinute();
        $sent = 0;

        $reminders = HrCalendarEventReminder::query()
            ->with(['event.attendees' => fn ($q) => $q->where('audience_type', 'person')])
            ->get();

        foreach ($reminders as $reminder) {
            $event = $reminder->event;
            if (! $event || ! $event->starts_at) {
                continue;
            }

            // An occurrence is due when (start - offset) ∈ (windowStart, now],
            // i.e. start ∈ (windowStart + offset, now + offset].
            $offset = (int) $reminder->offset_minutes;
            $from = $windowStart->copy()->addMinutes($offset);
            $to = $now->copy()->addMinutes($offset);

            $occStart = $this->firstOccurrenceStartBetween($event->starts_at, $event->rrule, $event->recurrence_until, $from, $to);
            if (! $occStart) {
                continue;
            }

            $triggerAt = $occStart->copy()->subMinutes($offset);
            if ($reminder->last_sent_at && $reminder->last_sent_at->equalTo($triggerAt)) {
                continue;
            }

            $userIds = $event->attendees->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
            if ($userIds === [] && $event->created_by) {
                $userIds = [(int) $event->created_by];
            }
            if ($userIds === []) {
                $reminder->update(['last_sent_at' => $triggerAt]);
                continue;
            }

            $notifications->notifyCrud(
                null,
                'reminder',
                'calendar_event',
                $event,
                null,
                [
                    'event_key' => 'hr.calendar.reminder',
                    'title' => 'Reminder: '.$event->title,
                    'body' => $event->title.' starts '.$occStart->diffForHumans(),
                    'url' => url('/hr/calendar'),
                    'target_user_ids' => $userIds,
                ],
            );

            $reminder->update(['last_sent_at' => $triggerAt]);
            $sent++;
        }

        if ($sent > 0) {
            Log::info('hr.calendar_reminders_dispatched', ['count' => $sent]);
        }

        $this->info("Dispatched {$sent} calendar reminder(s).");

        return self::SUCCESS;
    }

    /**
     * The first occurrence start of an event within [$from, $to]. Mirrors the
     * small RFC-5545 subset the aggregator expands (FREQ=DAILY|WEEKLY|MONTHLY,
     * INTERVAL, COUNT; UNTIL via recurrence_until).
     */
    private function firstOccurrenceStartBetween(Carbon $seriesStart, ?string $rrule, ?Carbon $until, Carbon $from, Carbon $to): ?Carbon
    {
        if (! $rrule) {
            return $seriesStart->betweenIncluded($from, $to) ? $seriesStart->copy() : null;
        }

        $rule = $this->parseRrule($rrule);
        if (! $rule) {
            return null;
        }

        $hardEnd = $until ? $to->copy()->min($until) : $to->copy();
        $cursor = $seriesStart->copy();
        $index = 0;
        while ($cursor->lte($hardEnd) && $index < 1000) {
            $index++;
            if ($rule['count'] !== null && $index > $rule['count']) {
                break;
            }
            if ($cursor->betweenIncluded($from, $to)) {
                return $cursor->copy();
            }
            match ($rule['freq']) {
                'DAILY' => $cursor->addDays($rule['interval']),
                'WEEKLY' => $cursor->addWeeks($rule['interval']),
                'MONTHLY' => $cursor->addMonthsNoOverflow($rule['interval']),
                default => $cursor->addYears(1000),
            };
        }

        return null;
    }

    /** @return array{freq: string, interval: int, count: int|null}|null */
    private function parseRrule(string $rrule): ?array
    {
        $parts = [];
        foreach (explode(';', $rrule) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            $parts[strtoupper(trim((string) $k))] = trim((string) $v);
        }
        $freq = $parts['FREQ'] ?? null;
        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY'], true)) {
            return null;
        }

        return [
            'freq' => $freq,
            'interval' => max(1, (int) ($parts['INTERVAL'] ?? 1)),
            'count' => isset($parts['COUNT']) ? max(1, (int) $parts['COUNT']) : null,
        ];
    }
}
