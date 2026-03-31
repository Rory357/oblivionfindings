<?php

namespace App\Models;

use App\Services\DoseSchedulingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationRoundTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_context_id',
        'site_id',
        'name',
        'scheduled_time',
        'window_minutes',
        'days_of_week',
        'active',
        'default_assigned_to',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'active' => 'boolean',
        'window_minutes' => 'integer',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function defaultAssignedTo()
    {
        return $this->belongsTo(User::class, 'default_assigned_to');
    }

    public function rounds()
    {
        return $this->hasMany(MedicationRound::class, 'round_template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function appliesToDay(int $dayOfWeek): bool
    {
        if (empty($this->days_of_week)) return true;
        return in_array($dayOfWeek, $this->days_of_week);
    }

    public function applicableMedicationCountForDate(Carbon $date): int
    {
        $windowMinutes = max(0, (int) ($this->window_minutes ?? 60));
        $roundTime = Carbon::parse($date->toDateString() . ' ' . $this->scheduled_time);
        $windowStart = $roundTime->copy()->subMinutes($windowMinutes);
        $windowEnd = $roundTime->copy()->addMinutes($windowMinutes);

        return ClientMedication::query()
            ->active()
            ->where(function ($query) {
                $query->where('is_prn', false)->orWhereNull('is_prn');
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date);
            })
            ->whereHas('client', function ($query) {
                if ($this->site_id) {
                    $query->where('site_id', $this->site_id);
                }

                if ($this->service_context_id) {
                    $query->where('service_context_id', $this->service_context_id);
                }
            })
            ->get()
            ->filter(function (ClientMedication $medication) use ($date, $windowStart, $windowEnd) {
                $doseTimes = $medication->dose_times;

                if (empty($doseTimes) && !empty($medication->frequency)) {
                    $doseTimes = DoseSchedulingService::calculateDoseTimes($medication->frequency);
                }

                return collect($doseTimes ?? [])->contains(function ($time) use ($date, $windowStart, $windowEnd) {
                    try {
                        $scheduledTime = Carbon::parse($date->toDateString() . ' ' . $time);
                    } catch (\Throwable) {
                        return false;
                    }

                    return $scheduledTime->between($windowStart, $windowEnd, true);
                });
            })
            ->count();
    }
}
