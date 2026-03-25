<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\RecurringMeetingSchedule;
use Carbon\Carbon;

class RecurringMeetingService
{
    public function generateUpcoming(int $monthsAhead = 3): int
    {
        $schedules = RecurringMeetingSchedule::where('is_active', true)->get();
        $created = 0;

        foreach ($schedules as $schedule) {
            $created += $this->generateForSchedule($schedule, $monthsAhead);
        }

        return $created;
    }

    public function generateForSchedule(RecurringMeetingSchedule $schedule, int $monthsAhead = 3): int
    {
        $dates = $this->calculateDates($schedule, $monthsAhead);
        $created = 0;

        foreach ($dates as $date) {
            $exists = GovernanceMeeting::where('recurring_schedule_id', $schedule->id)
                ->whereDate('scheduled_at', $date->toDateString())
                ->exists();

            if (!$exists) {
                GovernanceMeeting::create([
                    'meeting_type' => $schedule->meeting_type,
                    'board_committee_id' => $schedule->board_committee_id,
                    'title' => $this->generateTitle($schedule, $date),
                    'scheduled_at' => $date,
                    'duration_minutes' => $schedule->default_duration_minutes,
                    'location' => $schedule->default_location,
                    'virtual_link' => $schedule->default_virtual_link,
                    'status' => 'scheduled',
                    'quorum_required' => $schedule->quorum_percentage ?? 50,
                    'chair_id' => $schedule->default_chair_id,
                    'secretary_id' => $schedule->default_secretary_id,
                    'recurring_schedule_id' => $schedule->id,
                    'rsvp_deadline' => $date->copy()->subDays($schedule->rsvp_days_before ?? 7),
                    'preread_deadline' => $date->copy()->subDays($schedule->preread_days_before ?? 5),
                    'ceo_report_deadline' => $date->copy()->subDays($schedule->ceo_report_days_before ?? 3),
                    'created_by' => $schedule->created_by,
                ]);
                $created++;
            }
        }

        $schedule->update(['last_generated_at' => now()]);
        return $created;
    }

    protected function calculateDates(RecurringMeetingSchedule $schedule, int $monthsAhead): array
    {
        $dates = [];
        $now = now();
        $end = now()->addMonths($monthsAhead);

        $current = $now->copy()->startOfMonth();

        while ($current->lte($end)) {
            $meetingDate = $this->resolveDateForMonth($schedule, $current);

            if ($meetingDate && $meetingDate->gte($now) && $meetingDate->lte($end)) {
                $dates[] = $meetingDate;
            }

            $current->addMonth();
        }

        return $dates;
    }

    protected function resolveDateForMonth(RecurringMeetingSchedule $schedule, Carbon $monthStart): ?Carbon
    {
        $frequency = $schedule->frequency;
        $month = $monthStart->month;

        // Check if this month matches the frequency
        if ($frequency === 'monthly') {
            // Always generate
        } elseif ($frequency === 'bi_monthly') {
            if ($month % 2 !== ($schedule->start_month ?? 1) % 2) return null;
        } elseif ($frequency === 'quarterly') {
            $quarterMonths = match($schedule->start_month ?? 1) {
                1 => [1, 4, 7, 10],
                2 => [2, 5, 8, 11],
                3 => [3, 6, 9, 12],
                default => [1, 4, 7, 10],
            };
            if (!in_array($month, $quarterMonths)) return null;
        } elseif ($frequency === 'annual') {
            if ($month !== ($schedule->start_month ?? 1)) return null;
        }

        // Calculate specific day
        $dayOfWeek = $schedule->day_of_week; // 0=Sunday, 1=Monday, ...
        $weekOfMonth = $schedule->week_of_month; // 1=first, 2=second, 3=third, 4=fourth, -1=last

        $date = $monthStart->copy();

        if ($weekOfMonth === -1) {
            // Last occurrence of day in month
            $date = $date->endOfMonth();
            while ($date->dayOfWeek !== $dayOfWeek) {
                $date->subDay();
            }
        } else {
            // First day of the month
            $date = $date->startOfMonth();
            // Find first occurrence of the day
            while ($date->dayOfWeek !== $dayOfWeek) {
                $date->addDay();
            }
            // Move to correct week
            $date->addWeeks($weekOfMonth - 1);
        }

        // Set the time
        $time = $schedule->default_time ?? '10:00';
        [$hour, $minute] = explode(':', $time);
        $date->setTime((int)$hour, (int)$minute);

        // Ensure date is still in the correct month
        if ($date->month !== $monthStart->month) {
            return null;
        }

        return $date;
    }

    protected function generateTitle(RecurringMeetingSchedule $schedule, Carbon $date): string
    {
        $template = $schedule->title_template ?? '{type} Meeting - {month} {year}';

        $typeLabels = [
            'full_board' => 'Full Board',
            'audit_risk' => 'Audit & Risk Committee',
            'people' => 'People Committee',
            'finance' => 'Finance Committee',
            'special_general' => 'Special General',
            'executive_session' => 'Executive Session',
        ];

        return str_replace(
            ['{type}', '{month}', '{year}', '{date}'],
            [
                $typeLabels[$schedule->meeting_type] ?? $schedule->meeting_type,
                $date->format('F'),
                $date->format('Y'),
                $date->format('d M Y'),
            ],
            $template
        );
    }
}
