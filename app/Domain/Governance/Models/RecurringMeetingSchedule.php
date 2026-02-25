<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringMeetingSchedule extends Model
{
    protected $fillable = [
        'meeting_type', 'board_committee_id', 'title_template', 'frequency',
        'day_of_month', 'time', 'duration_minutes', 'location', 'virtual_link',
        'default_chair_id', 'default_secretary_id', 'quorum_required',
        'preread_days_before', 'rsvp_days_before', 'is_active',
        'start_date', 'end_date', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function meetings(): HasMany
    {
        return $this->hasMany(GovernanceMeeting::class, 'recurring_schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function generateTitle(Carbon $date): string
    {
        return str_replace(
            ['{month}', '{year}', '{date}'],
            [$date->format('F'), $date->format('Y'), $date->format('j M Y')],
            $this->title_template
        );
    }

    public function getNextDates(int $count = 6): array
    {
        $dates = [];
        $current = now()->startOfMonth();

        for ($i = 0; $i < 12 && count($dates) < $count; $i++) {
            $date = $current->copy()->addMonths($i);

            $shouldInclude = match ($this->frequency) {
                'monthly' => true,
                'bimonthly' => $date->month % 2 === ($current->month % 2),
                'quarterly' => in_array($date->month, [1, 4, 7, 10]) || in_array($date->month, [2, 5, 8, 11]) || in_array($date->month, [3, 6, 9, 12]),
                default => true,
            };

            if ($shouldInclude) {
                $meetingDate = $date->copy()->day(min($this->day_of_month, $date->daysInMonth));
                if ($meetingDate->isFuture() && (!$this->end_date || $meetingDate->lte($this->end_date))) {
                    $dates[] = $meetingDate;
                }
            }
        }

        return $dates;
    }

    public function createMeetingForDate(Carbon $date): GovernanceMeeting
    {
        $scheduledAt = $date->copy()->setTimeFromTimeString($this->time);

        return GovernanceMeeting::create([
            'meeting_type' => $this->meeting_type,
            'board_committee_id' => $this->board_committee_id,
            'title' => $this->generateTitle($date),
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => $this->duration_minutes,
            'location' => $this->location,
            'virtual_link' => $this->virtual_link,
            'chair_id' => $this->default_chair_id,
            'secretary_id' => $this->default_secretary_id,
            'quorum_required' => $this->quorum_required,
            'recurring_schedule_id' => $this->id,
            'rsvp_deadline' => $scheduledAt->copy()->subDays($this->rsvp_days_before)->toDateString(),
            'preread_deadline' => $scheduledAt->copy()->subDays($this->preread_days_before)->toDateString(),
            'ceo_report_deadline' => $scheduledAt->copy()->subDays($this->preread_days_before + 3)->toDateString(),
            'created_by' => $this->created_by,
        ]);
    }
}
