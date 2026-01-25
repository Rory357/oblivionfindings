<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ClientMedication;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MarScheduleService
{
    public function windowBeforeMinutes(): int
    {
        return (int) (AppSetting::query()->where('key', 'medications.mar.window_before_minutes')->value('value')
            ?? config('medications.mar.window_before_minutes', 30));
    }

    public function windowAfterMinutes(): int
    {
        return (int) (AppSetting::query()->where('key', 'medications.mar.window_after_minutes')->value('value')
            ?? config('medications.mar.window_after_minutes', 60));
    }

    public function dueSoonMinutes(): int
    {
        return (int) (AppSetting::query()->where('key', 'medications.mar.due_soon_minutes')->value('value')
            ?? config('medications.mar.due_soon_minutes', 60));
    }

    /**
     * Build scheduled dose times for a medication on a given date.
     *
     * We intentionally keep this heuristic-based because the app currently stores frequency as a free-text field.
     * Supported inputs:
     * - "08:00" / "8:00" / "08:00, 20:00"
     * - "8am" / "8 pm" / "8am, 12pm, 6pm"
     * - keywords: morning, noon, afternoon, evening, night
     */
    public function scheduledTimesForDate(ClientMedication $medication, Carbon $date): array
    {
        if (!$medication->active) {
            return [];
        }

        // PRN meds have no fixed schedule.
        if ($medication->is_prn) {
            return [];
        }

        // If medication has start/end date constraints.
        if ($medication->start_date && $date->toDateString() < $medication->start_date->toDateString()) {
            return [];
        }
        if ($medication->end_date && $date->toDateString() > $medication->end_date->toDateString()) {
            return [];
        }

        $freq = trim((string) ($medication->frequency ?? ''));
        if ($freq === '') {
            // No frequency specified; treat as unscheduled (won't appear in Daily MAR).
            return [];
        }

        $times = [];

        // 24h times: 8:00 or 08:00
        if (preg_match_all('/\b([01]?\d|2[0-3])\s*[:.]\s*([0-5]\d)\b/', $freq, $m)) {
            foreach ($m[1] as $i => $h) {
                $hh = str_pad((string) ((int) $h), 2, '0', STR_PAD_LEFT);
                $mm = str_pad((string) ((int) $m[2][$i]), 2, '0', STR_PAD_LEFT);
                $times[] = "$hh:$mm";
            }
        }

        // 12h times: 8am / 8 pm / 8:30am
        if (preg_match_all('/\b(1[0-2]|0?\d)(?:\s*[:.]\s*([0-5]\d))?\s*(am|pm)\b/i', $freq, $m2)) {
            foreach ($m2[1] as $i => $h) {
                $hour = (int) $h;
                $min = isset($m2[2][$i]) && $m2[2][$i] !== '' ? (int) $m2[2][$i] : 0;
                $ampm = strtolower($m2[3][$i]);
                if ($ampm === 'pm' && $hour < 12) {
                    $hour += 12;
                }
                if ($ampm === 'am' && $hour === 12) {
                    $hour = 0;
                }
                $times[] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $min, 2, '0', STR_PAD_LEFT);
            }
        }

        // Keywords
        $lower = Str::lower($freq);
        $keywordMap = [
            'morning' => '08:00',
            'noon' => '12:00',
            'midday' => '12:00',
            'afternoon' => '15:00',
            'evening' => '18:00',
            'night' => '21:00',
            'bedtime' => '21:00',
        ];
        foreach ($keywordMap as $key => $time) {
            if (Str::contains($lower, $key)) {
                $times[] = $time;
            }
        }

        $times = collect($times)
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($times);

        return array_map(fn ($t) => $date->copy()->setTimeFromTimeString($t), $times);
    }

    public function windowForScheduled(Carbon $scheduled): array
    {
        $start = $scheduled->copy()->subMinutes($this->windowBeforeMinutes());
        $end = $scheduled->copy()->addMinutes($this->windowAfterMinutes());
        return [$start, $end];
    }

    public function statusForDose(?Carbon $now, Carbon $scheduled, ?array $administration): array
    {
        $now = $now ? $now->copy() : now();
        [$wStart, $wEnd] = $this->windowForScheduled($scheduled);

        // Already recorded
        if ($administration) {
            $adminAt = isset($administration['administered_at']) && $administration['administered_at']
                ? Carbon::parse($administration['administered_at'])
                : null;
            $lateMinutes = null;
            if ($adminAt) {
                $diff = $scheduled->diffInMinutes($adminAt, false);
                $lateMinutes = $diff > 0 ? $diff : 0;
            }

            return [
                'state' => 'completed',
                'window_start' => $wStart,
                'window_end' => $wEnd,
                'late_minutes' => $lateMinutes,
            ];
        }

        // Not recorded yet
        if ($now->lessThan($wStart)) {
            $mins = $now->diffInMinutes($scheduled, false);
            $state = ($mins <= $this->dueSoonMinutes()) ? 'due_soon' : 'upcoming';
            return [
                'state' => $state,
                'window_start' => $wStart,
                'window_end' => $wEnd,
                'late_minutes' => null,
            ];
        }

        if ($now->betweenIncluded($wStart, $wEnd)) {
            return [
                'state' => 'due',
                'window_start' => $wStart,
                'window_end' => $wEnd,
                'late_minutes' => null,
            ];
        }

        return [
            'state' => 'late',
            'window_start' => $wStart,
            'window_end' => $wEnd,
            'late_minutes' => $wEnd->diffInMinutes($now),
        ];
    }
}
