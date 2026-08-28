<?php

namespace App\Models;

use App\Services\DoseSchedulingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class MedicationRoundTemplate extends Model
{
    use HasFactory;

    private const RETIREMENT_FIELDS = [
        'retired_at',
        'retired_by_user_id',
    ];

    private static int $governedRetirementDepth = 0;

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
        'retired_at' => 'immutable_datetime',
        'window_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $template): void {
            if ($template->retired_at !== null || $template->retired_by_user_id !== null) {
                throw new UnexpectedValueException('New medication round templates cannot begin as retired evidence.');
            }
        });
        self::deleting(function (): never {
            throw new UnexpectedValueException(
                'Medication round templates are retained as historical medication-round evidence.',
            );
        });
        self::updating(function (self $template): void {
            if (self::$governedRetirementDepth > 0) {
                return;
            }

            $dirty = array_keys($template->getDirty());
            if (array_intersect($dirty, self::RETIREMENT_FIELDS) !== []) {
                throw new UnexpectedValueException(
                    'Medication round-template retirement evidence can only change through the governed retirement transition.',
                );
            }
            if ($template->getRawOriginal('retired_at') !== null
                && array_diff($dirty, ['updated_at']) !== []) {
                throw new UnexpectedValueException('Retired medication round-template evidence is immutable.');
            }
        });
    }

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

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by_user_id');
    }

    public function rounds()
    {
        return $this->hasMany(MedicationRound::class, 'round_template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->whereNull('retired_at');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function retireGoverned(int $actorId): bool
    {
        if ($this->isRetired()) {
            return false;
        }
        if ($actorId <= 0) {
            throw new UnexpectedValueException('A valid actor is required to retire a medication round template.');
        }

        self::$governedRetirementDepth++;
        try {
            $this->forceFill([
                'active' => false,
                'retired_at' => now(),
                'retired_by_user_id' => $actorId,
            ])->save();
        } finally {
            self::$governedRetirementDepth--;
        }

        return true;
    }

    public function appliesToDay(int $dayOfWeek): bool
    {
        if (empty($this->days_of_week)) {
            return true;
        }

        return in_array($dayOfWeek, $this->days_of_week);
    }

    public function applicableMedicationCountForDate(Carbon $date): int
    {
        $windowMinutes = max(0, (int) ($this->window_minutes ?? 60));
        $roundTime = Carbon::parse($date->toDateString().' '.$this->scheduled_time);
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

                if (empty($doseTimes) && ! empty($medication->frequency)) {
                    $doseTimes = DoseSchedulingService::calculateDoseTimes($medication->frequency);
                }

                return collect($doseTimes ?? [])->contains(function ($time) use ($date, $windowStart, $windowEnd) {
                    try {
                        $scheduledTime = Carbon::parse($date->toDateString().' '.$time);
                    } catch (\Throwable) {
                        return false;
                    }

                    return $scheduledTime->between($windowStart, $windowEnd, true);
                });
            })
            ->count();
    }
}
